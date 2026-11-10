<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Integrity\HistoricalViolationRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0180 addendum. The explicit, operator-run step that turns a NOT VALID constraint into a validated one once no violating row remains. It refuses while any row violates the rule
 * and never changes or deletes data; VALIDATE CONSTRAINT only reads (it holds a lock that lets normal reads and writes continue).
 */
final class ValidateIntegrityConstraints extends Command
{
    protected $signature = 'integrity:validate-constraints {rule? : One rule key; omit for every constraint that is not validated yet}';

    protected $description = 'Validate NOT VALID integrity constraints whose historical violations are gone (refuses while any remain)';

    public function handle(): int
    {
        $keys = $this->argument('rule') !== null ? [(string) $this->argument('rule')] : array_keys(HistoricalViolationRegistry::rules());
        $failed = false;
        foreach ($keys as $key) {
            try {
                $rule = HistoricalViolationRegistry::rule($key);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if ($rule['constraint'] === null) {
                $this->line("{$key}: insert guard; it never re-judges old rows, nothing to validate.");

                continue;
            }
            if (! HistoricalViolationRegistry::isNotValidated($key)) {
                $this->line("{$key}: already validated.");

                continue;
            }
            $remaining = HistoricalViolationRegistry::count($key);
            if ($remaining > 0) {
                $this->error("{$key}: {$remaining} violating row(s) remain in {$rule['table']}; not validated. See integrity:historical-violations.");
                $failed = true;

                continue;
            }
            try {
                DB::statement("ALTER TABLE {$rule['table']} VALIDATE CONSTRAINT {$rule['constraint']}");
            } catch (QueryException $e) {
                // A row written between the count and the validation (the constraint refuses new ones, so this is only a race with a pre-existing writer).
                $this->error("{$key}: the database refused validation; run integrity:historical-violations.");
                $failed = true;

                continue;
            }
            DB::table('integrity_rule_baselines')->where('rule_key', $key)->update(['validated_at' => now()]);
            $this->info("{$key}: validated.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
