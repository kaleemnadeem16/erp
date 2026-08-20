<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First migration needing an exclusion constraint — see the "btree_gist" check
 * recorded in docs/STATUS.md (available on dbvm, not yet installed on `erp`, a
 * trusted extension since PG13 so no superuser needed). Installed here rather
 * than in a separate migration since it has no independent reason to exist yet.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('OPEN');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::statement(
            'ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_starts_on_before_ends_on '.
            'CHECK (starts_on <= ends_on)'
        );

        // No two fiscal years for the same company may cover overlapping dates —
        // see docs/architecture/database.md "Exclusion constraints for version periods"
        // and docs/domain/general-ledger.md "Fiscal years and periods".
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_no_overlap
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
        Schema::dropIfExists('fiscal_years');
    }
};
