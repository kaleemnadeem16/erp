<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored material selection strategy (ADR 0053 §2, ADR 0071): which of the three
 * presets an item, an item category or the whole company prefers when the Material
 * Preview proposes a stock draw. One row per change, kept forever — each carries the
 * reason and the actor, like overhead_policies — so who changed the preference, when
 * and why is always answerable, and an older preview is never reinterpreted.
 *
 * `strategy` NULL is an explicit "no preference here — inherit from the next level"
 * (how a setting is cleared while keeping the record of who cleared it and why).
 * scope_id is NULL iff scope_type = COMPANY and carries no FK, since it points at
 * items or categories depending on scope_type (validated at the request layer, the
 * same untyped reference overhead_policies uses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_selection_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('strategy')->nullable();
            $table->text('reason');
            $table->string('status')->default('ACTIVE');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE material_selection_policies ADD CONSTRAINT material_selection_policies_scope_check CHECK (scope_type IN ('COMPANY', 'ITEM_CATEGORY', 'ITEM') AND ((scope_type = 'COMPANY') = (scope_id IS NULL)))");
        DB::statement("ALTER TABLE material_selection_policies ADD CONSTRAINT material_selection_policies_strategy_check CHECK (strategy IS NULL OR strategy IN ('MINIMISE_WASTE', 'LOWEST_INTERNAL_COST', 'FEWEST_PACKAGES'))");
        DB::statement("ALTER TABLE material_selection_policies ADD CONSTRAINT material_selection_policies_status_check CHECK (status IN ('ACTIVE', 'SUPERSEDED') AND ((status = 'ACTIVE') = (effective_to IS NULL)))");
        DB::statement('ALTER TABLE material_selection_policies ADD CONSTRAINT material_selection_policies_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        // COALESCE(scope_id, 0): every COMPANY row shares one identity (NULLs are otherwise distinct in a unique index).
        DB::statement("CREATE UNIQUE INDEX material_selection_policies_one_active ON material_selection_policies (company_id, scope_type, COALESCE(scope_id, 0)) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('material_selection_policies');
    }
};
