<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncCurrencies as SyncCurrenciesAction;
use App\Support\Currency\IsoCurrencyDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * `erp:sync-currencies` — the deploy-time synchronisation of the global currency reference data with the ISO 4217 dataset (ADR 0143).
 *
 * Dry run by default: it reads, reports and writes nothing. `--apply` needs a named `--operator` and a `--reason`, runs inside one transaction, and writes an
 * audit record. It never edits a used currency's minor unit; a conflict is reported and the command exits 3 so a deploy script or operator cannot miss it.
 */
final class SyncCurrencies extends Command
{
    protected $signature = 'erp:sync-currencies
        {--apply : Write the changes (without it the command only reports)}
        {--operator= : Who is running this (required with --apply)}
        {--reason= : Why it is being run, e.g. a release or ticket reference (required with --apply)}
        {--refresh-names : With --apply, also update descriptive names that differ from the dataset}
        {--correct-unused : With --apply, also correct the minor unit of a currency nothing references (a used currency is never corrected)}
        {--dataset= : Path to an alternative dataset file (for review of a proposed update)}
        {--json : Print the report as JSON}';

    /** Exit status when a conflict needs review (distinct from invalid usage, which is 2). */
    public const CONFLICTS = 3;

    protected $description = 'Synchronise the global currency reference data with the ISO 4217 dataset (dry run by default)';

    public function handle(SyncCurrenciesAction $sync): int
    {
        $apply = (bool) $this->option('apply');
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));
        if ($apply && ($operator === '' || $reason === '')) {
            $this->error('--apply requires both --operator and --reason, so the change can be attributed.');

            return self::INVALID;
        }
        if (! $apply && ($this->option('refresh-names') || $this->option('correct-unused'))) {
            $this->error('--refresh-names and --correct-unused only take effect with --apply.');

            return self::INVALID;
        }

        try {
            $dataset = IsoCurrencyDataset::load($this->option('dataset') ?: null);
        } catch (RuntimeException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        $plan = $sync->plan($dataset);
        $result = null;
        if ($apply) {
            $result = $sync->apply($plan, (bool) $this->option('refresh-names'), (bool) $this->option('correct-unused'));
            $this->audit($operator, $reason, $dataset, $plan, $result);
        }

        $this->report($plan, $result, $apply);

        // A conflict that --correct-unused just resolved no longer needs review.
        $corrected = $result['corrected'] ?? [];
        $outstanding = array_filter($plan['conflict'], fn (array $conflict): bool => ! in_array($conflict['code'], $corrected, true));

        return $outstanding === [] ? self::SUCCESS : self::CONFLICTS;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     */
    private function audit(string $operator, string $reason, IsoCurrencyDataset $dataset, array $plan, array $result): void
    {
        $properties = [
            'operator' => $operator, 'reason' => $reason, 'dataset_version' => $dataset->version,
            'os_user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : get_current_user(),
            'host' => gethostname() ?: null, 'environment' => app()->environment(),
            'inserted' => $result['inserted'], 'names_refreshed' => $result['names_refreshed'], 'corrected' => $result['corrected'],
            'conflicts' => array_column($plan['conflict'], 'code'), 'unsupported' => count($plan['unsupported']),
        ];
        activity('currency-reference')->withProperties($properties)->log('currency reference data synchronised');
        Log::info('currency reference data synchronised', $properties);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $result
     */
    private function report(array $plan, ?array $result, bool $apply): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'plan' => $plan, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->line($apply ? 'APPLIED — dataset '.$plan['dataset_version'] : 'DRY RUN — nothing was written — dataset '.$plan['dataset_version']);
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'inserted' : 'would insert', $apply ? $result['inserted'] : count($plan['insert'])],
            ['unchanged', count($plan['unchanged'])],
            ['name differs (informational)', count($plan['name_drift'])],
            ['CONFLICT (minor unit differs; not applied)', count($plan['conflict'])],
            ['unsupported by the platform', count($plan['unsupported'])],
            ['local only (not in the dataset)', count($plan['local_only'])],
        ]);
        foreach ($plan['conflict'] as $conflict) {
            $this->warn("CONFLICT {$conflict['code']}: stored {$conflict['current']} places, dataset {$conflict['dataset']}; "
                .($conflict['used'] ? 'IN USE ('.implode(', ', $conflict['used_by']).') — needs a recorded review, never changed by this command' : 'unused — re-run with --apply --correct-unused after review'));
        }
        if ($plan['unsupported'] !== []) {
            $this->line('Unsupported: '.implode(', ', array_column($plan['unsupported'], 'code')));
        }
        if ($plan['local_only'] !== []) {
            $this->line('Local only: '.implode(', ', $plan['local_only']));
        }
        if (! $apply && $plan['insert'] !== []) {
            $this->line('Run with --apply --operator="<name>" --reason="<reference>" to insert the missing currencies.');
        }
    }
}
