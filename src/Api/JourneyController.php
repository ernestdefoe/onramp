<?php

namespace ErnestDefoe\Onramp\Api;

use ErnestDefoe\Onramp\Journeys;
use ErnestDefoe\Onramp\Presenter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/onramp — the member's own journey, with live counts.
 *
 * Opening Onramp is also a full re-check of every pending step, which is the
 * safety net under the event listeners: whatever an event missed (a step
 * whose extension was switched on later, a streak fed by a visit), the member
 * sees it ticked the moment they look.
 */
class JourneyController implements RequestHandlerInterface
{
    public function __construct(private Journeys $journeys, private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $result = $this->journeys->evaluate($actor);

        return new JsonResponse([
            'data' => $this->presenter->full($actor),
            'meta' => ['completed' => array_map(fn ($s) => $s->id, $result['completed']), 'graduated' => $result['graduated']],
        ]);
    }
}
