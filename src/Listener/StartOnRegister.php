<?php

namespace ErnestDefoe\Onramp\Listener;

use ErnestDefoe\Onramp\Config;
use ErnestDefoe\Onramp\Journeys;
use Flarum\User\Event\Registered;

/** Every new member starts a journey the moment they sign up. */
class StartOnRegister
{
    public function __construct(private Journeys $journeys, private Config $config)
    {
    }

    public function handle(Registered $event): void
    {
        if ($this->config->enabled()) {
            // SILENT: a brand-new member has no history worth an alert, and
            // a sign-up through a provider may already have an avatar.
            $this->journeys->start($event->user, Journeys::SILENT);
        }
    }
}
