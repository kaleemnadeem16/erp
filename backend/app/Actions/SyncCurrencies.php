<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Currency;
use App\Support\Currency\CurrencyUsage;
use App\Support\Currency\IsoCurrencyDataset;
use Illuminate\Support\Facades\DB;

/**
 * Plans and applies the synchronisation of the global `currencies` table with the ISO 4217 dataset (ADR 0143). Planning only reads. Applying inserts the
 * missing supported currencies and nothing more by default: a currency's minor unit is governed reference data, so a difference is reported as a
 * conflict and never applied to a used currency under any option. This Action is the deploy-time operator's tool, not a company-facing surface; the
 * console command that calls it is the only entry point and carries the operator's identity into the audit record.
 */
final class SyncCurrencies
{
    public function __construct(private readonly CurrencyUsage $usage) {}

    /**
     * @return array{
     *     dataset_version: string,
     *     insert: list<array{code: string, name: string, minor_units: int}>,
     *     unchanged: list<string>,
     *     name_drift: list<array{code: string, current: string, dataset: string}>,
     *     conflict: list<array{code: string, current: int, dataset: int, used: bool, used_by: list<string>}>,
     *     unsupported: list<array{code: string, name: string, minor_units: int|null, reason: string}>,
     *     local_only: list<string>
     * }
     */
    public function plan(IsoCurrencyDataset $dataset): array
    {
        $existing = Currency::query()->get()->keyBy('code');
        $used = $this->usage->usedBy();
        $plan = ['dataset_version' => $dataset->version, 'insert' => [], 'unchanged' => [], 'name_drift' => [], 'conflict' => [], 'unsupported' => [], 'local_only' => []];

        foreach ($dataset->currencies as $entry) {
            if (! IsoCurrencyDataset::supports($entry['minor_units'])) {
                $plan['unsupported'][] = $entry + ['reason' => $entry['minor_units'] === null
                    ? 'the standard publishes no minor unit for this code'
                    : 'a minor unit of '.$entry['minor_units'].' places cannot be settled in decimal(19,4)'];

                continue;
            }
            $current = $existing->get($entry['code']);
            if ($current === null) {
                $plan['insert'][] = $entry;

                continue;
            }
            if ((int) $current->decimal_places !== $entry['minor_units']) {
                $usedBy = $used[$current->id] ?? [];
                $plan['conflict'][] = ['code' => $entry['code'], 'current' => (int) $current->decimal_places, 'dataset' => (int) $entry['minor_units'],
                    'used' => $usedBy !== [], 'used_by' => $usedBy];

                continue;
            }
            $plan['unchanged'][] = $entry['code'];
            if ($current->name !== $entry['name']) {
                $plan['name_drift'][] = ['code' => $entry['code'], 'current' => $current->name, 'dataset' => $entry['name']];
            }
        }
        $datasetCodes = array_flip(array_column($dataset->currencies, 'code'));
        foreach ($existing as $code => $row) {
            if (! isset($datasetCodes[$code])) {
                $plan['local_only'][] = $code;
            }
        }

        return $plan;
    }

    /**
     * Apply a plan: insert the missing currencies. `refreshNames` also updates descriptive names that differ; `correctUnused` also corrects the minor unit of a
     * currency that nothing references (a used currency is never corrected). Repeating the run changes nothing.
     *
     * @param  array<string, mixed>  $plan
     * @return array{inserted: int, names_refreshed: int, corrected: list<string>}
     */
    public function apply(array $plan, bool $refreshNames = false, bool $correctUnused = false): array
    {
        return DB::transaction(function () use ($plan, $refreshNames, $correctUnused): array {
            $inserted = 0;
            foreach ($plan['insert'] as $entry) {
                // The symbol is not part of ISO 4217; the code is the honest default until a display symbol is chosen.
                $created = Currency::query()->firstOrCreate(['code' => $entry['code']],
                    ['name' => $entry['name'], 'symbol' => $entry['code'], 'decimal_places' => $entry['minor_units']]);
                $inserted += $created->wasRecentlyCreated ? 1 : 0;
            }
            $renamed = 0;
            if ($refreshNames) {
                foreach ($plan['name_drift'] as $drift) {
                    $renamed += Currency::query()->where('code', $drift['code'])->update(['name' => $drift['dataset']]);
                }
            }
            $corrected = [];
            if ($correctUnused) {
                foreach ($plan['conflict'] as $conflict) {
                    if ($conflict['used']) {
                        continue;
                    }
                    Currency::query()->where('code', $conflict['code'])->update(['decimal_places' => $conflict['dataset']]);
                    $corrected[] = $conflict['code'];
                }
            }

            return ['inserted' => $inserted, 'names_refreshed' => $renamed, 'corrected' => $corrected];
        });
    }
}
