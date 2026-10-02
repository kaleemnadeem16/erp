<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** Which counter a number is drawn from: a period plus dimensions (the company is always in scope). Explicit, never derived. */
final class CounterScope
{
    /** @param  list<string>  $dimensions variable keys, sorted and unique */
    public function __construct(public readonly ScopePeriod $period = ScopePeriod::None, public readonly array $dimensions = []) {}

    public static function company(): self
    {
        return new self(ScopePeriod::None);
    }

    /** @param  list<string>  $dimensions */
    public static function of(ScopePeriod $period, array $dimensions = []): self
    {
        $dimensions = array_values(array_unique($dimensions));
        sort($dimensions);

        return new self($period, $dimensions);
    }

    /** @param  array{period?: string, dimensions?: list<string>}  $data */
    public static function fromArray(array $data): self
    {
        return self::of(ScopePeriod::from($data['period'] ?? 'NONE'), $data['dimensions'] ?? []);
    }

    public function needsDate(): bool
    {
        return $this->period->needsDate();
    }

    /** "Company + Branch + Year" for the screens and the tester. */
    public function describe(): string
    {
        $parts = ['Company', ...array_map(fn (string $d): string => ucfirst(strtolower(str_replace('_', ' ', $d))), $this->dimensions)];
        $period = match ($this->period) {
            ScopePeriod::None => null,
            ScopePeriod::Year => 'Year',
            ScopePeriod::Month => 'Month',
            ScopePeriod::Day => 'Day',
            ScopePeriod::FiscalYear => 'Fiscal year',
            ScopePeriod::FiscalPeriod => 'Fiscal period',
        };
        if ($period !== null) {
            $parts[] = $period;
        }

        return implode(' + ', $parts);
    }
}
