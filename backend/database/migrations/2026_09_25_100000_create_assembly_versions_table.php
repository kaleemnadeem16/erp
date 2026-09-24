<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 of docs/phases/phase-3-estimating-core.md's Assembly plan — the
 * remaining Assembly slice. Same shape as formulation_versions exactly
 * (see that migration's docblock) — DRAFT is the only editable status,
 * ACTIVE/SUPERSEDED are frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_versions', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through assembly_id, see
            // docs/architecture/multi-tenancy.md "Child tables scope through
            // their parent".
            $table->foreignId('assembly_id')->constrained();
            $table->unsignedInteger('version_no');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['assembly_id', 'version_no']);
        });

        // At most one ACTIVE version per assembly.
        DB::statement("CREATE UNIQUE INDEX assembly_versions_one_active ON assembly_versions (assembly_id) WHERE status = 'ACTIVE'");

        // Same exclusion-constraint pattern as formulation_versions — see
        // docs/architecture/database.md "Exclusion constraints for version
        // periods".
        DB::statement(<<<'SQL'
            ALTER TABLE assembly_versions ADD CONSTRAINT assembly_versions_no_overlap
            EXCLUDE USING gist (
                assembly_id WITH =,
                daterange(effective_from, effective_to, '[)') WITH &&
            ) WHERE (status <> 'DRAFT')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_versions');
    }
};
