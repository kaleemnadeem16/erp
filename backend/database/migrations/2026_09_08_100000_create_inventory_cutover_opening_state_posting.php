<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cutover_avco_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('inventory_cutover_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('policy_transition_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('target_avco_pool_id');
            $table->decimal('opening_quantity', 19, 4);
            $table->decimal('opening_value', 19, 4);
            $table->decimal('base_opening_value', 19, 4);
            $table->decimal('derived_average', 19, 4);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX inventory_cutover_avco_pools_identity ON inventory_cutover_avco_pools (company_id, policy_transition_id, COALESCE(warehouse_id, 0), COALESCE(batch_id, 0))');

        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_cutover_fk FOREIGN KEY (company_id, inventory_cutover_id) REFERENCES inventory_cutovers (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_item_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_policy_transition_fk FOREIGN KEY (company_id, policy_transition_id) REFERENCES inventory_cutover_policy_transitions (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_warehouse_fk FOREIGN KEY (company_id, warehouse_id) REFERENCES warehouses (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_batch_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_pool_fk FOREIGN KEY (company_id, target_avco_pool_id) REFERENCES avco_pools (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_avco_pools ADD CONSTRAINT icap_quantity_check CHECK (opening_quantity >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_inventory_cutover_avco_pool() RETURNS trigger AS $$
            DECLARE transition_item bigint; pool_item bigint;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'inventory cutover AVCO pool provenance is immutable' USING ERRCODE = '23514';
                END IF;
                SELECT item_id INTO transition_item FROM inventory_cutover_policy_transitions
                    WHERE company_id = NEW.company_id AND id = NEW.policy_transition_id;
                SELECT item_id INTO pool_item FROM avco_pools
                    WHERE company_id = NEW.company_id AND id = NEW.target_avco_pool_id;
                IF transition_item IS DISTINCT FROM NEW.item_id OR pool_item IS DISTINCT FROM NEW.item_id THEN
                    RAISE EXCEPTION 'inventory cutover AVCO pool provenance crosses its item scope' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_cutover_avco_pools_guard
                BEFORE INSERT OR UPDATE OR DELETE ON inventory_cutover_avco_pools
                FOR EACH ROW EXECUTE FUNCTION guard_inventory_cutover_avco_pool();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_inventory_cutover_avco_pool() CASCADE');
        Schema::dropIfExists('inventory_cutover_avco_pools');
    }
};
