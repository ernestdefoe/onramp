<?php

namespace ErnestDefoe\Onramp\Listener;

use ErnestDefoe\Onramp\StepType\Registry;
use ErnestDefoe\Onramp\Tracker;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * One listener per event class any step type cares about, which tells the
 * tracker whose steps of which types to look at again.
 *
 * Event classes of extensions that are not installed are skipped here, so an
 * absent extension adds no listener at all.
 */
class TriggerSubscriber
{
    public function __construct(private Registry $types, private Tracker $tracker)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        /** @var array<string, list<array{string, callable}>> $map */
        $map = [];

        foreach ($this->types->all() as $type) {
            foreach ($type->events() as $event => $extract) {
                if (class_exists($event)) {
                    $map[$event][] = [$type->key(), $extract];
                }
            }
        }

        foreach ($map as $event => $watchers) {
            $events->listen($event, function (object $e) use ($watchers): void {
                foreach ($watchers as [$key, $extract]) {
                    $found = $extract($e);

                    // 🚨 Not `(array) $found`: cast to an array, a User is
                    // its own attributes, not a list of one.
                    foreach (is_array($found) ? $found : [$found] as $user) {
                        $id = $user instanceof User ? $user->id : (int) $user;

                        if ($id > 0) {
                            $this->tracker->touch($id, $key);
                        }
                    }
                }
            });
        }
    }
}
