<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('fiscal_year_id')->constrained();
            $table->unsignedSmallInteger('period_no');
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('OPEN');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_no']);
        });

        DB::statement(
            'ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_starts_on_before_ends_on '.
            'CHECK (starts_on <= ends_on)'
        );

        // Same overlap guarantee as fiscal_years — see docs/domain/general-ledger.md
        // "Fiscal years and periods". Containment within the parent fiscal_year's own
        // range can't be expressed as a same-table CHECK (it needs another table's
        // row), so that's an application-level guard — see AccountingPeriod::booted().
        DB::statement(<<<'SQL'
            ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_no_overlap
            EXCLUDE USING gist (
                company_id WITH =,
                daterange(starts_on, ends_on, '[]') WITH &&
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
