<?php

namespace ErnestDefoe\Onramp\Integration\Badges;

use ErnestDefoe\Onramp\Event\Graduated;
use ErnestDefoe\Onramp\Event\StepCompleted;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * A FoF Badges trigger: "Onramp steps completed ≥ N". Lets an admin build a
 * badge ladder along the journey in FoF Badges' own editor.
 *
 * Only ever loaded when fof/badges is installed (see BadgesProvider).
 */
class StepsCompletedMetric implements MetricInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'onramp_steps_completed';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-onramp.admin.badges.steps_completed';
    }

    public function getEventTriggers(): array
    {
        return [
            StepCompleted::class => fn (StepCompleted $e) => $e->user,
            Graduated::class => fn (Graduated $e) => $e->user,
        ];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('onramp_progress')->where('user_id', $user->id)->whereNotNull('completed_at')->count();
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/onramp'];
    }
}
