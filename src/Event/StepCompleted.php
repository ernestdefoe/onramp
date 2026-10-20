<?php

namespace ErnestDefoe\Onramp\Event;

use ErnestDefoe\Onramp\Step;
use Flarum\User\User;

/** A member completed a step (dispatched once per member and step, ever). */
class StepCompleted
{
    public function __construct(public User $user, public Step $step)
    {
    }
}
