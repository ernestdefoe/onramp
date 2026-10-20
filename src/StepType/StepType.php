<?php

namespace ErnestDefoe\Onramp\StepType;

use Flarum\User\User;

/**
 * A kind of step: what it counts, and which events mean it may have moved.
 *
 * 🚨 A step's progress is COUNTED from the forum's own records, never tallied
 * from events. Events only say "look again" — so a journey started today
 * already shows what the member did last month, a missed event is caught the
 * next time the member opens Onramp, and nothing can be counted twice.
 *
 * Other extensions add their own types with {@see \ErnestDefoe\Onramp\Extend\StepTypes}.
 */
abstract class StepType
{
    /** Unique key, stored on the step. Namespace a third-party one: `myext.thing`. */
    abstract public function key(): string;

    /** Font Awesome icon shown when the admin did not pick one. */
    abstract public function icon(): string;

    /**
     * Events after which a member's count may have changed, each mapped to the
     * member(s) to look at again.
     *
     * Keys are class NAMES, not class-strings: an optional extension's event
     * may not exist on this forum, and the subscriber skips any that does not.
     *
     * @return array<string, callable(object): mixed>
     */
    abstract public function events(): array;

    /** How far the member has got: compared with {@see target()}. */
    abstract public function progress(User $user, array $config): int;

    /**
     * The extension this type belongs to, as an extension id
     * (`flarum-likes`), or null for core. A type whose extension is disabled
     * is hidden and never blocks graduation.
     */
    public function extension(): ?string
    {
        return null;
    }

    /**
     * What the admin can set on a step of this type: `count`, `tag` or `path`.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return [];
    }

    /** How many it takes. Types without a count are done at 1. */
    public function target(array $config): int
    {
        return in_array('count', $this->fields(), true) ? max(1, (int) ($config['count'] ?? 1)) : 1;
    }

    /**
     * Whether the member's browser reports this step (a page visit) rather
     * than the server noticing it.
     */
    public function reportedByClient(): bool
    {
        return false;
    }
}
