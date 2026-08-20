<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a gap left by docs/decisions/0024-onboarding-base-currency-and-fiscal-year.md
 * (accepted 2026-08-10): the columns it requires were never actually added to
 * companies. base_currency_id is nullable — no currency is seeded by default, so
 * a hard DB default would point nowhere; it's set at onboarding (not built yet).
 * fiscal_year_start_month gets the ADR's stated default (July) since that one is a
 * plain integer with no such dependency. Locking either after first use (per the
 * ADR) isn't enforced here — nothing yet exists to check against (no posted
 * transaction, no closed period) — same deferral as Item::valuation_method's lock.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('base_currency_id')->nullable()->after('name')->constrained('currencies');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7)->after('base_currency_id');
        });

        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_fiscal_year_start_month_between_1_and_12 '.
            'CHECK (fiscal_year_start_month BETWEEN 1 AND 12)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_currency_id');
            $table->dropColumn('fiscal_year_start_month');
        });
    }
};
