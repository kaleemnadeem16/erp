<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulation_versions', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through formulation_id, see
            // docs/architecture/multi-tenancy.md "Child tables scope through
            // their parent".
            $table->foreignId('formulation_id')->constrained();
            $table->unsignedInteger('version_no');
            // NULL while DRAFT — set only on activation, see
            // ActivateFormulationVersion. Immutable once referenced by any
            // document per docs/domain/formulations.md; this phase's own
            // boundary is DRAFT (editable) vs ACTIVE/SUPERSEDED (frozen) —
            // see docs/phases/phase-3-estimating-core.md.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['formulation_id', 'version_no']);
        });

        // At most one ACTIVE version per formulation.
        DB::statement("CREATE UNIQUE INDEX formulation_versions_one_active ON formulation_versions (formulation_id) WHERE status = 'ACTIVE'");

        // A formulation's live/historical periods must never overlap — see
        // docs/architecture/database.md "Exclusion constraints for version
        // periods" (this table is its own worked example there). DRAFT rows
        // carry no effective_from yet and are excluded via the WHERE clause,
        // same as a partial index.
        DB::statement(<<<'SQL'
            ALTER TABLE formulation_versions ADD CONSTRAINT formulation_versions_no_overlap
            EXCLUDE USING gist (
                formulation_id WITH =,
                daterange(effective_from, effective_to, '[)') WITH &&
            ) WHERE (status <> 'DRAFT')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('formulation_versions');
    }
};
