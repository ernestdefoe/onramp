<?php

namespace ErnestDefoe\Onramp;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Event\Graduated;
use ErnestDefoe\Onramp\Event\StepCompleted;
use ErnestDefoe\Onramp\Notification\GraduatedBlueprint;
use ErnestDefoe\Onramp\Notification\StepCompletedBlueprint;
use ErnestDefoe\Onramp\StepType\Registry;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Starting, advancing and finishing journeys.
 *
 * 🚨 Completing a step and graduating are CLAIMS: a conditional write whose
 * affected-row count says whether this request is the one that did it. Only
 * the claimant pays. Two requests completing the same step at once (a double
 * click, a like and a reply landing together) therefore pay once, without a
 * lock held across the counting queries.
 */
class Journeys
{
    /** The member's own request: the toast tells them, no alert per step. */
    public const WATCHING = 1;

    /** Someone else's request (a like they received): alert them. */
    public const AWAY = 2;

    /** Journeys started in bulk: nobody is told anything. */
    public const SILENT = 3;

    public function __construct(
        private ConnectionInterface $db,
        private Registry $types,
        private Rewards $rewards,
        private Config $config,
        private Dispatcher $events,
        private NotificationSyncer $notifications,
    ) {
    }

    /**
     * Every enabled step whose type is available, in order.
     *
     * @return Collection<int, Step>
     */
    public function steps(): Collection
    {
        $available = array_keys($this->types->available());

        return Step::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (Step $s) => in_array($s->type, $available, true))
            ->values();
    }

    public function journey(User $user): ?Journey
    {
        return Journey::query()->find($user->id);
    }

    /**
     * Give a member a journey, and tick whatever they have already done.
     *
     * @param self::* $mode SILENT when starting journeys for existing members,
     *                      who must not each get a burst of notifications
     */
    public function start(User $user, int $mode = self::WATCHING): Journey
    {
        $journey = $this->journey($user);

        if (! $journey) {
            $row = ['user_id' => $user->id, 'started_at' => Carbon::now(), 'reminded' => 0];

            // Built from what was written rather than read back; a lost race
            // (the row was already there) reads the winner's.
            $journey = $this->db->table('onramp_journeys')->insertOrIgnore($row) > 0
                ? (new Journey())->forceFill($row)->syncOriginal()
                : $this->journey($user);
            $journey->exists = true;
        }

        if ($journey->isOpen()) {
            $this->setOpen($user, true);

            if ($this->evaluate($user, null, $mode, $journey)['graduated']) {
                $journey->refresh();
            }
        }

        return $journey;
    }

    /**
     * Look again at a member's pending steps — those of the given types, or
     * all of them — and complete the ones they have now done.
     *
     * @param list<string>|null $typeKeys
     * @param self::* $mode
     * @return array{completed: list<Step>, graduated: bool}
     */
    public function evaluate(User $user, ?array $typeKeys = null, int $mode = self::WATCHING, ?Journey $journey = null): array
    {
        $result = ['completed' => [], 'graduated' => false];
        $journey ??= $this->journey($user);

        if (! $journey || ! $journey->isOpen()) {
            return $result;
        }

        $steps = $this->steps();
        $done = $this->completedIds($user);

        foreach ($steps as $step) {
            if (in_array($step->id, $done, true) || ($typeKeys !== null && ! in_array($step->type, $typeKeys, true))) {
                continue;
            }

            $type = $this->types->get($step->type);

            if (! $type || $type->reportedByClient()) {
                continue;
            }

            if ($type->progress($user, $step->settings()) >= $type->target($step->settings())) {
                if ($this->complete($user, $step, $mode)) {
                    $result['completed'][] = $step;
                }
            }
        }

        if ($result['completed']) {
            $result['graduated'] = $this->graduateIfDone($user, $steps, $mode);
        }

        return $result;
    }

    /**
     * Mark a step done and pay for it — if this call is the one that did.
     *
     * @param self::* $mode
     */
    public function complete(User $user, Step $step, int $mode = self::WATCHING): bool
    {
        $now = Carbon::now();

        $claimed = $this->db->table('onramp_progress')->insertOrIgnore([
            'user_id' => $user->id,
            'step_id' => $step->id,
            'completed_at' => $now,
        ]) > 0;

        // A row already there was a skip (or an earlier completion): only a
        // skip turns into a completion.
        $claimed = $claimed || $this->db->table('onramp_progress')
            ->where('user_id', $user->id)
            ->where('step_id', $step->id)
            ->whereNull('completed_at')
            ->update(['completed_at' => $now, 'skipped_at' => null]) > 0;

        if (! $claimed) {
            return false;
        }

        $this->rewards->points($user, $step->points, 'step:'.$step->id);
        $this->rewards->badge($user, $step->badge_id);

        if ($mode === self::AWAY) {
            $this->notifications->sync(new StepCompletedBlueprint($user, $step), [$user]);
        }

        $this->events->dispatch(new StepCompleted($user, $step));

        return true;
    }

    /** A member's own "not for me": optional steps only. */
    public function skip(User $user, Step $step): bool
    {
        if ($step->is_required) {
            return false;
        }

        $this->db->table('onramp_progress')->insertOrIgnore([
            'user_id' => $user->id,
            'step_id' => $step->id,
            'skipped_at' => Carbon::now(),
        ]);

        $this->graduateIfDone($user, $this->steps());

        return true;
    }

    /** Take a skip back: the step is pending again. */
    public function undoSkip(User $user, Step $step): void
    {
        $this->db->table('onramp_progress')
            ->where('user_id', $user->id)
            ->where('step_id', $step->id)
            ->whereNull('completed_at')
            ->delete();
    }

    /** The member put Onramp away. */
    public function close(User $user): void
    {
        $this->db->table('onramp_journeys')
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->update(['closed_at' => Carbon::now()]);
        $this->setOpen($user, false);
    }

    /** Back on the road: undoes a close. Graduates stay graduated. */
    public function reopen(User $user): Journey
    {
        $this->db->table('onramp_journeys')
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->update(['closed_at' => null]);

        return $this->start($user);
    }

    /**
     * An admin's fresh start for a member: progress wiped, journey reopened.
     *
     * Rewards already paid are NOT paid again on the second time round — a
     * point grant is keyed per step and a badge is held once — so a reset
     * cannot be used to farm them.
     */
    public function reset(User $user): Journey
    {
        $this->db->table('onramp_progress')->where('user_id', $user->id)->delete();
        $this->db->table('onramp_journeys')->where('user_id', $user->id)->delete();

        return $this->start($user, self::SILENT);
    }

    /**
     * @param Collection<int, Step> $steps
     * @param self::* $mode
     */
    public function graduateIfDone(User $user, Collection $steps, int $mode = self::WATCHING): bool
    {
        if ($steps->isEmpty()) {
            return false;
        }

        $rows = $this->db->table('onramp_progress')
            ->where('user_id', $user->id)
            ->whereIn('step_id', $steps->pluck('id'))
            ->get(['step_id', 'completed_at', 'skipped_at'])
            ->keyBy('step_id');

        foreach ($steps as $step) {
            $row = $rows->get($step->id);

            if (! $row || (! $row->completed_at && $step->is_required)) {
                return false;
            }
        }

        $claimed = $this->db->table('onramp_journeys')
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereNull('closed_at')
            ->update(['completed_at' => Carbon::now()]) > 0;

        if (! $claimed) {
            return false;
        }

        $this->setOpen($user, false);

        $this->rewards->points($user, $this->config->rewardPoints(), 'journey');
        $this->rewards->badge($user, $this->config->rewardBadge() ?: null);
        $this->rewards->group($user, $this->config->rewardGroup());

        // Graduating is worth an alert even when the member saw the toast:
        // it is the one moment they may want to find again.
        if ($mode !== self::SILENT) {
            $this->notifications->sync(new GraduatedBlueprint($user), [$user]);
        }

        $this->events->dispatch(new Graduated($user));

        return true;
    }

    /** @return list<int> */
    public function completedIds(User $user): array
    {
        return $this->db->table('onramp_progress')
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->pluck('step_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function setOpen(User $user, bool $open): void
    {
        if ((bool) $user->onramp_open !== $open) {
            $this->db->table('users')->where('id', $user->id)->update(['onramp_open' => $open]);
        }

        // Keep the in-memory model in step without saving it: the member may
        // be mid-save in the request that got us here.
        $user->setRawAttributes(['onramp_open' => $open] + $user->getAttributes(), true);
    }
}
