<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0097, slice 2: the Supplier Return (Purchasing) and Scrap (Inventory) documents. They are separate business documents with a draft → post lifecycle; a draft
 * records intent and moves nothing, posting calls the shared rejected-stock exit core. Once a document leaves DRAFT it is frozen by the database (its lines are
 * linked to their immutable exit records and nothing else may change), and a POSTED document must have an exit for every line, matching quantity and kind. A supplier
 * return's expected credit is information only: nothing here touches accounts payable, a supplier balance or the general ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->document('supplier_returns', 'supplier_return_lines', 'supplier_return_id', function (Blueprint $t): void {
            $t->unsignedBigInteger('supplier_account_id');
            $t->string('supplier_reference', 100)->nullable();
            $t->string('carrier_note', 255)->nullable();
            // Information only (ADR 0097 §5): what the supplier is expected to credit. Creates no credit note, balance or journal.
            $t->decimal('expected_credit_amount', 19, 4)->nullable();
            $t->unsignedBigInteger('expected_credit_currency_id')->nullable();
            $t->string('expected_credit_reference', 100)->nullable();
            $t->foreign(['company_id', 'supplier_account_id'])->references(['company_id', 'id'])->on('supplier_accounts');
            $t->foreign('expected_credit_currency_id')->references('id')->on('currencies');
        });
        DB::statement('ALTER TABLE supplier_returns ADD CONSTRAINT supplier_return_credit_shape CHECK ((expected_credit_amount IS NULL OR expected_credit_amount >= 0) AND (expected_credit_amount IS NULL OR expected_credit_currency_id IS NOT NULL))');
        $this->document('stock_scraps', 'stock_scrap_lines', 'stock_scrap_id', function (Blueprint $t): void {
            $t->string('disposal_method', 100)->nullable();
            $t->string('disposal_reference', 100)->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_exit_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'A return or scrap document is never deleted; cancel a draft instead'; END IF;
 IF OLD.status<>'DRAFT' THEN RAISE EXCEPTION 'A posted or cancelled document is immutable'; END IF;
 IF NEW.status NOT IN ('DRAFT','POSTED','CANCELLED') THEN RAISE EXCEPTION 'Unknown document status'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER supplier_return_guard BEFORE UPDATE OR DELETE ON supplier_returns FOR EACH ROW EXECUTE FUNCTION guard_exit_document();
CREATE TRIGGER stock_scrap_guard BEFORE UPDATE OR DELETE ON stock_scraps FOR EACH ROW EXECUTE FUNCTION guard_exit_document();

-- Lines may change only while their document is a draft, except that posting links each line to its exit record.
CREATE OR REPLACE FUNCTION guard_exit_document_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE header_status text;
BEGIN
 EXECUTE format('SELECT status FROM %I WHERE id=$1', TG_ARGV[0]) INTO header_status USING CASE WHEN TG_OP='DELETE' THEN to_jsonb(OLD)->>TG_ARGV[1] ELSE to_jsonb(OLD)->>TG_ARGV[1] END::bigint;
 IF header_status='DRAFT' THEN RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END; END IF;
 IF TG_OP='UPDATE' AND OLD.rejected_stock_exit_id IS NULL AND NEW.rejected_stock_exit_id IS NOT NULL
  AND to_jsonb(NEW)-'rejected_stock_exit_id' = to_jsonb(OLD)-'rejected_stock_exit_id' THEN RETURN NEW; END IF;
 RAISE EXCEPTION 'The lines of a posted or cancelled document are immutable';
END $$;
CREATE TRIGGER supplier_return_line_guard BEFORE UPDATE OR DELETE ON supplier_return_lines FOR EACH ROW EXECUTE FUNCTION guard_exit_document_line('supplier_returns','supplier_return_id');
CREATE TRIGGER stock_scrap_line_guard BEFORE UPDATE OR DELETE ON stock_scrap_lines FOR EACH ROW EXECUTE FUNCTION guard_exit_document_line('stock_scraps','stock_scrap_id');

-- A POSTED document has an exit for every line, of its own kind, delivery line and quantity, with a number.
CREATE OR REPLACE FUNCTION check_posted_exit_document() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE kind text; lines_table text; fk text; bad integer;
BEGIN
 IF NEW.status<>'POSTED' THEN RETURN NULL; END IF;
 kind := CASE TG_TABLE_NAME WHEN 'supplier_returns' THEN 'SUPPLIER_RETURN' ELSE 'SCRAP' END;
 lines_table := CASE TG_TABLE_NAME WHEN 'supplier_returns' THEN 'supplier_return_lines' ELSE 'stock_scrap_lines' END;
 fk := CASE TG_TABLE_NAME WHEN 'supplier_returns' THEN 'supplier_return_id' ELSE 'stock_scrap_id' END;
 IF NEW.number IS NULL OR NEW.posted_at IS NULL OR NEW.posted_by IS NULL THEN RAISE EXCEPTION 'A posted document is numbered and records who posted it and when'; END IF;
 EXECUTE format('SELECT count(*) FROM %I l LEFT JOIN rejected_stock_exits x ON x.id=l.rejected_stock_exit_id WHERE l.%I=$1 AND (x.id IS NULL OR x.kind<>$2 OR x.goods_receipt_line_id<>l.goods_receipt_line_id OR x.quantity<>l.quantity)', lines_table, fk) INTO bad USING NEW.id, kind;
 IF bad>0 THEN RAISE EXCEPTION 'Every line of a posted document needs its matching exit record'; END IF;
 EXECUTE format('SELECT count(*) FROM %I WHERE %I=$1', lines_table, fk) INTO bad USING NEW.id;
 IF bad=0 THEN RAISE EXCEPTION 'A posted document has at least one line'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER supplier_return_posted_check AFTER UPDATE ON supplier_returns DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_posted_exit_document();
CREATE CONSTRAINT TRIGGER stock_scrap_posted_check AFTER UPDATE ON stock_scraps DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_posted_exit_document();
SQL);
    }

    public function down(): void
    {
        foreach (['supplier_returns' => 'supplier_return_lines', 'stock_scraps' => 'stock_scrap_lines'] as $header => $lines) {
            if (DB::table($header)->exists()) {
                throw new RuntimeException('Return or scrap documents exist; restore a compatible application instead of discarding them.');
            }
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER supplier_return_posted_check ON supplier_returns;
DROP TRIGGER stock_scrap_posted_check ON stock_scraps;
DROP FUNCTION check_posted_exit_document();
DROP TRIGGER supplier_return_line_guard ON supplier_return_lines;
DROP TRIGGER stock_scrap_line_guard ON stock_scrap_lines;
DROP FUNCTION guard_exit_document_line();
DROP TRIGGER supplier_return_guard ON supplier_returns;
DROP TRIGGER stock_scrap_guard ON stock_scraps;
DROP FUNCTION guard_exit_document();
SQL);
        Schema::dropIfExists('stock_scrap_lines');
        Schema::dropIfExists('stock_scraps');
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
    }

    private function document(string $header, string $lines, string $foreignKey, callable $extra): void
    {
        Schema::create($header, function (Blueprint $t) use ($extra): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('warehouse_id');
            $t->string('number', 60)->nullable();
            $t->string('status', 12)->default('DRAFT');
            $t->text('reason');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('posted_by')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->timestamps();
            $extra($t);
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement("CREATE UNIQUE INDEX {$header}_number_unique ON {$header}(company_id,number) WHERE number IS NOT NULL");
        DB::statement("ALTER TABLE {$header} ADD CONSTRAINT {$header}_status_shape CHECK (status IN ('DRAFT','POSTED','CANCELLED') AND length(trim(reason))>0 AND ((status='POSTED') = (posted_at IS NOT NULL)) AND ((status='CANCELLED') = (cancelled_at IS NOT NULL)))");
        Schema::create($lines, function (Blueprint $t) use ($header, $foreignKey): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger($foreignKey);
            $t->unsignedInteger('line_no');
            $t->unsignedBigInteger('goods_receipt_line_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('location_id');
            $t->decimal('quantity', 19, 4);
            $t->text('outcome_change_reason')->nullable();
            // The declared physical portion for a grouped item (whole REJECTED sealed packages and loose quantity); posting proves it again.
            $t->jsonb('package_portion')->nullable();
            $t->unsignedBigInteger('rejected_stock_exit_id')->nullable();
            $t->timestamps();
            $t->unique([$foreignKey, 'line_no']);
            $t->unique([$foreignKey, 'goods_receipt_line_id']);
            $t->foreign(['company_id', $foreignKey])->references(['company_id', 'id'])->on($header);
            $t->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items');
            $t->foreign('goods_receipt_line_id')->references('id')->on('goods_receipt_lines');
            $t->foreign('location_id')->references('id')->on('locations');
            $t->foreign(['company_id', 'rejected_stock_exit_id'])->references(['company_id', 'id'])->on('rejected_stock_exits');
        });
        DB::statement("ALTER TABLE {$lines} ADD CONSTRAINT {$lines}_shape CHECK (quantity > 0 AND line_no > 0)");
    }
};
