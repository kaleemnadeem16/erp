<?php

declare(strict_types=1);

use App\Support\Integrity\HistoricalViolationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #107 / ADR 0180 addendum: the integrity rules of migrations 100000 and 300000-330000 accept the rows that pre-date them. This records, per rule, how many such rows existed when
 * the rule was brought in (counts only; no row content), so the number is retrievable later, and logs every non-zero count. `php artisan integrity:historical-violations` reports the
 * live count and `integrity:validate-constraints` validates a constraint once its count is zero. No data is changed.
 *
 * (The unique index of 100000 is not listed: it cannot be created over duplicates, so it never accepts a violating row.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_rule_baselines', function (Blueprint $t): void {
            $t->string('rule_key', 120)->primary();
            $t->string('table_name', 120);
            $t->unsignedInteger('violations_at_introduction');
            $t->timestampTz('recorded_at');
            $t->timestampTz('validated_at')->nullable();
        });

        foreach (HistoricalViolationRegistry::rules() as $key => $rule) {
            $count = HistoricalViolationRegistry::count($key);
            DB::table('integrity_rule_baselines')->insert([
                'rule_key' => $key,
                'table_name' => $rule['table'],
                'violations_at_introduction' => $count,
                'recorded_at' => now(),
                // A constraint that is already validated has, by definition, no violating row.
                'validated_at' => HistoricalViolationRegistry::constraintState($key) === 'validated' ? now() : null,
            ]);
            if ($count > 0) {
                Log::warning("integrity rule {$key}: {$count} existing row(s) in {$rule['table']} violate it; it guards new writes only. Run `php artisan integrity:historical-violations`.");
            }
        }
    }

    public function down(): void
    {
        // The baseline is a derived record (the live count can always be recomputed), not business evidence.
        Schema::dropIfExists('integrity_rule_baselines');
    }
};
