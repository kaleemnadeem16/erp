<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulation_lines', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through formulation_version_id ->
            // formulation_id. Owned entirely by its version: deleting the
            // version removes its own lines with it (only ever legal while
            // that version is DRAFT — see DeleteFormulationVersion).
            $table->foreignId('formulation_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('input_item_id');
            $table->decimal('qty', 19, 6);
            $table->unsignedBigInteger('uom_id');
            $table->decimal('wastage_pct', 9, 4)->default('0');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            // Plain FKs, not composite — formulation_lines carries no company_id
            // of its own (only formulations, the ultimate parent, does), so the
            // CompanyScopedForeignKeyTest composite-FK rule doesn't apply here;
            // company scoping for these references is enforced at the
            // application layer instead (Store/Update request validation with
            // CompanyScoped::exists()).
            $table->foreign('input_item_id')->references('id')->on('items');
            $table->foreign('uom_id')->references('id')->on('uoms');
        });

        DB::statement('ALTER TABLE formulation_lines ADD CONSTRAINT formulation_lines_qty_check CHECK (qty > 0)');
        DB::statement('ALTER TABLE formulation_lines ADD CONSTRAINT formulation_lines_wastage_check CHECK (wastage_pct >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('formulation_lines');
    }
};
