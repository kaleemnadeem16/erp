<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('inventory_location_mode')->default('SIMPLE');
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_inventory_location_mode_check CHECK (inventory_location_mode IN ('SIMPLE', 'STRUCTURED'))");

        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('type')->default('STORAGE');
            $table->string('barcode')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('allows_stock')->default(true);
            $table->unique(['warehouse_id', 'id']);
        });
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('STORAGE', 'RECEIVING', 'QUARANTINE', 'DISPATCH', 'RETURNS', 'TRANSIT', 'SCRAP', 'PRODUCTION', 'VIEW'))");
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_sort_order_check CHECK (sort_order >= 0)');
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_view_stock_check CHECK (type <> 'VIEW' OR allows_stock = false)");
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_parent_warehouse_fk FOREIGN KEY (warehouse_id, parent_id) REFERENCES locations (warehouse_id, id)');
        DB::statement('CREATE UNIQUE INDEX locations_warehouse_barcode_unique ON locations (warehouse_id, barcode) WHERE barcode IS NOT NULL');

        Schema::create('warehouse_location_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id');
            $table->string('role');
            $table->timestamps();

            $table->unique(['company_id', 'warehouse_id', 'role']);
        });
        DB::statement("ALTER TABLE warehouse_location_roles ADD CONSTRAINT warehouse_location_roles_role_check CHECK (role IN ('RECEIVING', 'QUARANTINE', 'DISPATCH', 'RETURNS', 'TRANSIT', 'PRODUCTION'))");
        DB::statement('ALTER TABLE warehouse_location_roles ADD CONSTRAINT warehouse_location_roles_warehouse_company_fk FOREIGN KEY (company_id, warehouse_id) REFERENCES warehouses (company_id, id)');
        DB::statement('ALTER TABLE warehouse_location_roles ADD CONSTRAINT warehouse_location_roles_location_warehouse_fk FOREIGN KEY (warehouse_id, location_id) REFERENCES locations (warehouse_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_location_roles');

        DB::statement('DROP INDEX IF EXISTS locations_warehouse_barcode_unique');
        DB::statement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS locations_parent_warehouse_fk');
        DB::statement('ALTER TABLE locations DROP CONSTRAINT locations_warehouse_id_id_unique');
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['parent_id', 'type', 'barcode', 'sort_order', 'allows_stock']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('inventory_location_mode');
        });
    }
};
