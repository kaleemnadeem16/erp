<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('negative_stock_shortages', function (Blueprint $table) {
            $table->unsignedBigInteger('override_request_id')->nullable()->change();
            $table->unsignedBigInteger('inventory_cutover_negative_opening_id')->nullable()->after('override_request_id');
            $table->unique(['company_id', 'inventory_cutover_negative_opening_id']);
        });
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_cutover_company_fk FOREIGN KEY (company_id, inventory_cutover_negative_opening_id) REFERENCES inventory_cutover_negative_openings (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_authority_check CHECK ((override_request_id IS NOT NULL) <> (inventory_cutover_negative_opening_id IS NOT NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_negative_shortage_scope() RETURNS trigger AS $$
            BEGIN
                IF NEW.override_request_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM negative_stock_override_requests r
                        JOIN stock_moves m ON m.id = NEW.source_move_id AND m.company_id = NEW.company_id
                        JOIN locations l ON l.id = NEW.location_id
                        JOIN warehouses w ON w.id = l.warehouse_id AND w.company_id = NEW.company_id
                        WHERE r.id = NEW.override_request_id
                          AND r.company_id = NEW.company_id
                          AND r.item_id = NEW.item_id
                          AND r.location_id = NEW.location_id
                          AND r.batch_id IS NOT DISTINCT FROM NEW.batch_id
                          AND r.source_type = m.source_type
                          AND r.source_id = m.source_id
                          AND m.item_id = NEW.item_id
                          AND m.from_location_id = NEW.location_id
                          AND m.to_location_id IS NULL
                          AND m.batch_id IS NOT DISTINCT FROM NEW.batch_id
                    ) THEN
                        RAISE EXCEPTION 'negative shortage references incompatible override, move, location or pool' USING ERRCODE = '23503';
                    END IF;
                ELSE
                    IF NOT EXISTS (
                        SELECT 1
                        FROM inventory_cutover_negative_openings e
                        JOIN stock_moves m ON m.id = NEW.source_move_id AND m.company_id = NEW.company_id
                        WHERE e.id = NEW.inventory_cutover_negative_opening_id
                          AND e.company_id = NEW.company_id
                          AND e.status = 'APPROVED'
                          AND e.item_id = NEW.item_id
                          AND e.location_id = NEW.location_id
                          AND e.batch_id IS NOT DISTINCT FROM NEW.batch_id
                          AND m.item_id = NEW.item_id
                          AND m.from_location_id = NEW.location_id
                          AND m.to_location_id IS NULL
                          AND m.batch_id IS NOT DISTINCT FROM NEW.batch_id
                    ) THEN
                        RAISE EXCEPTION 'negative shortage references incompatible cutover exception, move, location or pool' USING ERRCODE = '23503';
                    END IF;
                END IF;
                IF NEW.avco_pool_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM avco_pools p
                    WHERE p.id = NEW.avco_pool_id AND p.company_id = NEW.company_id AND p.item_id = NEW.item_id
                ) THEN
                    RAISE EXCEPTION 'negative shortage references incompatible override, move, location or pool' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE negative_stock_shortages DROP CONSTRAINT negative_shortages_authority_check');
        DB::statement('ALTER TABLE negative_stock_shortages DROP CONSTRAINT negative_shortages_cutover_company_fk');
        Schema::table('negative_stock_shortages', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'inventory_cutover_negative_opening_id']);
            $table->dropColumn('inventory_cutover_negative_opening_id');
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_negative_shortage_scope() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM negative_stock_override_requests r
                    JOIN stock_moves m ON m.id = NEW.source_move_id AND m.company_id = NEW.company_id
                    JOIN locations l ON l.id = NEW.location_id
                    JOIN warehouses w ON w.id = l.warehouse_id AND w.company_id = NEW.company_id
                    WHERE r.id = NEW.override_request_id
                      AND r.company_id = NEW.company_id
                      AND r.item_id = NEW.item_id
                      AND r.location_id = NEW.location_id
                      AND r.batch_id IS NOT DISTINCT FROM NEW.batch_id
                      AND r.source_type = m.source_type
                      AND r.source_id = m.source_id
                      AND m.item_id = NEW.item_id
                      AND m.from_location_id = NEW.location_id
                      AND m.to_location_id IS NULL
                      AND m.batch_id IS NOT DISTINCT FROM NEW.batch_id
                ) OR (NEW.avco_pool_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM avco_pools p
                    WHERE p.id = NEW.avco_pool_id AND p.company_id = NEW.company_id AND p.item_id = NEW.item_id
                )) THEN
                    RAISE EXCEPTION 'negative shortage references incompatible override, move, location or pool' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            SQL);
        Schema::table('negative_stock_shortages', function (Blueprint $table) {
            $table->unsignedBigInteger('override_request_id')->nullable(false)->change();
        });
    }
};
