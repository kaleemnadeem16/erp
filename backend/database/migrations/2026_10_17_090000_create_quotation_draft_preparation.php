<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, QL1a (R5 slice QL1, backend only): what the first quotation Actions need and nothing that is not yet knowable.
 *
 *  - OPERATION RECEIPTS: one append-only receipt per (company, actor, key) for a governed quotation operation. The same key and intent replays the original
 *    result; a different intent under the same key is refused by the Action. The receipt names its family and revision and holds a versioned result reference.
 *  - ENTERED LINE MONEY (ADR 0012, R4): a unit price the seller typed, in the revision's currency, together with the pinned FX evidence and the base-currency
 *    unit price computed from it. The six money columns are all present or all absent, so an incomplete dual-money row cannot exist; a missing conversion
 *    refuses the money write in the Action while non-money edits stay possible. A price of exactly zero is an explicit price; no price is NULL (unknown).
 *  - MEASURES: the priced and stock quantity and unit and the conversion version, copied from source resolution. Only the Item's own stock unit is supported
 *    by the pricing kernel today (conversion version 1 = identity), so no package measure is stored.
 *  - A revision remembers the rounding policy its entered prices were prepared under (fingerprint, version, as-of date) so a later change can be noticed.
 *  - A revision's currency cannot change while any of its lines carries an entered price.
 * No tax, cost, candidate, number or issue column exists: those belong to later slices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotation_operation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 85);
            $t->string('operation', 40);
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('revision_id')->nullable();
            $t->char('payload_hash', 64);
            $t->timestampTz('completed_at');
            $t->jsonb('result');
            $t->unique(['company_id', 'actor_id', 'request_key'], 'sales_quotation_receipts_key_unique');
            $t->unique(['company_id', 'id'], 'sales_quotation_receipts_company_id_unique');
            $t->foreign(['company_id', 'actor_id'], 'sales_quotation_receipts_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'family_id'], 'sales_quotation_receipts_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'revision_id'], 'sales_quotation_receipts_revision_fk')->references(['company_id', 'id'])->on('quotation_revisions');
            $t->index(['company_id', 'family_id'], 'sales_quotation_receipts_by_family');
        });
        DB::statement("ALTER TABLE sales_quotation_operation_receipts ADD CONSTRAINT sales_quotation_operation_receipts_shape CHECK (
            length(trim(request_key)) > 0 AND length(trim(operation)) > 0 AND payload_hash ~ '^[0-9a-f]{64}\$'
        )");

        Schema::table('quotation_revisions', function (Blueprint $t): void {
            $t->char('rounding_policy_fingerprint', 64)->nullable();
            $t->unsignedInteger('rounding_policy_version_no')->nullable();
            $t->date('rounding_policy_as_of')->nullable();
        });
        DB::statement('ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_rounding_binding CHECK (
            (rounding_policy_fingerprint IS NULL) = (rounding_policy_as_of IS NULL)
            AND (rounding_policy_version_no IS NULL OR rounding_policy_fingerprint IS NOT NULL)
        )');

        Schema::table('quotation_draft_lines', function (Blueprint $t): void {
            $t->decimal('unit_price', 19, 4)->nullable();
            $t->unsignedBigInteger('price_currency_id')->nullable();
            $t->unsignedBigInteger('base_currency_id')->nullable();
            $t->decimal('base_unit_price', 19, 4)->nullable();
            $t->jsonb('fx_evidence')->nullable();
            $t->char('fx_fingerprint', 64)->nullable();
            $t->decimal('priced_quantity', 19, 4)->nullable();
            $t->unsignedBigInteger('priced_uom_id')->nullable();
            $t->decimal('stock_quantity', 19, 4)->nullable();
            $t->unsignedBigInteger('stock_uom_id')->nullable();
            $t->unsignedSmallInteger('conversion_version')->nullable();
            $t->foreign('price_currency_id', 'quotation_draft_lines_price_currency_fk')->references('id')->on('currencies');
            $t->foreign('base_currency_id', 'quotation_draft_lines_base_currency_fk')->references('id')->on('currencies');
            $t->foreign(['company_id', 'priced_uom_id'], 'quotation_draft_lines_priced_uom_fk')->references(['company_id', 'id'])->on('uoms');
            $t->foreign(['company_id', 'stock_uom_id'], 'quotation_draft_lines_stock_uom_fk')->references(['company_id', 'id'])->on('uoms');
        });
        DB::statement("ALTER TABLE quotation_draft_lines ADD CONSTRAINT quotation_draft_lines_entered_money CHECK (
            (unit_price IS NULL) = (price_currency_id IS NULL)
            AND (unit_price IS NULL) = (base_currency_id IS NULL)
            AND (unit_price IS NULL) = (base_unit_price IS NULL)
            AND (unit_price IS NULL) = (fx_evidence IS NULL)
            AND (unit_price IS NULL) = (fx_fingerprint IS NULL)
            AND (unit_price IS NULL OR (unit_price >= 0 AND base_unit_price >= 0 AND fx_fingerprint ~ '^[0-9a-f]{64}\$'))
        )");
        DB::statement('ALTER TABLE quotation_draft_lines ADD CONSTRAINT quotation_draft_lines_measures CHECK (
            (priced_quantity IS NULL) = (priced_uom_id IS NULL)
            AND (priced_quantity IS NULL) = (stock_quantity IS NULL)
            AND (priced_quantity IS NULL) = (stock_uom_id IS NULL)
            AND (priced_quantity IS NULL) = (conversion_version IS NULL)
            AND (priced_quantity IS NULL OR (item_id IS NOT NULL AND priced_quantity > 0 AND stock_quantity > 0 AND conversion_version >= 1))
        )');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_quotation_receipts_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Sales quotation operation receipts are append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER sales_quotation_receipts_append_only BEFORE UPDATE OR DELETE ON sales_quotation_operation_receipts FOR EACH ROW EXECUTE FUNCTION sales_quotation_receipts_append_only();

-- A line's entered price is in the revision's currency, and that currency cannot move underneath priced lines.
CREATE OR REPLACE FUNCTION quotation_draft_line_currency_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_currency bigint;
BEGIN
 IF NEW.price_currency_id IS NOT NULL THEN
  SELECT currency_id INTO rev_currency FROM quotation_revisions WHERE id = NEW.revision_id;
  IF rev_currency IS DISTINCT FROM NEW.price_currency_id THEN RAISE EXCEPTION 'An entered price is in the currency of its quotation' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_draft_line_currency_guard BEFORE INSERT OR UPDATE ON quotation_draft_lines FOR EACH ROW EXECUTE FUNCTION quotation_draft_line_currency_guard();

CREATE OR REPLACE FUNCTION quotation_revision_currency_lock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.currency_id <> OLD.currency_id AND EXISTS (SELECT 1 FROM quotation_draft_lines WHERE revision_id = OLD.id AND unit_price IS NOT NULL) THEN
  RAISE EXCEPTION 'The currency of a quotation cannot change while a line carries an entered price' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_revision_currency_lock BEFORE UPDATE ON quotation_revisions FOR EACH ROW EXECUTE FUNCTION quotation_revision_currency_lock();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase evidence: receipts and entered prices are the record of what was prepared and under which FX.
        if (Schema::hasTable('sales_quotation_operation_receipts')
            && (DB::table('sales_quotation_operation_receipts')->exists() || DB::table('quotation_draft_lines')->whereNotNull('unit_price')->exists())) {
            throw new RuntimeException('Quotation receipts or entered prices exist; rolling this migration back would erase their evidence. Plan a backout first.');
        }
        DB::statement('DROP TRIGGER IF EXISTS quotation_revision_currency_lock ON quotation_revisions');
        DB::statement('DROP TRIGGER IF EXISTS quotation_draft_line_currency_guard ON quotation_draft_lines');
        DB::statement('DROP TRIGGER IF EXISTS sales_quotation_receipts_append_only ON sales_quotation_operation_receipts');
        DB::statement('DROP FUNCTION IF EXISTS quotation_revision_currency_lock()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_draft_line_currency_guard()');
        DB::statement('DROP FUNCTION IF EXISTS sales_quotation_receipts_append_only()');
        DB::statement('ALTER TABLE quotation_draft_lines DROP CONSTRAINT IF EXISTS quotation_draft_lines_measures');
        DB::statement('ALTER TABLE quotation_draft_lines DROP CONSTRAINT IF EXISTS quotation_draft_lines_entered_money');
        Schema::table('quotation_draft_lines', function (Blueprint $t): void {
            $t->dropForeign('quotation_draft_lines_price_currency_fk');
            $t->dropForeign('quotation_draft_lines_base_currency_fk');
            $t->dropForeign('quotation_draft_lines_priced_uom_fk');
            $t->dropForeign('quotation_draft_lines_stock_uom_fk');
            $t->dropColumn(['unit_price', 'price_currency_id', 'base_currency_id', 'base_unit_price', 'fx_evidence', 'fx_fingerprint', 'priced_quantity', 'priced_uom_id',
                'stock_quantity', 'stock_uom_id', 'conversion_version']);
        });
        DB::statement('ALTER TABLE quotation_revisions DROP CONSTRAINT IF EXISTS quotation_revisions_rounding_binding');
        Schema::table('quotation_revisions', function (Blueprint $t): void {
            $t->dropColumn(['rounding_policy_fingerprint', 'rounding_policy_version_no', 'rounding_policy_as_of']);
        });
        Schema::dropIfExists('sales_quotation_operation_receipts');
    }
};
