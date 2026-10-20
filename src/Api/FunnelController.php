<?php

namespace ErnestDefoe\Onramp\Api;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Journeys;
use ErnestDefoe\Onramp\Step;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/onramp-admin/funnel?weeks=8 — where newcomers stop. Admins only.
 *
 * For the journeys started in the window: how many reached each step, how
 * long the median member took to get there, which step most of the
 * unfinished ones are stuck on, and the step where the most people drop off.
 * Plus the same per weekly cohort, so a change to the journey shows up as a
 * change between one week's newcomers and the next.
 *
 * 🚨 Read as two streams (journeys, then their progress rows) and folded in
 * PHP rather than grouped in SQL: week bucketing and medians are different
 * SQL on each of MySQL, PostgreSQL and SQLite, and this is an admin page read
 * a few times a week — a cursor over a few thousand rows is the portable,
 * flat-memory answer.
 */
class FunnelController implements RequestHandlerInterface
{
    public function __construct(private Journeys $journeys, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $weeks = max(1, min(26, (int) ($request->getQueryParams()['weeks'] ?? 8)));
        $since = Carbon::now()->startOfWeek()->subWeeks($weeks - 1);
        $steps = $this->journeys->steps();
        $ids = $steps->pluck('id')->all();

        $cohorts = [];
        for ($w = 0; $w < $weeks; $w++) {
            $start = $since->copy()->addWeeks($w)->toDateString();
            $cohorts[$start] = ['start' => $start, 'started' => 0, 'graduated' => 0, 'steps' => array_fill_keys($ids, 0)];
        }

        $journeys = [];
        $totals = ['started' => 0, 'graduated' => 0, 'closed' => 0, 'open' => 0];

        foreach ($this->db->table('onramp_journeys')->where('started_at', '>=', $since)->select('user_id', 'started_at', 'completed_at', 'closed_at')->cursor() as $j) {
            $started = Carbon::parse($j->started_at);
            $week = $started->copy()->startOfWeek()->toDateString();
            $journeys[(int) $j->user_id] = ['week' => $week, 'at' => $started, 'done' => []];

            $totals['started']++;
            $cohorts[$week]['started'] = ($cohorts[$week]['started'] ?? 0) + 1;

            if ($j->completed_at) {
                $totals['graduated']++;
                $cohorts[$week]['graduated']++;
            } elseif ($j->closed_at) {
                $totals['closed']++;
            } else {
                $totals['open']++;
            }
        }

        $hours = array_fill_keys($ids, []);
        $reached = array_fill_keys($ids, 0);

        if ($journeys && $ids) {
            $rows = $this->db->table('onramp_progress')
                ->join('onramp_journeys', 'onramp_journeys.user_id', '=', 'onramp_progress.user_id')
                ->where('onramp_journeys.started_at', '>=', $since)
                ->whereIn('onramp_progress.step_id', $ids)
                ->whereNotNull('onramp_progress.completed_at')
                ->select('onramp_progress.user_id', 'onramp_progress.step_id', 'onramp_progress.completed_at')
                ->cursor();

            foreach ($rows as $row) {
                $uid = (int) $row->user_id;

                if (! isset($journeys[$uid])) {
                    continue;
                }

                $step = (int) $row->step_id;
                $journeys[$uid]['done'][$step] = true;
                $reached[$step]++;
                $cohorts[$journeys[$uid]['week']]['steps'][$step]++;
                $hours[$step][] = max(0, $journeys[$uid]['at']->diffInMinutes(Carbon::parse($row->completed_at))) / 60;
            }
        }

        // Where the unfinished stop: their first step not yet done.
        $stuck = array_fill_keys($ids, 0);
        foreach ($journeys as $j) {
            foreach ($ids as $id) {
                if (! isset($j['done'][$id])) {
                    $stuck[$id]++;
                    break;
                }
            }
        }

        $rows = [];
        $bottleneck = null;
        $worstDrop = 0.0;
        $previous = 1.0;

        foreach ($steps as $step) {
            /** @var Step $step */
            $rate = $totals['started'] ? $reached[$step->id] / $totals['started'] : 0.0;
            $drop = $previous - $rate;

            // Only a real drop, and only with enough journeys to mean something.
            if ($totals['started'] >= 5 && $drop > $worstDrop + 0.0001) {
                $worstDrop = $drop;
                $bottleneck = $step->id;
            }

            $previous = $rate;
            $rows[] = [
                'id' => $step->id,
                'type' => $step->type,
                'title' => $step->title,
                // For the type's own wording: "Read 3 discussions".
                'target' => max(1, (int) ($step->settings()['count'] ?? 1)),
                'reached' => $reached[$step->id],
                'rate' => round($rate, 4),
                'medianHours' => $this->median($hours[$step->id]),
                'stuck' => $stuck[$step->id],
            ];
        }

        return new JsonResponse([
            'data' => [
                'weeks' => $weeks,
                'totals' => $totals,
                'steps' => $rows,
                'cohorts' => array_values($cohorts),
                'bottleneck' => $worstDrop >= 0.15 ? $bottleneck : null,
            ],
        ]);
    }

    /** @param list<float> $values */
    private function median(array $values): ?float
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
