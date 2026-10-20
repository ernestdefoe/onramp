<?php

namespace ErnestDefoe\Onramp;

use ErnestDefoe\Onramp\StepType\BuiltIn;
use ErnestDefoe\Onramp\StepType\Registry;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;

class OnrampServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Step types other extensions add (Extend\StepTypes).
        $this->container->instance('onramp.step-types', []);

        $this->container->singleton(Registry::class, fn ($c) => new Registry(
            $c->make(BuiltIn::class),
            $c->make(ExtensionManager::class),
            $c->make('onramp.step-types'),
        ));

        // 🚨 A singleton: it holds what this request touched until the end.
        $this->container->singleton(Tracker::class);
    }
}
