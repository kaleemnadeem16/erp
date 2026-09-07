<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recalculation never edits an allocation. It appends an exact reversal of
 * an existing ALLOCATION row and, where needed, a replacement ALLOCATION row
 * tied to the recalculation request. The live layer balance is adjusted in the
 * same transaction, while the ledger retains both the original and correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_layer_consumptions', function (Blueprint $table) {
            $table->unsignedBigInteger('reverses_consumption_id')->nullable();
            $table->unsignedBigInteger('recalculation_request_id')->nullable();
            $table->dropUnique(['company_id', 'stock_move_id', 'stock_layer_id']);
        });
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_purpose_check');
        DB::statement("ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_purpose_check CHECK (purpose IN ('ALLOCATION', 'REVERSAL', 'ALLOCATION_REVERSAL'))");
        DB::statement("ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_reallocation_shape_check CHECK (
            (purpose = 'ALLOCATION_REVERSAL' AND reverses_consumption_id IS NOT NULL AND recalculation_request_id IS NOT NULL)
            OR (purpose = 'ALLOCATION' AND reverses_consumption_id IS NULL)
            OR (purpose = 'REVERSAL' AND reverses_consumption_id IS NULL AND recalculation_request_id IS NULL)
        )");
        DB::statement('ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_reversed_company_fk FOREIGN KEY (company_id, reverses_consumption_id) REFERENCES stock_layer_consumptions (company_id, id)');
        DB::statement('ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_recalculation_request_company_fk FOREIGN KEY (company_id, recalculation_request_id) REFERENCES recalculation_requests (company_id, id)');
        DB::statement("CREATE UNIQUE INDEX slc_reallocation_allocation_unique ON stock_layer_consumptions (company_id, recalculation_request_id, stock_move_id, stock_layer_id) WHERE purpose = 'ALLOCATION' AND recalculation_request_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX slc_reallocation_reversal_unique ON stock_layer_consumptions (company_id, recalculation_request_id, reverses_consumption_id) WHERE purpose = 'ALLOCATION_REVERSAL'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_layer_allocation_reversal() RETURNS trigger AS $$
            DECLARE
                source_row stock_layer_consumptions%ROWTYPE;
                already_reversed numeric(19,4);
            BEGIN
                IF NEW.recalculation_request_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM recalculation_requests request
                    JOIN stock_moves move ON move.company_id = request.company_id
                        AND move.id = NEW.stock_move_id
                        AND move.item_id = request.item_id
                        AND move.from_location_id = request.location_id
                    JOIN stock_layers layer ON layer.company_id = request.company_id
                        AND layer.id = NEW.stock_layer_id
                        AND layer.item_id = request.item_id
                        AND layer.location_id = request.location_id
                    WHERE request.company_id = NEW.company_id
                      AND request.id = NEW.recalculation_request_id
                      AND request.movement_kind = 'RECEIPT'
                ) THEN
                    RAISE EXCEPTION 'reallocation row does not match its receipt request, issue and layer scope' USING ERRCODE = '23514';
                END IF;

                IF NEW.purpose <> 'ALLOCATION_REVERSAL' THEN
                    RETURN NEW;
                END IF;

                SELECT * INTO source_row FROM stock_layer_consumptions
                    WHERE company_id = NEW.company_id AND id = NEW.reverses_consumption_id
                    FOR UPDATE;
                IF NOT FOUND OR source_row.purpose <> 'ALLOCATION'
                   OR source_row.stock_move_id <> NEW.stock_move_id
                   OR source_row.stock_layer_id <> NEW.stock_layer_id
                THEN
                    RAISE EXCEPTION 'allocation reversal must reference an allocation for the same move and layer' USING ERRCODE = '23514';
                END IF;

                SELECT COALESCE(SUM(qty_consumed), 0) INTO already_reversed
                    FROM stock_layer_consumptions
                    WHERE company_id = NEW.company_id
                      AND reverses_consumption_id = NEW.reverses_consumption_id
                      AND purpose = 'ALLOCATION_REVERSAL';
                IF already_reversed + NEW.qty_consumed > source_row.qty_consumed THEN
                    RAISE EXCEPTION 'allocation reversal exceeds the referenced allocation' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER slc_00_allocation_reversal_guard
                BEFORE INSERT ON stock_layer_consumptions
                FOR EACH ROW EXECUTE FUNCTION guard_stock_layer_allocation_reversal();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER slc_00_allocation_reversal_guard ON stock_layer_consumptions');
        DB::statement('DROP FUNCTION guard_stock_layer_allocation_reversal()');
        DB::statement('DROP INDEX slc_reallocation_reversal_unique');
        DB::statement('DROP INDEX slc_reallocation_allocation_unique');
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_recalculation_request_company_fk');
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_reversed_company_fk');
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_reallocation_shape_check');
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_purpose_check');
        DB::statement("ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_purpose_check CHECK (purpose IN ('ALLOCATION', 'REVERSAL'))");
        Schema::table('stock_layer_consumptions', function (Blueprint $table) {
            $table->dropColumn(['reverses_consumption_id', 'recalculation_request_id']);
            $table->unique(['company_id', 'stock_move_id', 'stock_layer_id']);
        });
    }
};
