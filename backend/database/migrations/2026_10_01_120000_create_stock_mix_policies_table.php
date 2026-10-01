<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How tightly a company controls hand-chosen stock (ADR 0076): one row per change, kept forever, shaped exactly like
 * material_selection_policies (ADR 0071) so category and item scope can be added later without a data change. Only the
 * COMPANY scope can be set today (the API enforces it). `level` NULL is a recorded "no preference here — inherit".
 * `threshold_percent` is how many percent a hand-built mix may cost above the proposal before the stricter levels act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_mix_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('level')->nullable();
            $table->decimal('threshold_percent', 9, 4)->nullable();
            $table->text('reason');
            $table->string('status')->default('ACTIVE');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE stock_mix_policies ADD CONSTRAINT stock_mix_policies_scope_check CHECK (scope_type IN ('COMPANY', 'ITEM_CATEGORY', 'ITEM') AND ((scope_type = 'COMPANY') = (scope_id IS NULL)))");
        DB::statement("ALTER TABLE stock_mix_policies ADD CONSTRAINT stock_mix_policies_level_check CHECK (level IS NULL OR level IN ('NOT_ALLOWED', 'INFORMATION', 'REASON_REQUIRED', 'APPROVAL_REQUIRED'))");
        DB::statement("ALTER TABLE stock_mix_policies ADD CONSTRAINT stock_mix_policies_threshold_check CHECK ((level IN ('REASON_REQUIRED', 'APPROVAL_REQUIRED')) = (threshold_percent IS NOT NULL) AND (threshold_percent IS NULL OR threshold_percent > 0))");
        DB::statement("ALTER TABLE stock_mix_policies ADD CONSTRAINT stock_mix_policies_status_check CHECK (status IN ('ACTIVE', 'SUPERSEDED') AND ((status = 'ACTIVE') = (effective_to IS NULL)))");
        DB::statement('ALTER TABLE stock_mix_policies ADD CONSTRAINT stock_mix_policies_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::statement("CREATE UNIQUE INDEX stock_mix_policies_one_active ON stock_mix_policies (company_id, scope_type, COALESCE(scope_id, 0)) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mix_policies');
    }
};
