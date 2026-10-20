<?php

namespace ErnestDefoe\Onramp\StepType;

use Closure;
use Flarum\User\User;

/**
 * A step type described by values and closures, which is all most types are.
 * The built-in catalogue is made of these; a type that needs more can extend
 * {@see StepType} directly.
 */
final class CallbackType extends StepType
{
    /**
     * @param array<string, callable(object): mixed> $events
     * @param Closure(User, array<string, mixed>): int $progress
     * @param list<string> $fields
     */
    public function __construct(
        private string $key,
        private string $icon,
        private array $events,
        private Closure $progress,
        private array $fields = [],
        private ?string $extension = null,
        private bool $client = false,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function events(): array
    {
        return $this->events;
    }

    public function progress(User $user, array $config): int
    {
        return ($this->progress)($user, $config);
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function extension(): ?string
    {
        return $this->extension;
    }

    public function reportedByClient(): bool
    {
        return $this->client;
    }
}
