<?php

namespace ErnestDefoe\Onramp\Integration\Badges;

use ErnestDefoe\Onramp\Event\Graduated;
use ErnestDefoe\Onramp\Event\StepCompleted;
use Flarum\Foundation\AbstractServiceProvider;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\Trigger\MetricManager;
use FoF\Badges\Trigger\TriggerEvaluator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Onramp's two triggers in FoF Badges, and the listener that evaluates them.
 *
 * 🚨 FoF Badges' own listener cannot be reused for Onramp's events: it finds
 * the member by asking `instanceof` about the events it knows, and returns
 * null for any other. So Onramp asks the evaluator itself — the same two
 * calls that listener makes.
 *
 * Registered from extend.php only when fof-badges is enabled.
 */
class BadgesProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(MetricManager::class, function (MetricManager $manager) {
            $manager->register($this->container->make(StepsCompletedMetric::class));
            $manager->register($this->container->make(GraduatedMetric::class));

            return $manager;
        });
    }

    public function boot(Dispatcher $events): void
    {
        $evaluate = function (StepCompleted|Graduated $event): void {
            $evaluator = $this->container->make(TriggerEvaluator::class);
            $awarder = $this->container->make(BadgeAwarder::class);

            foreach ($evaluator->evaluateForEvent($event->user, $event::class) as $badge) {
                $awarder->award($event->user, $badge);
            }
        };

        $events->listen(StepCompleted::class, $evaluate);
        $events->listen(Graduated::class, $evaluate);
    }
}
