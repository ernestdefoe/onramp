<?php

namespace ErnestDefoe\Onramp\Event;

use Flarum\User\User;

/** A member finished their journey (dispatched once per member). */
class Graduated
{
    public function __construct(public User $user)
    {
    }
}
