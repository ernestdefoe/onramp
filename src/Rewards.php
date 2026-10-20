<?php

namespace ErnestDefoe\Onramp;

use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Container\Container;

/**
 * Paying out: FoF Gamification points, a FoF Badges badge, a group.
 *
 * Each of these is idempotent on its own side — a point grant is keyed, a
 * badge is unique per member, a group is attached once — so a reward can
 * never be paid twice, even by two requests racing to complete the same step.
 *
 * 🚨 Both FoF extensions are optional and resolved by NAME at the moment of
 * paying. Nothing here imports them, so Onramp loads the same with either,
 * both or neither installed.
 */
class Rewards
{
    /** fof/gamification's point-grants seam (added to fof/gamification for this; absent from older versions). */
    public const POINT_GRANTS = 'FoF\Gamification\PointGrants';

    public function __construct(private ExtensionManager $extensions, private Container $container, private Dispatcher $events)
    {
    }

    public function pointsAvailable(): bool
    {
        return $this->extensions->isEnabled('fof-gamification') && class_exists(self::POINT_GRANTS);
    }

    public function badgesAvailable(): bool
    {
        return $this->extensions->isEnabled('fof-badges') && class_exists('FoF\Badges\Badge');
    }

    /** @param string $key what the points are for, e.g. `step:12` — one grant per key, ever */
    public function points(User $user, int $points, string $key): void
    {
        if ($points !== 0 && $this->pointsAvailable()) {
            $this->container->make(self::POINT_GRANTS)->grant($user, 'onramp', $key, $points);
        }
    }

    public function badge(User $user, ?int $badgeId): void
    {
        if (! $badgeId || ! $this->badgesAvailable()) {
            return;
        }

        $badge = \FoF\Badges\Badge::query()->find($badgeId);

        if ($badge) {
            $this->container->make('FoF\Badges\BadgeAwarder')->award($user, $badge, \FoF\Badges\UserBadge::GRANTED_BY_SYSTEM);
        }
    }

    public function group(User $user, int $groupId): void
    {
        // Never Admin, Guest or Member (ids 1–3): a journey is not a way to
        // hand out the keys to the forum, and the other two are implicit.
        if ($groupId <= Group::MEMBER_ID || ! Group::query()->whereKey($groupId)->exists()) {
            return;
        }

        $before = $user->groups()->get()->all();

        if (! in_array($groupId, array_map(fn (Group $g) => $g->id, $before), true)) {
            $user->groups()->attach($groupId);
            $user->unsetRelation('groups');
            // Ranks, Ladder and the like listen for this.
            $this->events->dispatch(new GroupsChanged($user, $before));
        }
    }
}
