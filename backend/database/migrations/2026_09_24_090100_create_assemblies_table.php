<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of the Phase 3 Assembly slice — identity only. No assembly_versions
 * or assembly_components yet (Step 3); this table exists so Step 2's
 * overhead_policies has a real ASSEMBLY/ASSEMBLY_GROUP scope target to
 * resolve against instead of a placeholder — see
 * docs/phases/phase-3-estimating-core.md and
 * docs/decisions/0049-assembly-group-overhead-scope.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assemblies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('work_uom_id');
            // NULLABLE — the Assembly Group override mechanism, see ADR 0049.
            $table->unsignedBigInteger('assembly_group_id')->nullable();
            $table->string('margin_type');
            $table->decimal('margin_pct', 9, 4);
            $table->string('status')->default('DRAFT');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // uoms_company_id_id_unique already exists
        // (2026_08_21_090000_enforce_company_scoped_foreign_keys.php).
        DB::statement('ALTER TABLE assemblies ADD CONSTRAINT assemblies_margin_pct_check CHECK (margin_pct >= 0)');
        DB::statement('ALTER TABLE assemblies ADD CONSTRAINT assemblies_work_uom_company_fk FOREIGN KEY (company_id, work_uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('ALTER TABLE assemblies ADD CONSTRAINT assemblies_group_company_fk FOREIGN KEY (company_id, assembly_group_id) REFERENCES assembly_groups (company_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assemblies');
    }
};
