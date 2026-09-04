<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('accounting_timezone')->default('UTC');
            $table->string('backdating_horizon_mode')->default('CURRENT_AND_PREVIOUS_PERIOD');
            $table->string('backdating_posting_floor_subtype')->nullable();
            $table->date('backdating_posting_floor_fixed_date')->nullable();
            $table->unsignedSmallInteger('backdating_posting_floor_rolling_days')->nullable();
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_backdating_horizon_mode_check CHECK (backdating_horizon_mode IN ('CURRENT_AND_PREVIOUS_PERIOD', 'PERIOD_LOCK_ONLY', 'POSTING_FLOOR'))");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_backdating_posting_floor_subtype_check CHECK (backdating_posting_floor_subtype IN ('FIXED_DATE', 'ROLLING_FORMULA'))");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_backdating_posting_floor_rolling_days_check CHECK (backdating_posting_floor_rolling_days BETWEEN 1 AND 3650)');
        // subtype is set (to either allowed value) iff mode is POSTING_FLOOR; each subtype
        // requires exactly its own floor parameter and forbids the other. COALESCE(..., FALSE)
        // resolves the NULL-subtype case to FALSE explicitly — without it, a subtype IS NULL
        // comparison yields SQL NULL, which CHECK treats as passing, silently allowing a stray
        // fixed_date/rolling_days value to survive alongside a NULL subtype.
        DB::statement(<<<'SQL'
            ALTER TABLE companies ADD CONSTRAINT companies_backdating_posting_floor_shape_check CHECK (
                (backdating_posting_floor_subtype IS NOT NULL) = (backdating_horizon_mode = 'POSTING_FLOOR')
                AND (backdating_posting_floor_fixed_date IS NOT NULL) = COALESCE(backdating_posting_floor_subtype = 'FIXED_DATE', FALSE)
                AND (backdating_posting_floor_rolling_days IS NOT NULL) = COALESCE(backdating_posting_floor_subtype = 'ROLLING_FORMULA', FALSE)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_backdating_posting_floor_shape_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_backdating_posting_floor_rolling_days_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_backdating_posting_floor_subtype_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_backdating_horizon_mode_check');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'accounting_timezone', 'backdating_horizon_mode', 'backdating_posting_floor_subtype',
                'backdating_posting_floor_fixed_date', 'backdating_posting_floor_rolling_days',
            ]);
        });
    }
};
