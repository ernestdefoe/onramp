<?php

namespace ErnestDefoe\Onramp;

use Carbon\Carbon;
use Flarum\User\User;

/**
 * Journeys for members who joined before Onramp: everyone who joined in the
 * last N days and has none yet, a batch at a time.
 *
 * 🚨 Batched because each member is a handful of counting queries. The admin
 * button calls this until nothing is left, so a forum with thousands of
 * recent sign-ups never meets a request timeout — and nothing is sent to
 * anybody: steps they already did are ticked, and paid, without an alert.
 */
class Starter
{
    public function __construct(private Journeys $journeys)
    {
    }

    /** @return array{started: int, remaining: int} */
    public function batch(int $days, int $limit = 100): array
    {
        $query = $this->eligible($days);
        $started = 0;

        foreach ($query->clone()->orderBy('id')->limit($limit)->get() as $user) {
            $this->journeys->start($user, Journeys::SILENT);
            $started++;
        }

        return ['started' => $started, 'remaining' => $query->count()];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function eligible(int $days)
    {
        return User::query()
            ->where('joined_at', '>=', Carbon::now()->subDays(max(1, min(3650, $days))))
            ->whereNotExists(fn ($q) => $q->from('onramp_journeys')->whereColumn('onramp_journeys.user_id', 'users.id'));
    }
}
