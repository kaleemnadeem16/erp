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
            $table->string('default_avco_pool_scope')->default('COMPANY_ITEM');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('avco_pool_scope')->nullable();
        });
        DB::statement("UPDATE items SET avco_pool_scope = 'COMPANY_ITEM' WHERE valuation_method = 'AVCO'");

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->string('code');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'item_id', 'code']);
        });

        Schema::create('avco_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->string('scope');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->decimal('qty_on_hand', 19, 4)->default(0);
            $table->decimal('inventory_value', 19, 4)->default(0);
            $table->decimal('avg_unit_cost', 19, 4)->default(0);
            $table->decimal('base_avg_unit_cost', 19, 4)->default(0);
            $table->decimal('last_valid_avg_unit_cost', 19, 4)->default(0);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('stock_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->decimal('qty', 19, 4);
            $table->unsignedBigInteger('uom_id');
            $table->timestampTz('moved_at');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->decimal('unit_cost', 19, 4);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('fx_rate', 19, 9);
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'item_id', 'moved_at']);
            $table->index(['company_id', 'to_location_id', 'item_id']);
            $table->index(['company_id', 'from_location_id', 'item_id']);
        });

        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('qty_received', 19, 4);
            $table->decimal('qty_remaining', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('fx_rate', 19, 9);
            $table->decimal('base_unit_cost', 19, 4);
            $table->timestampTz('received_at');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'item_id', 'received_at']);
        });
        DB::statement('CREATE INDEX stock_layers_open_fifo ON stock_layers (company_id, item_id, received_at, id) WHERE qty_remaining > 0');
        DB::statement('CREATE UNIQUE INDEX stock_layers_active_serial_unique ON stock_layers (company_id, item_id, serial_no) WHERE serial_no IS NOT NULL AND qty_remaining > 0');

        Schema::create('negative_stock_shortages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('avco_pool_id')->nullable();
            $table->decimal('qty_open', 19, 4);
            $table->decimal('provisional_unit_cost', 19, 4);
            $table->string('status')->default('OPEN');
            $table->unsignedBigInteger('source_move_id');
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('stock_valuation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_move_id')->constrained('stock_moves')->restrictOnDelete();
            $table->unsignedBigInteger('avco_pool_id')->nullable();
            $table->string('pool_effect')->nullable();
            $table->decimal('qty', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('amount', 19, 4);
            $table->decimal('base_amount', 19, 4);
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->timestamps();
            $table->index(['stock_move_id', 'pool_effect']);
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_default_avco_pool_scope_check CHECK (default_avco_pool_scope IN ('COMPANY_ITEM', 'WAREHOUSE_ITEM', 'COMPANY_ITEM_BATCH'))");
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_avco_pool_scope_check CHECK ((valuation_method = 'AVCO' AND avco_pool_scope IN ('COMPANY_ITEM', 'WAREHOUSE_ITEM', 'COMPANY_ITEM_BATCH')) OR (valuation_method <> 'AVCO' AND avco_pool_scope IS NULL))");
        DB::statement('ALTER TABLE stock_batches ADD CONSTRAINT stock_batches_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement("ALTER TABLE avco_pools ADD CONSTRAINT avco_pools_scope_check CHECK ((scope = 'COMPANY_ITEM' AND warehouse_id IS NULL AND batch_id IS NULL) OR (scope = 'WAREHOUSE_ITEM' AND warehouse_id IS NOT NULL AND batch_id IS NULL) OR (scope = 'COMPANY_ITEM_BATCH' AND warehouse_id IS NULL AND batch_id IS NOT NULL))");
        DB::statement("ALTER TABLE avco_pools ADD CONSTRAINT avco_pools_status_check CHECK (status IN ('ACTIVE', 'FROZEN'))");
        DB::statement('ALTER TABLE avco_pools ADD CONSTRAINT avco_pools_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE avco_pools ADD CONSTRAINT avco_pools_warehouse_company_fk FOREIGN KEY (company_id, warehouse_id) REFERENCES warehouses (company_id, id)');
        DB::statement('ALTER TABLE avco_pools ADD CONSTRAINT avco_pools_batch_company_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement("CREATE UNIQUE INDEX avco_pools_company_item_unique ON avco_pools (company_id, item_id) WHERE scope = 'COMPANY_ITEM'");
        DB::statement("CREATE UNIQUE INDEX avco_pools_warehouse_item_unique ON avco_pools (company_id, item_id, warehouse_id) WHERE scope = 'WAREHOUSE_ITEM'");
        DB::statement("CREATE UNIQUE INDEX avco_pools_company_item_batch_unique ON avco_pools (company_id, item_id, batch_id) WHERE scope = 'COMPANY_ITEM_BATCH'");
        DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_uom_company_fk FOREIGN KEY (company_id, uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_batch_company_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_shape_check CHECK (qty > 0 AND fx_rate > 0 AND (from_location_id IS NOT NULL OR to_location_id IS NOT NULL) AND from_location_id IS DISTINCT FROM to_location_id)');
        DB::statement('ALTER TABLE stock_layers ADD CONSTRAINT stock_layers_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE stock_layers ADD CONSTRAINT stock_layers_batch_company_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE stock_layers ADD CONSTRAINT stock_layers_qty_check CHECK (qty_received > 0 AND qty_remaining >= 0 AND qty_remaining <= qty_received AND fx_rate > 0)');
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_batch_company_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_pool_company_fk FOREIGN KEY (company_id, avco_pool_id) REFERENCES avco_pools (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_move_company_fk FOREIGN KEY (company_id, source_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement("ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_status_check CHECK (status IN ('OPEN', 'PARTIALLY_SETTLED', 'SETTLED', 'OPEN_CUTOVER_SHORTAGE'))");
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_qty_check CHECK (qty_open >= 0 AND provisional_unit_cost >= 0)');
        DB::statement('ALTER TABLE stock_valuation_entries ADD CONSTRAINT stock_valuation_entries_pool_fk FOREIGN KEY (avco_pool_id) REFERENCES avco_pools (id)');
        DB::statement("ALTER TABLE stock_valuation_entries ADD CONSTRAINT stock_valuation_entries_pool_effect_check CHECK (pool_effect IS NULL OR pool_effect IN ('SOURCE_OUT', 'DESTINATION_IN'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_stock_location_company() RETURNS trigger AS $$
            BEGIN
                IF NEW.from_location_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id
                    WHERE l.id = NEW.from_location_id AND w.company_id = NEW.company_id
                ) THEN RAISE EXCEPTION 'from location belongs to another company' USING ERRCODE = '23503'; END IF;
                IF NEW.to_location_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id
                    WHERE l.id = NEW.to_location_id AND w.company_id = NEW.company_id
                ) THEN RAISE EXCEPTION 'to location belongs to another company' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_moves_location_company BEFORE INSERT OR UPDATE ON stock_moves
            FOR EACH ROW EXECUTE FUNCTION enforce_stock_location_company();

            CREATE OR REPLACE FUNCTION enforce_stock_layer_company() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id
                    WHERE l.id = NEW.location_id AND w.company_id = NEW.company_id
                ) THEN RAISE EXCEPTION 'layer location belongs to another company' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_layers_location_company BEFORE INSERT OR UPDATE ON stock_layers
            FOR EACH ROW EXECUTE FUNCTION enforce_stock_layer_company();

            CREATE OR REPLACE FUNCTION enforce_stock_valuation_company() RETURNS trigger AS $$
            DECLARE move_company bigint;
            BEGIN
                SELECT company_id INTO move_company FROM stock_moves WHERE id = NEW.stock_move_id;
                IF NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE id = NEW.account_id AND company_id = move_company)
                   OR (NEW.avco_pool_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM avco_pools WHERE id = NEW.avco_pool_id AND company_id = move_company))
                THEN RAISE EXCEPTION 'valuation references another company' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_valuation_entries_company BEFORE INSERT OR UPDATE ON stock_valuation_entries
            FOR EACH ROW EXECUTE FUNCTION enforce_stock_valuation_company();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS enforce_stock_valuation_company() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_stock_layer_company() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_stock_location_company() CASCADE');
        Schema::dropIfExists('stock_valuation_entries');
        Schema::dropIfExists('negative_stock_shortages');
        Schema::dropIfExists('stock_layers');
        Schema::dropIfExists('stock_moves');
        Schema::dropIfExists('avco_pools');
        Schema::dropIfExists('stock_batches');
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('avco_pool_scope'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('default_avco_pool_scope'));
    }
};
