<?php

declare(strict_types=1);

use App\Support\Integrity\HistoricalViolationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #107 / ADR 0180 addendum, forward correction. The first version of migration 340000 set `validated_at` when no NOT VALID constraint of that NAME existed anywhere, so a rule
 * whose constraint was MISSING on its table was recorded as validated, and a rule whose constraint was validated was left unrecorded when a same-named NOT VALID constraint sat on
 * another table. Edited migration files do not reach databases that already ran the old one; this re-derives `validated_at` from the real pg_constraint state on the expected table
 * (HistoricalViolationRegistry::constraintState). A validated constraint keeps an existing timestamp (or gets one if none); a missing, NOT VALID or guard rule gets none. Counts and
 * recorded_at are never touched, no row is added or removed, and it is idempotent: on a fresh install (already correct) it changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (array_keys(HistoricalViolationRegistry::rules()) as $key) {
            $baseline = DB::table('integrity_rule_baselines')->where('rule_key', $key);
            if ($baseline->doesntExist()) {
                continue;
            }
            if (HistoricalViolationRegistry::constraintState($key) === 'validated') {
                $baseline->whereNull('validated_at')->update(['validated_at' => now()]);
            } else {
                $baseline->whereNotNull('validated_at')->update(['validated_at' => null]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the previous values were wrong by construction (and are derivable again), and no data was created or lost. Rolling back leaves the corrected state in place.
    }
};
