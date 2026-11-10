<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Integrity\HistoricalViolationRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** ADR 0180 addendum. Read-only: counts rows that pre-date an integrity rule and break it. Output is counts and (with --ids) row ids; never row content. */
final class ListHistoricalIntegrityViolations extends Command
{
    protected $signature = 'integrity:historical-violations {--ids : Also list up to --limit row ids per rule} {--limit=20 : Most ids to list per rule} {--json : Machine-readable output}';

    protected $description = 'Read-only: count existing rows that violate the newer integrity rules (non-zero exit while any remain)';

    public function handle(): int
    {
        $report = DB::transaction(function (): array {
            // A consistent, read-only snapshot (the level can only be set at the start of a top-level transaction; an outer one, as in tests, is the caller's).
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $baselines = DB::table('integrity_rule_baselines')->get()->keyBy('rule_key');
            $rows = [];
            foreach (HistoricalViolationRegistry::rules() as $key => $rule) {
                $baseline = $baselines->get($key);
                $rows[] = [
                    'rule' => $key,
                    'table' => $rule['table'],
                    'enforcement' => $rule['constraint'] === null ? 'insert guard' : (HistoricalViolationRegistry::isNotValidated($key) ? 'constraint, not validated' : 'constraint, validated'),
                    'at_introduction' => $baseline === null ? null : (int) $baseline->violations_at_introduction,
                    'current' => HistoricalViolationRegistry::count($key),
                    'ids' => $this->option('ids') ? HistoricalViolationRegistry::ids($key, (int) $this->option('limit')) : null,
                ];
            }

            return $rows;
        });

        $remaining = array_sum(array_column($report, 'current'));
        if ($this->option('json')) {
            $this->line((string) json_encode(['remaining' => $remaining, 'rules' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Rule', 'Table', 'Enforcement', 'At introduction', 'Current'], array_map(fn (array $r): array => [$r['rule'], $r['table'], $r['enforcement'], $r['at_introduction'] ?? 'n/a', $r['current']], $report));
            foreach ($report as $r) {
                if ($r['ids'] !== null && $r['ids'] !== []) {
                    $this->line("{$r['rule']} ids: ".implode(', ', $r['ids']));
                }
            }
            $this->line($remaining === 0 ? 'No historical violations remain.' : "{$remaining} historical violation(s) remain; review them (nothing is repaired or deleted automatically).");
        }

        return $remaining === 0 ? self::SUCCESS : self::FAILURE;
    }
}
