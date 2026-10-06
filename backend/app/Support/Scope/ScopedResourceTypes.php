<?php

declare(strict_types=1);

namespace App\Support\Scope;

use Closure;

/**
 * The kinds of resource an organisation unit can own (ADR 0089). The module that owns a kind registers it from its service provider
 * (Inventory registers `warehouse`), so the organisation code never reaches into another module, and a new kind — a sales region, a
 * cost centre — is an addition, not a migration.
 */
final class ScopedResourceTypes
{
    /** @var array<string, array{label: string, exists: Closure(int): bool, name: Closure(int): ?string}> */
    private array $types = [];

    /**
     * @param  Closure(int): bool  $exists  does this resource exist in the current company?
     * @param  Closure(int): ?string  $name  a human name for it, or null
     */
    public function register(string $type, string $label, Closure $exists, Closure $name): void
    {
        $this->types[$type] = ['label' => $label, 'exists' => $exists, 'name' => $name];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function label(string $type): string
    {
        return $this->types[$type]['label'] ?? $type;
    }

    public function exists(string $type, int $id): bool
    {
        return $this->has($type) && ($this->types[$type]['exists'])($id);
    }

    public function name(string $type, int $id): ?string
    {
        return $this->has($type) ? ($this->types[$type]['name'])($id) : null;
    }
}
