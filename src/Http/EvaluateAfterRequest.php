<?php

namespace ErnestDefoe\Onramp\Http;

use ErnestDefoe\Onramp\Presenter;
use ErnestDefoe\Onramp\Tracker;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the request, then evaluates the steps it touched — and, when the acting
 * member completed something, tells their browser in the same response.
 *
 * 🚨 The response is only rewritten when the actor completed a step, which is
 * a handful of times in a member's life. Every other response passes through
 * untouched: no decode, no re-encode, no added bytes.
 *
 * The news rides in JSON:API's own `meta` member, which every client ignores
 * unless it asks for it, so nothing else that reads the response notices.
 */
class EvaluateAfterRequest implements MiddlewareInterface
{
    public function __construct(private Tracker $tracker, private Presenter $presenter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $this->tracker->begin($actor);

        try {
            $response = $handler->handle($request);
            // An inner API call (an extension using Flarum's own client
            // mid-request) leaves the flush to the request around it.
            $mine = $this->tracker->outermost() ? $this->tracker->flush() : null;
        } finally {
            $this->tracker->end();
        }

        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        $stream = $response->getBody();

        // Only a whole JSON document can be rewritten — never a stream.
        if (! $mine || ! in_array($type, ['application/vnd.api+json', 'application/json'], true) || ! $stream->isSeekable()) {
            return $response;
        }

        // 🚨 Objects, not arrays: decoded as arrays, an empty `{}` anywhere in
        // the document would come back out as `[]`.
        $body = json_decode((string) $stream);

        if (! $body instanceof \stdClass) {
            return $response;
        }

        $state = $this->presenter->summary($actor);

        $body->meta ??= new \stdClass();
        $body->meta->onramp = [
            'completed' => array_map(fn ($step) => $step->id, $mine['completed']),
            'graduated' => $mine['graduated'],
            'state' => $state,
        ];

        /*
         * 🚨 The member's own user may be IN this response — a like returns
         * the post with its likers, the actor among them — serialized before
         * the steps were evaluated, so with the old journey. The browser
         * applies that copy after reading the meta, and the ring would go
         * back to the old count. So every copy of the actor's own user here
         * gets the fresh journey too.
         */
        foreach (['data', 'included'] as $member) {
            $resources = $body->{$member} ?? null;

            foreach (is_array($resources) ? $resources : [$resources] as $resource) {
                if ($resource instanceof \stdClass && ($resource->type ?? null) === 'users' && (string) ($resource->id ?? '') === (string) $actor->id
                    && ($resource->attributes ?? null) instanceof \stdClass && property_exists($resource->attributes, 'onramp')) {
                    $resource->attributes->onramp = $state;
                }
            }
        }

        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response
            ->withBody((new StreamFactory())->createStream($json))
            ->withHeader('Content-Length', (string) strlen($json));
    }
}
