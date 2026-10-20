<?php

namespace ErnestDefoe\Onramp;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The admin's settings, read in one place with their defaults.
 */
class Config
{
    public const PREFIX = 'ernestdefoe-onramp.';

    public const DEFAULTS = [
        'enabled' => true,
        'launcher' => true,
        'reward_points' => 25,
        'reward_badge' => 0,
        'reward_group' => 0,
        'reminders' => true,
        'reminder_days' => '3,7,14',
        'close_after' => 30,
    ];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function launcher(): bool
    {
        return (bool) $this->get('launcher');
    }

    public function rewardPoints(): int
    {
        return (int) $this->get('reward_points');
    }

    public function rewardBadge(): int
    {
        return (int) $this->get('reward_badge');
    }

    public function rewardGroup(): int
    {
        return (int) $this->get('reward_group');
    }

    public function reminders(): bool
    {
        return (bool) $this->get('reminders');
    }

    /**
     * Days after the start to send a reminder on, ascending, each 1–365.
     *
     * @return list<int>
     */
    public function reminderDays(): array
    {
        $days = array_map('intval', explode(',', (string) $this->get('reminder_days')));
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d >= 1 && $d <= 365)));
        sort($days);

        return $days;
    }

    /** Days without finishing before a journey is put away; 0 = never. */
    public function closeAfter(): int
    {
        return max(0, (int) $this->get('close_after'));
    }

    private function get(string $key): mixed
    {
        return $this->settings->get(self::PREFIX.$key, self::DEFAULTS[$key]);
    }
}
