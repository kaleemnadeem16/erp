<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('over_receipt_mode')->default('BLOCK');
            $table->decimal('over_receipt_tolerance_percent', 9, 4)->default(0);
            $table->boolean('purchase_order_required')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('number')->nullable();
            $table->unsignedBigInteger('supplier_account_id');
            $table->unsignedBigInteger('supplier_site_id')->nullable();
            $table->string('status')->default('DRAFT');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('fx_policy')->default('FLOATING');
            $table->decimal('fx_rate', 19, 9);
            $table->date('fx_rate_date');
            $table->string('fx_rate_type');
            $table->string('fx_rate_source');
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('item_id');
            $table->string('item_code')->nullable();
            $table->string('description');
            $table->string('supplier_item_code')->nullable();
            $table->decimal('ordered_qty', 19, 4);
            $table->unsignedBigInteger('purchase_uom_id');
            $table->decimal('ordered_stock_qty', 19, 4);
            $table->unsignedBigInteger('stock_uom_id');
            $table->decimal('uom_factor', 19, 9);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_amount', 19, 4);
            $table->decimal('base_unit_price', 19, 4);
            $table->decimal('base_discount_amount', 19, 4)->default(0);
            $table->decimal('base_tax_amount', 19, 4)->default(0);
            $table->decimal('base_line_amount', 19, 4);
            $table->date('expected_delivery_date')->nullable();
            $table->boolean('qa_required')->default(false);
            $table->string('over_receipt_mode')->nullable();
            $table->decimal('over_receipt_tolerance_percent', 9, 4)->nullable();
            $table->decimal('short_closed_stock_qty', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['purchase_order_id', 'line_number']);
        });

        DB::statement("ALTER TABLE purchasing_settings ADD CONSTRAINT purchasing_settings_mode_check CHECK (over_receipt_mode IN ('BLOCK', 'ALLOW_WITHIN_TOLERANCE'))");
        DB::statement('ALTER TABLE purchasing_settings ADD CONSTRAINT purchasing_settings_tolerance_check CHECK (over_receipt_tolerance_percent >= 0 AND over_receipt_tolerance_percent <= 100)');
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('DRAFT', 'ISSUED', 'CLOSED', 'CANCELLED'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_fx_policy_check CHECK (fx_policy IN ('FLOATING', 'FIXED'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_fx_rate_check CHECK (fx_rate > 0)');
        DB::statement('CREATE UNIQUE INDEX purchase_orders_company_number_unique ON purchase_orders (company_id, number) WHERE number IS NOT NULL');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_supplier_company_fk FOREIGN KEY (company_id, supplier_account_id) REFERENCES supplier_accounts (company_id, id)');
        DB::statement('ALTER TABLE supplier_sites ADD CONSTRAINT supplier_sites_supplier_account_id_id_unique UNIQUE (supplier_account_id, id)');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_supplier_site_fk FOREIGN KEY (supplier_account_id, supplier_site_id) REFERENCES supplier_sites (supplier_account_id, id)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_order_company_fk FOREIGN KEY (company_id, purchase_order_id) REFERENCES purchase_orders (company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_purchase_uom_company_fk FOREIGN KEY (company_id, purchase_uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_stock_uom_company_fk FOREIGN KEY (company_id, stock_uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_qty_check CHECK (ordered_qty > 0 AND ordered_stock_qty > 0 AND uom_factor > 0 AND short_closed_stock_qty >= 0)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_amount_check CHECK (unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_amount >= 0 AND base_unit_price >= 0 AND base_discount_amount >= 0 AND base_tax_amount >= 0 AND base_line_amount >= 0)');
        DB::statement("ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_mode_check CHECK (over_receipt_mode IS NULL OR over_receipt_mode IN ('BLOCK', 'ALLOW_WITHIN_TOLERANCE'))");
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_tolerance_check CHECK (over_receipt_tolerance_percent IS NULL OR (over_receipt_tolerance_percent >= 0 AND over_receipt_tolerance_percent <= 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        DB::statement('ALTER TABLE supplier_sites DROP CONSTRAINT supplier_sites_supplier_account_id_id_unique');
        Schema::dropIfExists('purchasing_settings');
    }
};
