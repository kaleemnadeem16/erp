<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-phase integration, Batch 2 (ADR 0190): a quotation Assembly line adopts a Material Plan — the Phase 3 resolution of its pinned Assembly version into leaf material
 * requirements, the planned quantities the seller chose, the variance (ADR 0054) and hand-chosen-stock (ADR 0076) controls that judged them, and the approvals they need.
 * Four append-only tables hold it: `quotation_material_plans` (one adoption fact; a re-adoption is a new row naming the one it supersedes), `…_items` (one row per leaf
 * material, standard and planned quantity kept apart), `…_approvals` (the approval-engine subjects an adoption needs, one per controlled item and kind) and `…_revalidations`
 * (each deliberate re-check, appended). Internal-cost figures sit in protected columns that no Resource returns.
 *
 * Rows are written only while the revision is a DRAFT and never updated; they may be deleted only with their draft line (cascade) while the revision is still a DRAFT, so an issued
 * revision's plan can never change or disappear. `quotation_issued_lines.material_plan_id` freezes the line's current plan at issue, and the database refuses an issued line whose
 * plan is not the line's latest. ADDITIVE; down() refuses while an issued line names a plan. The shared insert guard is replaced (it keeps the Assembly-version check).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_material_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('line_id');
            $t->unsignedBigInteger('logical_scope_id');
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->unsignedBigInteger('assembly_version_id');
            $t->decimal('work_quantity', 19, 4);
            $t->unsignedBigInteger('work_uom_id');
            $t->jsonb('inputs');
            $t->string('effect', 20);
            $t->char('routing_fingerprint', 64);
            $t->unsignedBigInteger('adopted_by');
            $t->timestampTz('adopted_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'quotation_material_plans_company_id_unique');
            $t->unique(['company_id', 'revision_id', 'id'], 'quotation_material_plans_revision_key_unique');
            $t->unique(['company_id', 'supersedes_id'], 'quotation_material_plans_one_successor');
            $t->foreign(['company_id', 'revision_id', 'line_id', 'logical_scope_id'], 'quotation_material_plans_line_fk')->references(['company_id', 'revision_id', 'id', 'logical_scope_id'])->on('quotation_draft_lines')->cascadeOnDelete();
            $t->foreign(['company_id', 'supersedes_id'], 'quotation_material_plans_supersedes_fk')->references(['company_id', 'id'])->on('quotation_material_plans');
            $t->foreign('assembly_version_id', 'quotation_material_plans_version_fk')->references('id')->on('assembly_versions');
            $t->foreign(['company_id', 'work_uom_id'], 'quotation_material_plans_uom_fk')->references(['company_id', 'id'])->on('uoms');
            $t->foreign(['company_id', 'adopted_by'], 'quotation_material_plans_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_material_plans ADD CONSTRAINT quotation_material_plans_shape CHECK (
            work_quantity > 0 AND effect IN ('AUDIT_ONLY','REASON_REQUIRED','APPROVAL_REQUIRED') AND routing_fingerprint ~ '^[0-9a-f]{64}\$' AND (supersedes_id IS NULL OR supersedes_id <> id)
        )");

        Schema::create('quotation_material_plan_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('uom_id');
            $t->decimal('standard_quantity', 19, 9);
            $t->decimal('planned_quantity', 19, 9);
            $t->decimal('delta_quantity', 19, 9);
            $t->decimal('delta_percent', 19, 6)->nullable();
            $t->string('effect', 20)->nullable();
            $t->unsignedBigInteger('reason_id')->nullable();
            $t->text('reason_note')->nullable();
            $t->unsignedBigInteger('policy_id')->nullable();
            $t->jsonb('matched');
            $t->decimal('routing_amount', 19, 4)->nullable();
            $t->jsonb('mix')->nullable();
            $t->string('mix_level', 20)->nullable();
            $t->string('mix_status', 20)->nullable();
            $t->text('mix_note')->nullable();
            $t->decimal('mix_percent', 12, 2)->nullable();
            $t->decimal('mix_extra', 19, 4)->nullable();
            $t->unique(['company_id', 'id'], 'quotation_material_plan_items_company_id_unique');
            $t->unique(['plan_id', 'item_id'], 'quotation_material_plan_items_one_per_item');
            $t->foreign(['company_id', 'revision_id', 'plan_id'], 'quotation_material_plan_items_plan_fk')->references(['company_id', 'revision_id', 'id'])->on('quotation_material_plans')->cascadeOnDelete();
            $t->foreign(['company_id', 'item_id'], 'quotation_material_plan_items_item_fk')->references(['company_id', 'id'])->on('items');
            $t->foreign(['company_id', 'uom_id'], 'quotation_material_plan_items_uom_fk')->references(['company_id', 'id'])->on('uoms');
            $t->foreign(['company_id', 'reason_id'], 'quotation_material_plan_items_reason_fk')->references(['company_id', 'id'])->on('material_variance_reasons');
        });
        DB::statement("ALTER TABLE quotation_material_plan_items ADD CONSTRAINT quotation_material_plan_items_shape CHECK (
            standard_quantity >= 0 AND planned_quantity >= 0 AND delta_quantity = planned_quantity - standard_quantity
            AND (effect IS NULL OR effect IN ('AUDIT_ONLY','REASON_REQUIRED','APPROVAL_REQUIRED'))
            AND (effect IS NULL) = (delta_quantity = 0)
            AND (effect <> 'REASON_REQUIRED' OR reason_id IS NOT NULL)
            AND (mix IS NULL) = (mix_level IS NULL) AND (mix IS NULL) = (mix_status IS NULL)
        )");

        Schema::create('quotation_material_plan_approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('plan_item_id');
            $t->string('subject_code', 40);
            $t->jsonb('facts');
            $t->unique(['company_id', 'id'], 'quotation_material_plan_approvals_company_id_unique');
            $t->unique(['plan_item_id', 'subject_code'], 'quotation_material_plan_approvals_one_per_kind');
            $t->foreign(['company_id', 'plan_item_id'], 'quotation_material_plan_approvals_item_fk')->references(['company_id', 'id'])->on('quotation_material_plan_items')->cascadeOnDelete();
            $t->foreign(['company_id', 'revision_id', 'plan_id'], 'quotation_material_plan_approvals_plan_fk')->references(['company_id', 'revision_id', 'id'])->on('quotation_material_plans')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE quotation_material_plan_approvals ADD CONSTRAINT quotation_material_plan_approvals_shape CHECK (subject_code IN ('MATERIAL_VARIANCE','STOCK_MIX'))");

        Schema::create('quotation_material_plan_revalidations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('plan_id');
            $t->string('outcome', 12);
            $t->char('fingerprint', 64);
            $t->jsonb('change_codes');
            $t->unsignedBigInteger('checked_by');
            $t->timestampTz('checked_at', 6)->useCurrent();
            $t->foreign(['company_id', 'revision_id', 'plan_id'], 'quotation_material_plan_revalidations_plan_fk')->references(['company_id', 'revision_id', 'id'])->on('quotation_material_plans')->cascadeOnDelete();
            $t->foreign(['company_id', 'checked_by'], 'quotation_material_plan_revalidations_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_material_plan_revalidations ADD CONSTRAINT quotation_material_plan_revalidations_shape CHECK (outcome IN ('CURRENT','CHANGED') AND fingerprint ~ '^[0-9a-f]{64}\$')");

        Schema::table('quotation_issued_lines', function (Blueprint $t): void {
            $t->unsignedBigInteger('material_plan_id')->nullable();
            $t->foreign(['company_id', 'material_plan_id'], 'quotation_issued_lines_material_plan_fk')->references(['company_id', 'id'])->on('quotation_material_plans');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quotation_material_plan_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text; line record;
BEGIN
 IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME USING ERRCODE = '23514'; END IF;
 SELECT state INTO rev_state FROM quotation_revisions WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.revision_id ELSE NEW.revision_id END FOR SHARE;
 IF TG_OP = 'DELETE' THEN
  IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'A material plan of an issued or cancelled quotation is history and cannot be deleted' USING ERRCODE = '23514'; END IF;
  RETURN OLD;
 END IF;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'A material plan can only be adopted on a draft revision' USING ERRCODE = '23514'; END IF;
 IF TG_TABLE_NAME = 'quotation_material_plans' THEN
  SELECT * INTO line FROM quotation_draft_lines WHERE company_id = NEW.company_id AND revision_id = NEW.revision_id AND id = NEW.line_id;
  IF line.source_kind IS DISTINCT FROM 'ASSEMBLY_WORK' OR line.assembly_version_id IS DISTINCT FROM NEW.assembly_version_id
     OR line.offered_quantity IS DISTINCT FROM NEW.work_quantity OR line.offered_uom_id IS DISTINCT FROM NEW.work_uom_id THEN
   RAISE EXCEPTION 'A material plan is adopted for exactly its Assembly line, version and work quantity' USING ERRCODE = '23514';
  END IF;
  IF NEW.supersedes_id IS NULL THEN
   IF EXISTS (SELECT 1 FROM quotation_material_plans p WHERE p.line_id = NEW.line_id) THEN RAISE EXCEPTION 'A re-adoption names the plan it supersedes' USING ERRCODE = '23514'; END IF;
  ELSIF NOT EXISTS (SELECT 1 FROM quotation_material_plans p WHERE p.id = NEW.supersedes_id AND p.line_id = NEW.line_id AND p.revision_id = NEW.revision_id) THEN
   RAISE EXCEPTION 'A plan supersedes an earlier plan of the same line' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_material_plans_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_material_plans FOR EACH ROW EXECUTE FUNCTION quotation_material_plan_guard();
CREATE TRIGGER quotation_material_plan_items_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_material_plan_items FOR EACH ROW EXECUTE FUNCTION quotation_material_plan_guard();
CREATE TRIGGER quotation_material_plan_approvals_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_material_plan_approvals FOR EACH ROW EXECUTE FUNCTION quotation_material_plan_guard();
CREATE TRIGGER quotation_material_plan_revalidations_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_material_plan_revalidations FOR EACH ROW EXECUTE FUNCTION quotation_material_plan_guard();
SQL);
        DB::unprepared(self::issuedGuard(true));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_issued_lines, quotation_material_plans IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_issued_lines')->whereNotNull('material_plan_id')->exists()) {
            throw new RuntimeException('Issued quotation lines name their material plan: rolling back would erase the evidence they were issued on. Plan a separately approved backout first.');
        }
        DB::unprepared(self::issuedGuard(false));
        Schema::table('quotation_issued_lines', function (Blueprint $t): void {
            $t->dropForeign('quotation_issued_lines_material_plan_fk');
            $t->dropColumn('material_plan_id');
        });
        Schema::dropIfExists('quotation_material_plan_revalidations');
        Schema::dropIfExists('quotation_material_plan_approvals');
        Schema::dropIfExists('quotation_material_plan_items');
        Schema::dropIfExists('quotation_material_plans');
        DB::statement('DROP FUNCTION IF EXISTS quotation_material_plan_guard()');
    }

    private static function issuedGuard(bool $withPlan): string
    {
        $plan = $withPlan ? <<<'SQL'
 SELECT p.id INTO current_plan FROM quotation_material_plans p WHERE p.line_id = NEW.source_line_id AND NOT EXISTS (SELECT 1 FROM quotation_material_plans n WHERE n.supersedes_id = p.id);
 IF NEW.material_plan_id IS DISTINCT FROM current_plan THEN RAISE EXCEPTION 'An issued line freezes the current material plan of its draft line' USING ERRCODE = '23514'; END IF;
SQL : '';
        $declare = $withPlan ? 'DECLARE rev_state text; draft_version bigint; current_plan bigint;' : 'DECLARE rev_state text; draft_version bigint;';

        return <<<SQL
CREATE OR REPLACE FUNCTION quotation_issued_line_insert_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
{$declare}
BEGIN
 SELECT r.state INTO rev_state FROM quotation_revisions r WHERE r.id = NEW.revision_id;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'Issued lines are written with their issue, before the revision becomes issued' USING ERRCODE = '23514'; END IF;
 SELECT d.assembly_version_id INTO draft_version FROM quotation_draft_lines d WHERE d.company_id = NEW.company_id AND d.revision_id = NEW.revision_id AND d.id = NEW.source_line_id;
 IF NEW.assembly_version_id IS DISTINCT FROM draft_version THEN RAISE EXCEPTION 'An issued line freezes the Assembly version of its draft line' USING ERRCODE = '23514'; END IF;
{$plan}
 RETURN NEW;
END \$\$;
SQL;
    }
};
