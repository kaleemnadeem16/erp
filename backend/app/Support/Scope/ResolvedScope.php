<?php

declare(strict_types=1);

namespace App\Support\Scope;

/**
 * Which records a person may reach for a capability (ADR 0089): all of the company's, none, or those of a set of organisation units.
 * "Units" with an empty list is a restricted person who belongs to no unit: they reach nothing, never everything.
 */
final readonly class ResolvedScope
{
    private const ALL = 'ALL';

    private const NONE = 'NONE';

    private const UNITS = 'UNITS';

    private const OWN = 'OWN';

    /** @param  list<int>  $unitIds */
    private function __construct(private string $kind, private array $unitIds = []) {}

    public static function all(): self
    {
        return new self(self::ALL);
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** @param  list<int>  $unitIds */
    public static function units(array $unitIds): self
    {
        return new self(self::UNITS, array_values(array_unique($unitIds)));
    }

    public static function own(): self
    {
        return new self(self::OWN);
    }

    public function ownsRecords(): bool
    {
        return $this->kind === self::OWN;
    }

    public function allowsNamedDelegation(): bool
    {
        return false;
    }

    public function isAll(): bool
    {
        return $this->kind === self::ALL;
    }

    public function isNone(): bool
    {
        return $this->kind === self::NONE;
    }

    /** @return list<int> the units reached, including their descendants; empty for none */
    public function unitIds(): array
    {
        return $this->unitIds;
    }
}
