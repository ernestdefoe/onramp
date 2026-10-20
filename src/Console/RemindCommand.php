<?php

namespace ErnestDefoe\Onramp\Console;

use Carbon\Carbon;
use ErnestDefoe\Onramp\Config;
use ErnestDefoe\Onramp\Journey;
use ErnestDefoe\Onramp\Notification\ReminderBlueprint;
use ErnestDefoe\Onramp\Presenter;
use Flarum\Console\AbstractCommand;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Database\ConnectionInterface;

/**
 * Hourly: put away journeys left too long, and remind members still on one.
 *
 * A reminder goes out on each day the admin chose (3, 7 and 14 by default),
 * counted from the day the journey started; a member who was due two at once
 * (the forum's scheduler was off for a week) gets the later one only.
 *
 * 🚨 At most 500 reminders a run. Each can be an email, and a backlog — a
 * scheduler switched on for the first time on a busy forum — must drain over
 * a few hours rather than land on the mail server in one go.
 */
class RemindCommand extends AbstractCommand
{
    private const PER_RUN = 500;

    public function __construct(
        private Config $config,
        private Presenter $presenter,
        private NotificationSyncer $notifications,
        private TranslatorInterface $translator,
        private ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('onramp:remind')
            ->setDescription('Send Onramp reminders and put away journeys left unfinished for too long.');
    }

    protected function fire(): int
    {
        if (! $this->config->enabled()) {
            return 0;
        }

        $closed = $this->closeStale();
        $sent = $this->config->reminders() ? $this->remind() : 0;

        $this->info("Closed {$closed} journeys, sent {$sent} reminders.");

        return 0;
    }

    private function closeStale(): int
    {
        $days = $this->config->closeAfter();

        if ($days === 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $closed = 0;

        $this->db->table('onramp_journeys')
            ->whereNull('completed_at')
            ->whereNull('closed_at')
            ->where('started_at', '<=', $cutoff)
            ->chunkById(500, function ($rows) use (&$closed) {
                $ids = $rows->pluck('user_id')->all();
                $this->db->table('onramp_journeys')->whereIn('user_id', $ids)->update(['closed_at' => Carbon::now()]);
                $this->db->table('users')->whereIn('id', $ids)->update(['onramp_open' => false]);
                $closed += count($ids);
            }, 'user_id');

        return $closed;
    }

    private function remind(): int
    {
        $days = $this->config->reminderDays();

        if (! $days) {
            return 0;
        }

        $sent = 0;
        $now = Carbon::now();

        // A journey is due once the earliest reminder day it has not had yet
        // has passed.
        Journey::query()
            ->with('user')
            ->whereNull('completed_at')
            ->whereNull('closed_at')
            ->where('started_at', '<=', $now->copy()->subDays($days[0]))
            ->where('reminded', '<', end($days))
            ->chunkById(100, function ($journeys) use ($days, $now, &$sent) {
                foreach ($journeys as $journey) {
                    if ($sent >= self::PER_RUN) {
                        return false;
                    }

                    $age = (int) $journey->started_at->diffInDays($now);
                    $due = array_values(array_filter($days, fn (int $d) => $d <= $age && $d > $journey->reminded));

                    if (! $due || ! $journey->user) {
                        continue;
                    }

                    $day = end($due);
                    $this->db->table('onramp_journeys')->where('user_id', $journey->user_id)->update(['reminded' => $day]);

                    $state = $this->presenter->summary($journey->user);
                    $pending = array_values(array_filter($state['steps'] ?? [], fn ($s) => ! $s['completedAt'] && ! $s['skippedAt']));

                    if (! $pending) {
                        continue;
                    }

                    $this->notifications->sync(
                        new ReminderBlueprint($journey->user, $day, count($pending), $this->title($pending[0])),
                        [$journey->user]
                    );
                    $sent++;
                }

                return true;
            }, 'user_id');

        return $sent;
    }

    /** @param array<string, mixed> $step */
    private function title(array $step): string
    {
        return $step['title'] ?: $this->translator->trans('ernestdefoe-onramp.lib.types.'.$step['type'].'.title', [
            'count' => $step['target'],
            'tag' => $step['tag']['name'] ?? '',
        ]);
    }
}
