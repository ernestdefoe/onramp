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
            $mine = $this->tracker->flush();
        } finally {
            $this->tracker->end();
        }

        if (! $mine || ! str_contains($response->getHeaderLine('Content-Type'), 'json')) {
            return $response;
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || array_is_list($body)) {
            return $response;
        }

        $state = $this->presenter->summary($actor);

        $body['meta'] = (array) ($body['meta'] ?? []);
        $body['meta']['onramp'] = [
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
            if (! isset($body[$member]) || ! is_array($body[$member])) {
                continue;
            }

            $resources = array_is_list($body[$member]) ? $body[$member] : [$body[$member]];

            foreach ($resources as $i => $resource) {
                if (($resource['type'] ?? null) === 'users' && (string) ($resource['id'] ?? '') === (string) $actor->id
                    && is_array($resource['attributes'] ?? null) && array_key_exists('onramp', $resource['attributes'])) {
                    $resources[$i]['attributes']['onramp'] = $state;
                }
            }

            $body[$member] = array_is_list($body[$member]) ? $resources : $resources[0];
        }

        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response
            ->withBody((new StreamFactory())->createStream($json))
            ->withHeader('Content-Length', (string) strlen($json));
    }
}
