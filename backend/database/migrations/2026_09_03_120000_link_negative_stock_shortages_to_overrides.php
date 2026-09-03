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
            $table->unsignedBigInteger('override_request_id')->after('source_move_id');
            $table->unique(['company_id', 'source_move_id']);
            $table->unique(['company_id', 'override_request_id']);
        });
        DB::statement('ALTER TABLE negative_stock_shortages ADD CONSTRAINT negative_shortages_override_company_fk FOREIGN KEY (company_id, override_request_id) REFERENCES negative_stock_override_requests (company_id, id)');
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
            CREATE TRIGGER negative_shortages_scope BEFORE INSERT OR UPDATE ON negative_stock_shortages
            FOR EACH ROW EXECUTE FUNCTION enforce_negative_shortage_scope();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS enforce_negative_shortage_scope() CASCADE');
        Schema::table('negative_stock_shortages', function (Blueprint $table) {
            $table->dropForeign('negative_shortages_override_company_fk');
            $table->dropUnique(['company_id', 'source_move_id']);
            $table->dropUnique(['company_id', 'override_request_id']);
            $table->dropColumn('override_request_id');
        });
    }
};
