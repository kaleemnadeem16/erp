<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0054's configurable material-variance control, the storage half:
 *
 *  - material_variance_reasons — the company's own reason list. Nothing is
 *    seeded here: reasons are company data, and the demo seeder supplies an
 *    example list only.
 *  - material_variance_policies — versioned, effective-dated policy per
 *    scope, resolved item → nearest category → company. Same lifecycle and
 *    constraints as overhead_policies (one ACTIVE per scope, no overlapping
 *    periods); the row id is the policy "version" an evaluation snapshots.
 *  - material_variance_policy_bands — the typed, bounded conditions of one
 *    policy: an over-plan or under-plan difference, measured as a
 *    quantity, a percentage or an internal-cost impact, at or beyond a
 *    threshold, with a control effect. The strongest matching effect wins.
 *
 * `scope_id` has no foreign key (it targets categories or items depending on
 * scope_type, NULL for COMPANY) and is validated at the request layer, as for
 * overhead_policies. The effect vocabulary keeps APPROVAL_REQUIRED so the
 * approval workflow (Q27) can light it up later without a schema change;
 * the application refuses to store or resolve it until then (ADR 0054 §7).
 * Waste/residual is deliberately not a measure yet — it needs package-level
 * tracking (ADR 0052) — and adding one later is a CHECK change only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_variance_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('material_variance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            // A floor under whatever the bands resolve to: an item or category the company
            // always wants at least explained, however small the difference.
            $table->string('minimum_effect')->nullable();
            $table->text('reason');
            $table->string('status')->default('DRAFT');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('material_variance_policy_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('policy_id');
            $table->string('direction');
            $table->string('measure');
            $table->decimal('threshold', 19, 6);
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->string('effect');
            $table->timestamps();
        });

        $effects = "'AUDIT_ONLY', 'REASON_REQUIRED', 'APPROVAL_REQUIRED', 'BLOCKED'";

        DB::statement("ALTER TABLE material_variance_policies ADD CONSTRAINT material_variance_policies_scope_check CHECK ((scope_type = 'COMPANY' AND scope_id IS NULL) OR (scope_type IN ('CATEGORY', 'ITEM') AND scope_id IS NOT NULL))");
        DB::statement("ALTER TABLE material_variance_policies ADD CONSTRAINT material_variance_policies_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'SUPERSEDED'))");
        DB::statement("ALTER TABLE material_variance_policies ADD CONSTRAINT material_variance_policies_minimum_effect_check CHECK (minimum_effect IS NULL OR minimum_effect IN ({$effects}))");
        DB::statement('ALTER TABLE material_variance_policies ADD CONSTRAINT material_variance_policies_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');

        // COALESCE(scope_id, 0): one COMPANY-scope identity (NULLs are otherwise all distinct).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX material_variance_policies_one_active
            ON material_variance_policies (company_id, scope_type, COALESCE(scope_id, 0))
            WHERE status = 'ACTIVE'
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE material_variance_policies ADD CONSTRAINT material_variance_policies_no_overlap
            EXCLUDE USING gist (
                company_id WITH =,
                scope_type WITH =,
                COALESCE(scope_id, 0) WITH =,
                daterange(effective_from, effective_to, '[)') WITH &&
            ) WHERE (status <> 'DRAFT')
            SQL);

        DB::statement("ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_direction_check CHECK (direction IN ('OVER', 'UNDER'))");
        DB::statement("ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_measure_check CHECK (measure IN ('QUANTITY', 'PERCENT', 'COST_AMOUNT', 'COST_PERCENT'))");
        DB::statement("ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_effect_check CHECK (effect IN ({$effects}))");
        DB::statement('ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_threshold_check CHECK (threshold >= 0)');
        // A quantity is only meaningful in a unit; the other measures carry none.
        DB::statement("ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_uom_check CHECK ((measure = 'QUANTITY') = (uom_id IS NOT NULL))");
        DB::statement('ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_policy_company_fk FOREIGN KEY (company_id, policy_id) REFERENCES material_variance_policies (company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE material_variance_policy_bands ADD CONSTRAINT material_variance_policy_bands_uom_fk FOREIGN KEY (uom_id) REFERENCES uoms (id)');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX material_variance_policy_bands_identity
            ON material_variance_policy_bands (policy_id, direction, measure, COALESCE(uom_id, 0), threshold)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('material_variance_policy_bands');
        Schema::dropIfExists('material_variance_policies');
        Schema::dropIfExists('material_variance_reasons');
    }
};
