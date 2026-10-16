<?php

declare(strict_types=1);

namespace App\Support\Currency;

use RuntimeException;

/**
 * The maintained ISO 4217 reference dataset (ADR 0143): code, name and minor unit, kept in the repository and versioned with it. A malformed dataset
 * stops the synchronisation before anything is read from or written to the database. An entry whose minor unit is absent (funds, metals, test codes) or
 * beyond the four places that storage can settle is *unsupported*: it is reported, never inserted.
 */
final class IsoCurrencyDataset
{
    public const MAX_MINOR_UNITS = 4;

    /** @param list<array{code: string, name: string, minor_units: int|null}> $currencies */
    private function __construct(public readonly string $version, public readonly string $source, public readonly array $currencies) {}

    public static function load(?string $path = null): self
    {
        $path ??= base_path('database/reference/iso-4217.json');
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new RuntimeException("The currency dataset [{$path}] could not be read.");
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['currencies'] ?? null) || ! is_string($data['dataset_version'] ?? null) || $data['dataset_version'] === '') {
            throw new RuntimeException('The currency dataset is not in the expected shape (dataset_version and currencies are required).');
        }
        $seen = [];
        $rows = [];
        foreach ($data['currencies'] as $index => $row) {
            $code = is_array($row) ? ($row['code'] ?? null) : null;
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $units = is_array($row) ? ($row['minor_units'] ?? null) : null;
            if (! is_string($code) || preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                throw new RuntimeException("Currency dataset entry {$index} has no valid three-letter code.");
            }
            if (isset($seen[$code])) {
                throw new RuntimeException("Currency dataset lists {$code} more than once.");
            }
            if (! is_string($name) || trim($name) === '') {
                throw new RuntimeException("Currency dataset entry {$code} has no name.");
            }
            if ($units !== null && ! (is_int($units) && $units >= 0)) {
                throw new RuntimeException("Currency dataset entry {$code} has an invalid minor unit.");
            }
            $seen[$code] = true;
            $rows[] = ['code' => $code, 'name' => trim($name), 'minor_units' => $units];
        }

        return new self($data['dataset_version'], (string) ($data['source'] ?? ''), $rows);
    }

    /** Whether the platform can settle in this entry (a minor unit from zero to four places). */
    public static function supports(?int $minorUnits): bool
    {
        return $minorUnits !== null && $minorUnits >= 0 && $minorUnits <= self::MAX_MINOR_UNITS;
    }
}
