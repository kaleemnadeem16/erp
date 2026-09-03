<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'source_type', 'source_id']);
            $table->unsignedSmallInteger('source_line_no')->default(1)->after('source_id');
            $table->unique(['company_id', 'source_type', 'source_id', 'source_line_no']);
        });
        Schema::create('stock_transfer_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('stock_move_id');
            $table->unsignedBigInteger('source_avco_pool_id')->nullable();
            $table->unsignedBigInteger('destination_avco_pool_id')->nullable();
            $table->boolean('is_provisional')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'stock_move_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT std_move_company_fk FOREIGN KEY (company_id, stock_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT std_source_pool_company_fk FOREIGN KEY (company_id, source_avco_pool_id) REFERENCES avco_pools (company_id, id)');
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT std_destination_pool_company_fk FOREIGN KEY (company_id, destination_avco_pool_id) REFERENCES avco_pools (company_id, id)');
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT std_pool_shape_check CHECK ((source_avco_pool_id IS NULL AND destination_avco_pool_id IS NULL AND is_provisional = false) OR (source_avco_pool_id IS NOT NULL AND destination_avco_pool_id IS NOT NULL))');
        DB::statement('ALTER TABLE stock_layers ADD CONSTRAINT stock_layers_source_line_check CHECK (source_line_no > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_negative_settlement_scope() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM negative_stock_shortages s
                    JOIN stock_moves m ON m.id = NEW.inbound_move_id AND m.company_id = NEW.company_id
                    WHERE s.id = NEW.shortage_id AND s.company_id = NEW.company_id
                      AND s.item_id = m.item_id AND s.location_id = m.to_location_id
                      AND s.batch_id IS NOT DISTINCT FROM m.batch_id
                ) THEN RAISE EXCEPTION 'negative settlement references an incompatible shortage or inbound move' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION enforce_stock_transfer_detail() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM stock_moves m WHERE m.id = NEW.stock_move_id AND m.company_id = NEW.company_id AND m.from_location_id IS NOT NULL AND m.to_location_id IS NOT NULL)
                   OR (NEW.source_avco_pool_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM avco_pools p JOIN stock_moves m ON m.id = NEW.stock_move_id WHERE p.id = NEW.source_avco_pool_id AND p.company_id = NEW.company_id AND p.item_id = m.item_id))
                   OR (NEW.destination_avco_pool_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM avco_pools p JOIN stock_moves m ON m.id = NEW.stock_move_id WHERE p.id = NEW.destination_avco_pool_id AND p.company_id = NEW.company_id AND p.item_id = m.item_id))
                THEN RAISE EXCEPTION 'transfer detail references incompatible state' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_transfer_detail_scope BEFORE INSERT OR UPDATE ON stock_transfer_details FOR EACH ROW EXECUTE FUNCTION enforce_stock_transfer_detail();
            CREATE OR REPLACE FUNCTION prevent_stock_transfer_detail_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'stock transfer details are immutable' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_transfer_detail_immutable BEFORE UPDATE OR DELETE ON stock_transfer_details FOR EACH ROW EXECUTE FUNCTION prevent_stock_transfer_detail_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_stock_transfer_detail_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_stock_transfer_detail() CASCADE');
        // Restore the stricter pre-transfer version of the settlement-scope
        // function this migration's up() relaxed (receipt-only inbound moves),
        // so a partial rollback leaves the earlier migration's contract intact.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_negative_settlement_scope() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM negative_stock_shortages s
                    JOIN stock_moves m ON m.id = NEW.inbound_move_id AND m.company_id = NEW.company_id
                    WHERE s.id = NEW.shortage_id
                      AND s.company_id = NEW.company_id
                      AND s.item_id = m.item_id
                      AND s.location_id = m.to_location_id
                      AND m.from_location_id IS NULL
                      AND s.batch_id IS NOT DISTINCT FROM m.batch_id
                ) THEN
                    RAISE EXCEPTION 'negative settlement references an incompatible shortage or inbound move' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            SQL);
        Schema::dropIfExists('stock_transfer_details');
        DB::statement('ALTER TABLE stock_layers DROP CONSTRAINT IF EXISTS stock_layers_source_line_check');
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'source_type', 'source_id', 'source_line_no']);
            $table->dropColumn('source_line_no');
            $table->unique(['company_id', 'source_type', 'source_id']);
        });
    }
};
