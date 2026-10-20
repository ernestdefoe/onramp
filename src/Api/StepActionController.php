<?php

namespace ErnestDefoe\Onramp\Api;

use ErnestDefoe\Onramp\Journeys;
use ErnestDefoe\Onramp\Presenter;
use ErnestDefoe\Onramp\Step;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The member's own buttons, all on their own journey only:
 *
 *   POST /api/onramp/skip    {step}   an optional step: "not for me"
 *   POST /api/onramp/unskip  {step}   take that back
 *   POST /api/onramp/visit   {path}   the browser reports a page visit
 *   POST /api/onramp/close            put Onramp away
 *   POST /api/onramp/reopen           bring it back
 */
class StepActionController implements RequestHandlerInterface
{
    public function __construct(private Journeys $journeys, private Presenter $presenter, private UrlGenerator $url)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $action = (string) ($request->getQueryParams()['action'] ?? '');
        $body = (array) $request->getParsedBody();
        $journey = $this->journeys->journey($actor);

        if (! $journey) {
            return $this->error(404, 'no_journey');
        }

        $result = ['completed' => [], 'graduated' => false];

        switch ($action) {
            case 'skip':
            case 'unskip':
                if (! $journey->isOpen()) {
                    return $this->error(409, 'not_open');
                }

                $step = $this->step($body['step'] ?? null);

                if (! $step) {
                    return $this->error(404, 'no_step');
                }

                if ($action === 'skip' && ! $this->journeys->skip($actor, $step)) {
                    return $this->error(422, 'required');
                }

                if ($action === 'unskip') {
                    $this->journeys->undoSkip($actor, $step);
                }

                break;

            case 'visit':
                $result = $this->visit($actor, (string) ($body['path'] ?? ''));
                break;

            case 'close':
                $this->journeys->close($actor);
                break;

            case 'reopen':
                $this->journeys->reopen($actor);
                break;

            default:
                return $this->error(404, 'no_action');
        }

        return new JsonResponse([
            'data' => $this->presenter->full($actor),
            'meta' => ['completed' => array_map(fn ($s) => $s->id, $result['completed']), 'graduated' => $result['graduated']],
        ]);
    }

    /**
     * Complete the pending visit steps whose page is this one.
     *
     * The browser only says where it is; which steps that completes is
     * decided here, against steps the admin wrote. A member can claim a visit
     * they did not make by calling this by hand — which completes a step
     * whose whole point was "go and look at this page", so there is nothing
     * gained by cheating it.
     *
     * @return array{completed: list<Step>, graduated: bool}
     */
    private function visit(User $actor, string $path): array
    {
        $result = ['completed' => [], 'graduated' => false];
        $journey = $this->journeys->journey($actor);

        if (! $journey?->isOpen() || $path === '') {
            return $result;
        }

        $done = $this->journeys->completedIds($actor);
        $steps = $this->journeys->steps();

        foreach ($steps as $step) {
            if ($step->type === 'visit' && ! in_array($step->id, $done, true) && $this->samePath((string) ($step->settings()['path'] ?? ''), $path)) {
                if ($this->journeys->complete($actor, $step)) {
                    $result['completed'][] = $step;
                }
            }
        }

        if ($result['completed']) {
            $result['graduated'] = $this->journeys->graduateIfDone($actor, $steps);
        }

        return $result;
    }

    /**
     * A step's page and the page the member is on are the same page: the
     * path part only, relative to the forum (a forum in a subfolder works
     * with either), without a trailing slash, and a discussion matches by its
     * id alone (`/d/12` and `/d/12-rules/3` are one discussion).
     */
    public function samePath(string $want, string $have): bool
    {
        $base = rtrim((string) parse_url($this->url->to('forum')->base(), PHP_URL_PATH), '/');

        $norm = function (string $p) use ($base): string {
            $p = '/'.trim((string) parse_url($p, PHP_URL_PATH), '/');

            if ($base !== '' && str_starts_with($p.'/', $base.'/')) {
                $p = '/'.trim(substr($p, strlen($base)), '/');
            }

            return preg_match('#^/d/(\d+)#', $p, $m) ? '/d/'.$m[1] : strtolower($p);
        };

        return $want !== '' && $norm($want) === $norm($have);
    }

    private function step(mixed $id): ?Step
    {
        $step = $this->journeys->steps()->firstWhere('id', (int) $id);

        return $step instanceof Step ? $step : null;
    }

    private function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'code' => 'onramp_'.$code]]], $status);
    }
}
