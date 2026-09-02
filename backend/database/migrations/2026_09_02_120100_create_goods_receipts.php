<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('number')->nullable();
            $table->unsignedBigInteger('supplier_account_id');
            $table->string('status')->default('DRAFT');
            $table->timestampTz('physical_arrival_at');
            $table->timestampTz('recorded_at');
            $table->date('posting_date');
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('received_qty', 19, 4);
            $table->unsignedBigInteger('received_uom_id');
            $table->decimal('stock_qty', 19, 4);
            $table->unsignedBigInteger('stock_uom_id');
            $table->decimal('uom_factor', 19, 9);
            $table->decimal('provisional_unit_cost', 19, 4);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('fx_rate', 19, 9);
            $table->decimal('base_unit_cost', 19, 4);
            $table->date('fx_rate_date');
            $table->string('fx_rate_type');
            $table->string('fx_rate_source');
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
            $table->unique(['goods_receipt_id', 'id']);
            $table->index(['goods_receipt_id', 'purchase_order_line_id']);
        });

        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_status_check CHECK (status IN ('DRAFT', 'POSTED', 'REVERSED'))");
        DB::statement('CREATE UNIQUE INDEX goods_receipts_company_number_unique ON goods_receipts (company_id, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX goods_receipts_company_idempotency_unique ON goods_receipts (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_supplier_company_fk FOREIGN KEY (company_id, supplier_account_id) REFERENCES supplier_accounts (company_id, id)');
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_reversal_company_fk FOREIGN KEY (company_id, reversal_of_id) REFERENCES goods_receipts (company_id, id)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_po_line_fk FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines (id)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_item_fk FOREIGN KEY (item_id) REFERENCES items (id)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_received_uom_fk FOREIGN KEY (received_uom_id) REFERENCES uoms (id)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_stock_uom_fk FOREIGN KEY (stock_uom_id) REFERENCES uoms (id)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_qty_check CHECK (received_qty > 0 AND stock_qty > 0 AND uom_factor > 0 AND provisional_unit_cost >= 0 AND base_unit_cost >= 0 AND fx_rate > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_goods_receipt_line_company() RETURNS trigger AS $$
            DECLARE receipt_company bigint;
            BEGIN
                SELECT company_id INTO receipt_company FROM goods_receipts WHERE id = NEW.goods_receipt_id;
                IF NOT EXISTS (SELECT 1 FROM purchase_order_lines WHERE id = NEW.purchase_order_line_id AND company_id = receipt_company)
                   OR NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = receipt_company)
                   OR NOT EXISTS (SELECT 1 FROM uoms WHERE id = NEW.received_uom_id AND company_id = receipt_company)
                   OR NOT EXISTS (SELECT 1 FROM uoms WHERE id = NEW.stock_uom_id AND company_id = receipt_company)
                   OR NOT EXISTS (
                       SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id
                       WHERE l.id = NEW.location_id AND w.company_id = receipt_company
                   )
                THEN RAISE EXCEPTION 'goods receipt line references another company' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER goods_receipt_lines_company BEFORE INSERT OR UPDATE ON goods_receipt_lines
            FOR EACH ROW EXECUTE FUNCTION enforce_goods_receipt_line_company();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS enforce_goods_receipt_line_company() CASCADE');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
