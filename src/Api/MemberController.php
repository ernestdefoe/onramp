<?php

namespace ErnestDefoe\Onramp\Api;

use ErnestDefoe\Onramp\Journeys;
use ErnestDefoe\Onramp\Presenter;
use ErnestDefoe\Onramp\Step;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A member's journey, seen by staff.
 *
 *   GET  /api/onramp/members/{id}   moderators (onramp.viewMembers) and admins
 *   POST /api/onramp/members/{id}   admins: {action: start|reset|close|reopen|complete, step?}
 *
 * `complete` is the helping hand — tick a step for a member who did it in a
 * way Onramp could not see — and pays the step's rewards like any other.
 */
class MemberController implements RequestHandlerInterface
{
    private const ACTIONS = ['start', 'reset', 'close', 'reopen', 'complete'];

    public function __construct(private Journeys $journeys, private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // Permission first: checked after the lookup, a 403 for a real id and
        // a 404 for a missing one told anybody which ids exist.
        $request->getMethod() === 'POST' ? $actor->assertAdmin() : $actor->assertCan('onramp.viewMembers');

        $user = User::query()->findOrFail((int) ($request->getQueryParams()['id'] ?? 0));

        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $action = (string) ($body['action'] ?? '');

            if (! in_array($action, self::ACTIONS, true)) {
                return new JsonResponse(['errors' => [['status' => '422', 'code' => 'onramp_no_action']]], 422);
            }

            match ($action) {
                'start' => $this->journeys->start($user, Journeys::SILENT),
                'reset' => $this->journeys->reset($user),
                'close' => $this->journeys->close($user),
                'reopen' => $this->journeys->reopen($user),
                'complete' => $this->complete($user, (int) ($body['step'] ?? 0)),
            };
        }

        return new JsonResponse(['data' => $this->presenter->full($user)]);
    }

    private function complete(User $user, int $stepId): void
    {
        $step = $this->journeys->steps()->firstWhere('id', $stepId);

        if ($step instanceof Step && $this->journeys->journey($user)?->isOpen()) {
            // AWAY: the member is not the one watching, so they get the alert.
            if ($this->journeys->complete($user, $step, Journeys::AWAY)) {
                $this->journeys->graduateIfDone($user, $this->journeys->steps(), Journeys::AWAY);
            }
        }
    }
}
