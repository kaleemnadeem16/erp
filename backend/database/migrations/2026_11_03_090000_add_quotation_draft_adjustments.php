<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, PR4/PR5 (accepted R3 P5-P7, ADR 0116): the seller's discounts and final-net overrides on a DRAFT, stored as inputs with who entered them and when.
 *
 *  - LINE DISCOUNT (one slot): a kind (PERCENT, PER_PRICED_UNIT, PER_LINE) and a value, entered_by/at stamped by the Action. All four columns are present together or absent.
 *  - LINE FINAL-NET OVERRIDE: the amount the line settles to, a mandatory reason, entered_by/at. It replaces the calculated net, runs no discount afterwards (so a line holds a
 *    discount OR an override, never both) and needs an entered unit price, because the calculated net it replaces must exist to be retained.
 *  - APPROVAL NEEDED: a recorded fact (the approvals engine is not built): an override, or a discount that is not proven within a configured limit, says that approval is
 *    required and why. The reason is a code; no limit, threshold or cost is stored.
 *  - DOCUMENT DISCOUNT (one slot per revision): PERCENT or AMOUNT of the counted lines' net, its own table so a change moves the revision's input generation through the same
 *    trigger lines use (the revision guard compares header columns only, and an old migration is not edited).
 *  - ISSUED LINES keep the provenance of what reduced them (kind and amounts), so the customer document reconciles: quantity x unit price - reduction = amount.
 * Money is decimal(19,4). Every user reference is a composite company foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_draft_lines', function (Blueprint $t): void {
            $t->string('discount_kind', 16)->nullable();
            $t->decimal('discount_value', 19, 4)->nullable();
            $t->unsignedBigInteger('discount_entered_by')->nullable();
            $t->timestampTz('discount_entered_at')->nullable();
            $t->decimal('net_override', 19, 4)->nullable();
            $t->text('override_reason')->nullable();
            $t->unsignedBigInteger('override_entered_by')->nullable();
            $t->timestampTz('override_entered_at')->nullable();
            $t->boolean('approval_required')->default(false);
            $t->string('approval_reason', 40)->nullable();
            $t->foreign(['company_id', 'discount_entered_by'], 'quotation_draft_lines_discount_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'override_entered_by'], 'quotation_draft_lines_override_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_draft_lines ADD CONSTRAINT quotation_draft_lines_adjustments CHECK (
            (discount_kind IS NULL) = (discount_value IS NULL) AND (discount_kind IS NULL) = (discount_entered_by IS NULL) AND (discount_kind IS NULL) = (discount_entered_at IS NULL)
            AND (discount_kind IS NULL OR (discount_kind IN ('PERCENT','PER_PRICED_UNIT','PER_LINE') AND discount_value > 0 AND (discount_kind <> 'PERCENT' OR discount_value <= 100)))
            AND (net_override IS NULL) = (override_reason IS NULL) AND (net_override IS NULL) = (override_entered_by IS NULL) AND (net_override IS NULL) = (override_entered_at IS NULL)
            AND (net_override IS NULL OR (net_override >= 0 AND length(trim(override_reason)) > 0 AND unit_price IS NOT NULL))
            AND (discount_kind IS NULL OR net_override IS NULL)
            AND (approval_required = (approval_reason IS NOT NULL))
            AND (approval_reason IS NULL OR (discount_kind IS NOT NULL OR net_override IS NOT NULL))
        )");

        Schema::create('quotation_draft_document_discounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('revision_id');
            $t->string('kind', 8);
            $t->decimal('value', 19, 4);
            $t->unsignedBigInteger('entered_by');
            $t->timestampTz('entered_at');
            $t->boolean('approval_required')->default(false);
            $t->string('approval_reason', 40)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'revision_id'], 'quotation_document_discounts_revision_unique');
            $t->foreign(['company_id', 'revision_id'], 'quotation_document_discounts_revision_fk')->references(['company_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'entered_by'], 'quotation_document_discounts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_draft_document_discounts ADD CONSTRAINT quotation_document_discounts_shape CHECK (
            kind IN ('PERCENT','AMOUNT') AND value > 0 AND (kind <> 'PERCENT' OR value <= 100) AND (approval_required = (approval_reason IS NOT NULL))
        )");

        Schema::table('quotation_issued_lines', function (Blueprint $t): void {
            $t->string('line_reduction_kind', 10)->nullable();
            $t->decimal('line_reduction_amount', 19, 4)->default(0);
            $t->decimal('document_reduction_amount', 19, 4)->default(0);
        });
        DB::statement("ALTER TABLE quotation_issued_lines ADD CONSTRAINT quotation_issued_lines_reduction CHECK (
            line_reduction_amount >= 0 AND document_reduction_amount >= 0 AND (line_reduction_kind IS NULL OR line_reduction_kind IN ('DISCOUNT','OVERRIDE'))
            AND (line_reduction_kind IS NOT NULL OR line_reduction_amount = 0)
        )");

        // Reduction controls (the discount limits a concession is proven against) become publishable; margin and floor kinds are unchanged.
        DB::statement('ALTER TABLE quotation_net_controls DROP CONSTRAINT quotation_net_controls_shape');
        DB::statement("ALTER TABLE quotation_net_controls ADD CONSTRAINT quotation_net_controls_shape CHECK (
            kind IN ('FLOOR','MIN_MARGIN_AMOUNT','MIN_MARGIN_PERCENT','INPUT_REDUCTION_PERCENT','INPUT_REDUCTION_AMOUNT','AGGREGATE_REDUCTION_PERCENT','AGGREGATE_REDUCTION_AMOUNT')
            AND mode IN ('ADVISORY','MANDATORY') AND basis IN ('LINE','PER_UNIT')
            AND length(trim(control_ref)) > 0 AND control_version >= 1 AND limit_value >= 0
            AND ((kind IN ('MIN_MARGIN_PERCENT','INPUT_REDUCTION_PERCENT','AGGREGATE_REDUCTION_PERCENT') AND currency_id IS NULL AND limit_value <= 100)
                 OR (kind NOT IN ('MIN_MARGIN_PERCENT','INPUT_REDUCTION_PERCENT','AGGREGATE_REDUCTION_PERCENT') AND currency_id IS NOT NULL))
        )");

        DB::unprepared(<<<'SQL'
-- The document discount is a draft input like a line: only a DRAFT revision takes one, and every change moves the revision's input generation.
CREATE OR REPLACE FUNCTION quotation_document_discount_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text; rev_id bigint;
BEGIN
 rev_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.revision_id ELSE NEW.revision_id END;
 SELECT state INTO rev_state FROM quotation_revisions WHERE id = rev_id FOR UPDATE;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'A document discount can only change on a draft quotation' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'UPDATE' AND ROW(NEW.company_id, NEW.revision_id) IS DISTINCT FROM ROW(OLD.company_id, OLD.revision_id) THEN
  RAISE EXCEPTION 'A document discount keeps its revision' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 NEW.updated_at := now();
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_document_discount_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_draft_document_discounts FOR EACH ROW EXECUTE FUNCTION quotation_document_discount_guard();

CREATE OR REPLACE FUNCTION quotation_document_discount_generation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE quotation_revisions SET input_generation = input_generation + 1, updated_at = now() WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.revision_id ELSE NEW.revision_id END;
 RETURN NULL;
END $$;
CREATE TRIGGER quotation_document_discount_generation AFTER INSERT OR UPDATE OR DELETE ON quotation_draft_document_discounts FOR EACH ROW EXECUTE FUNCTION quotation_document_discount_generation();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase evidence: an entered discount or override, or a frozen reduction, is the record of what a seller conceded and who entered it.
        if (DB::table('quotation_draft_document_discounts')->exists() || DB::table('quotation_draft_lines')->where(fn ($q) => $q->whereNotNull('discount_kind')->orWhereNotNull('net_override'))->exists()
            || DB::table('quotation_issued_lines')->where(fn ($q) => $q->whereNotNull('line_reduction_kind')->orWhere('document_reduction_amount', '>', 0))->exists()) {
            throw new RuntimeException('Quotation discounts, overrides or frozen reductions exist; rolling this migration back would erase their evidence. Plan a backout first.');
        }
        if (DB::table('quotation_net_controls')->whereNotIn('kind', ['FLOOR', 'MIN_MARGIN_AMOUNT', 'MIN_MARGIN_PERCENT'])->exists()) {
            throw new RuntimeException('Published reduction controls exist; rolling this migration back would erase them. Plan a backout first.');
        }
        DB::statement('ALTER TABLE quotation_net_controls DROP CONSTRAINT quotation_net_controls_shape');
        DB::statement("ALTER TABLE quotation_net_controls ADD CONSTRAINT quotation_net_controls_shape CHECK (
            kind IN ('FLOOR','MIN_MARGIN_AMOUNT','MIN_MARGIN_PERCENT') AND mode IN ('ADVISORY','MANDATORY') AND basis IN ('LINE','PER_UNIT')
            AND length(trim(control_ref)) > 0 AND control_version >= 1 AND limit_value >= 0
            AND ((kind = 'MIN_MARGIN_PERCENT' AND currency_id IS NULL AND limit_value <= 100) OR (kind <> 'MIN_MARGIN_PERCENT' AND currency_id IS NOT NULL))
        )");
        DB::statement('DROP TRIGGER IF EXISTS quotation_document_discount_generation ON quotation_draft_document_discounts');
        DB::statement('DROP TRIGGER IF EXISTS quotation_document_discount_guard ON quotation_draft_document_discounts');
        DB::statement('DROP FUNCTION IF EXISTS quotation_document_discount_generation()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_document_discount_guard()');
        DB::statement('ALTER TABLE quotation_issued_lines DROP CONSTRAINT IF EXISTS quotation_issued_lines_reduction');
        Schema::table('quotation_issued_lines', fn (Blueprint $t) => $t->dropColumn(['line_reduction_kind', 'line_reduction_amount', 'document_reduction_amount']));
        Schema::dropIfExists('quotation_draft_document_discounts');
        DB::statement('ALTER TABLE quotation_draft_lines DROP CONSTRAINT IF EXISTS quotation_draft_lines_adjustments');
        Schema::table('quotation_draft_lines', function (Blueprint $t): void {
            $t->dropForeign('quotation_draft_lines_discount_actor_fk');
            $t->dropForeign('quotation_draft_lines_override_actor_fk');
            $t->dropColumn(['discount_kind', 'discount_value', 'discount_entered_by', 'discount_entered_at', 'net_override', 'override_reason', 'override_entered_by', 'override_entered_at',
                'approval_required', 'approval_reason']);
        });
    }
};
