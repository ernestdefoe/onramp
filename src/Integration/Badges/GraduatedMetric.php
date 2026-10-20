<?php

namespace ErnestDefoe\Onramp\Integration\Badges;

use ErnestDefoe\Onramp\Event\Graduated;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** A FoF Badges trigger: "finished their Onramp journey". */
class GraduatedMetric implements MetricInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'onramp_graduated';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-onramp.admin.badges.graduated';
    }

    public function getEventTriggers(): array
    {
        return [Graduated::class => fn (Graduated $e) => $e->user];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('onramp_journeys')->where('user_id', $user->id)->whereNotNull('completed_at')->exists() ? 1 : 0;
    }

    public function isBoolean(): bool
    {
        return true;
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
