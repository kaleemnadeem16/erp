<?php

declare(strict_types=1);

namespace App\Support\Scope;

/**
 * Records reached for a capability: ALL/NONE/units for legacy adapters, with explicit own/unit alternatives for resource-aware adapters.
 * An empty unit set reaches nothing, never everything. RecordScope keeps unsupported variants out of legacy callers.
 */
final readonly class ResolvedScope
{
    private const ALL = 'ALL';

    private const NONE = 'NONE';

    private const UNITS = 'UNITS';

    private const OWN = 'OWN';

    private const RECORDS = 'RECORDS';

    /** @param  list<int>  $unitIds */
    private function __construct(private string $kind, private array $unitIds = [], private bool $ownPath = false, private bool $namedPath = false) {}

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

    /** Same-duty alternatives for a resource-aware adapter; legacy resolution never returns this union.
     * @param  list<int>  $unitIds
     */
    public static function records(bool $ownsRecords, array $unitIds, bool $namedDelegation = false): self
    {
        if (! $ownsRecords && $unitIds === [] && ! $namedDelegation) {
            return self::none();
        }

        return new self(self::RECORDS, array_values(array_unique($unitIds)), $ownsRecords, $namedDelegation);
    }

    public function ownsRecords(): bool
    {
        return $this->kind === self::OWN || $this->ownPath;
    }

    public function allowsNamedDelegation(): bool
    {
        return $this->namedPath;
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
