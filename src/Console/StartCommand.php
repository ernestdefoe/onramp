<?php

namespace ErnestDefoe\Onramp\Console;

use ErnestDefoe\Onramp\Starter;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * php flarum onramp:start --days=30 — the admin button, from a terminal.
 */
class StartCommand extends AbstractCommand
{
    public function __construct(private Starter $starter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('onramp:start')
            ->setDescription('Start Onramp journeys for members who joined in the last N days and have none.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How far back to go, in days.', '30');
    }

    protected function fire(): int
    {
        $days = (int) $this->input->getOption('days');
        $total = 0;

        do {
            $batch = $this->starter->batch($days, 200);
            $total += $batch['started'];
            $this->info("Started {$total}, {$batch['remaining']} to go.");
        } while ($batch['started'] > 0 && $batch['remaining'] > 0);

        return 0;
    }
}
