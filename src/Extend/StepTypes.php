<?php

namespace ErnestDefoe\Onramp\Extend;

use ErnestDefoe\Onramp\StepType\StepType;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * Add a step type from another extension.
 *
 *     // extend.php
 *     (new \ErnestDefoe\Onramp\Extend\StepTypes())->add(JoinedAGroup::class),
 *
 * The class extends {@see StepType}: a key (namespace it — `myext.joined`),
 * an icon, the events that may move it, and how to count it. Name the
 * extension it belongs to in `extension()` and Onramp hides it whenever that
 * extension is off. Give it a title in your locale file under
 * `ernestdefoe-onramp.lib.types.<key>.title` (and `.description`), or let
 * admins write their own on the step.
 *
 * Wrap it in `Extend\Conditional` so it only applies when Onramp is enabled.
 */
class StepTypes implements ExtenderInterface
{
    /** @var list<class-string<StepType>> */
    private array $types = [];

    /** @param class-string<StepType> $type */
    public function add(string $type): self
    {
        $this->types[] = $type;

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend('onramp.step-types', fn (array $existing) => [...$existing, ...$this->types]);
    }
}
