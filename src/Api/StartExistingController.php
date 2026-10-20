<?php

namespace ErnestDefoe\Onramp\Api;

use ErnestDefoe\Onramp\Starter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/onramp-admin/start-existing {days} — one batch of journeys for
 * recent members. The admin page calls it again until `remaining` is 0.
 */
class StartExistingController implements RequestHandlerInterface
{
    public function __construct(private Starter $starter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $days = (int) (((array) $request->getParsedBody())['days'] ?? 30);

        return new JsonResponse(['data' => $this->starter->batch($days)]);
    }
}
