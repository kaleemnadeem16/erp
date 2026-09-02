<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('supplier_account_id');
            $table->string('supplier_item_code')->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('minimum_order_qty', 19, 4)->nullable();
            $table->unsignedBigInteger('purchase_uom_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'item_id', 'supplier_account_id']);
        });

        DB::statement('ALTER TABLE item_suppliers ADD CONSTRAINT item_suppliers_priority_check CHECK (priority IS NULL OR priority >= 1)');
        DB::statement('ALTER TABLE item_suppliers ADD CONSTRAINT item_suppliers_moq_check CHECK (minimum_order_qty IS NULL OR minimum_order_qty > 0)');
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_company_id_id_unique UNIQUE (company_id, id)');
        DB::statement('ALTER TABLE item_suppliers ADD CONSTRAINT item_suppliers_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE item_suppliers ADD CONSTRAINT item_suppliers_supplier_company_fk FOREIGN KEY (company_id, supplier_account_id) REFERENCES supplier_accounts (company_id, id)');
        DB::statement('ALTER TABLE item_suppliers ADD CONSTRAINT item_suppliers_purchase_uom_company_fk FOREIGN KEY (company_id, purchase_uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('CREATE UNIQUE INDEX item_suppliers_one_active_preferred ON item_suppliers (company_id, item_id) WHERE active AND priority = 1');

        DB::statement(<<<'SQL'
            INSERT INTO item_suppliers (company_id, item_id, supplier_account_id, priority, active, created_at, updated_at)
            SELECT company_id, id, default_supplier_id, 1, true, NOW(), NOW()
            FROM items
            WHERE default_supplier_id IS NOT NULL
            SQL);

        DB::statement('ALTER TABLE items DROP CONSTRAINT items_default_supplier_company_fk');
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('default_supplier_id'));
    }

    public function down(): void
    {
        Schema::table('items', fn (Blueprint $table) => $table->unsignedBigInteger('default_supplier_id')->nullable());
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_default_supplier_company_fk FOREIGN KEY (company_id, default_supplier_id) REFERENCES supplier_accounts (company_id, id)');
        DB::statement(<<<'SQL'
            UPDATE items
            SET default_supplier_id = preferred.supplier_account_id
            FROM item_suppliers preferred
            WHERE preferred.company_id = items.company_id
              AND preferred.item_id = items.id
              AND preferred.active
              AND preferred.priority = 1
            SQL);
        Schema::dropIfExists('item_suppliers');
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_company_id_id_unique');
    }
};
