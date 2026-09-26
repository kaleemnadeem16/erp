<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * See docs/domain/assemblies.md "Schema" and "Cost roll-up" for the exact
 * shape and formula this table feeds. No OVERHEAD kind — it is computed
 * from the resolved overhead policy at roll-up time, never a stored row
 * here (ADR 0049).
 *
 * item_id/formulation_id/application_rate_id are all nullable and their
 * combination depends on kind — see StoreAssemblyComponentRequest for the
 * validated rules, not enforced at the schema level (same
 * plain-FK-not-composite shape as formulation_lines, since this table
 * carries no company_id of its own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_components', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through assembly_version_id ->
            // assembly_id, same as formulation_lines.
            $table->foreignId('assembly_version_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('formulation_id')->nullable();
            $table->unsignedBigInteger('application_rate_id')->nullable();
            $table->decimal('qty_per_work_unit', 19, 6);
            $table->unsignedBigInteger('uom_id');
            $table->decimal('wastage_pct', 9, 4)->default('0');
            $table->decimal('multiplier', 9, 4)->default('1');
            $table->decimal('rate_override', 19, 4)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('formulation_id')->references('id')->on('formulations');
            $table->foreign('application_rate_id')->references('id')->on('application_rates');
            $table->foreign('uom_id')->references('id')->on('uoms');
        });

        DB::statement('ALTER TABLE assembly_components ADD CONSTRAINT assembly_components_qty_check CHECK (qty_per_work_unit > 0)');
        DB::statement('ALTER TABLE assembly_components ADD CONSTRAINT assembly_components_wastage_check CHECK (wastage_pct >= 0)');
        DB::statement('ALTER TABLE assembly_components ADD CONSTRAINT assembly_components_multiplier_check CHECK (multiplier > 0)');
        DB::statement('ALTER TABLE assembly_components ADD CONSTRAINT assembly_components_rate_override_check CHECK (rate_override >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_components');
    }
};
