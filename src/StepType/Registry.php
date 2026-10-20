<?php

namespace ErnestDefoe\Onramp\StepType;

use Flarum\Extension\ExtensionManager;

/**
 * Every step type, keyed — the built-in ones and any another extension added.
 *
 * "Available" is the question everything else asks: a type whose extension is
 * disabled is not offered to the admin, not shown to members, not evaluated,
 * and not waited for at graduation.
 */
class Registry
{
    /** @var array<string, StepType>|null */
    private ?array $types = null;

    /**
     * @param list<class-string<StepType>|StepType> $extra from the StepTypes extender
     */
    public function __construct(private BuiltIn $builtIn, private ExtensionManager $extensions, private array $extra = [])
    {
    }

    /** @return array<string, StepType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];

            foreach ([...$this->builtIn->types(), ...array_map(fn ($t) => is_string($t) ? resolve($t) : $t, $this->extra)] as $type) {
                $this->types[$type->key()] = $type;
            }
        }

        return $this->types;
    }

    /** @return array<string, StepType> */
    public function available(): array
    {
        return array_filter($this->all(), fn (StepType $t) => $this->isAvailable($t));
    }

    public function get(string $key): ?StepType
    {
        $type = $this->all()[$key] ?? null;

        return $type && $this->isAvailable($type) ? $type : null;
    }

    private function isAvailable(StepType $type): bool
    {
        $ext = $type->extension();

        return $ext === null || $this->extensions->isEnabled($ext);
    }
}
