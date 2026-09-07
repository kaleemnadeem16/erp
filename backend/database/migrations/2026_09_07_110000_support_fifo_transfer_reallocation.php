<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the reallocation guards to also allow correction rows at a
 * transfer's own immediate destination location — one hop out from the
 * receipt request's own location, no further. This covers both the
 * transfer's own reversed destination-layer component and whatever the
 * destination's own later issues already consumed from it; either way the
 * row's layer must sit at that one-hop destination, and a *transfer*-typed
 * row there may only be the original hop's own reversal, never a second hop.
 * This is what lets FifoReceiptReallocator correct a transfer's
 * destination-layer provenance and cascade into direct issues already
 * posted there, while a second transfer hop still has nothing that permits
 * it and stays refused (also enforced independently in PHP).
 *
 * A superseded destination-layer-component is recorded the same way an
 * ordinary receipt reversal already is — a purpose = REVERSAL row preserving
 * the exact invalidated quantity for replay — except this one also carries
 * recalculation_request_id for correction provenance, which the prior shape
 * check disallowed for REVERSAL. Relaxed here rather than introducing a new
 * purpose value, since the meaning ("this layer's contribution, or part of
 * it, is undone") is identical either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_reallocation_shape_check');
        DB::statement("ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_reallocation_shape_check CHECK (
            (purpose = 'ALLOCATION_REVERSAL' AND reverses_consumption_id IS NOT NULL AND recalculation_request_id IS NOT NULL)
            OR (purpose = 'ALLOCATION' AND reverses_consumption_id IS NULL)
            OR (purpose = 'REVERSAL' AND reverses_consumption_id IS NULL)
        )");
        DB::statement("CREATE UNIQUE INDEX slc_reallocation_destination_reversal_unique ON stock_layer_consumptions (company_id, recalculation_request_id, stock_move_id, stock_layer_id) WHERE purpose = 'REVERSAL' AND recalculation_request_id IS NOT NULL");

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
                    JOIN stock_layers layer ON layer.company_id = request.company_id
                        AND layer.id = NEW.stock_layer_id
                        AND layer.item_id = request.item_id
                    WHERE request.company_id = NEW.company_id
                      AND request.id = NEW.recalculation_request_id
                      AND request.movement_kind = 'RECEIPT'
                      AND (
                            (move.from_location_id = request.location_id AND layer.location_id = request.location_id)
                         OR EXISTS (
                                SELECT 1 FROM stock_moves hop
                                WHERE hop.company_id = request.company_id AND hop.item_id = request.item_id
                                  AND hop.movement_kind = 'TRANSFER' AND hop.from_location_id = request.location_id
                                  AND hop.to_location_id = layer.location_id
                                  AND (move.movement_kind <> 'TRANSFER' OR move.id = hop.id)
                            )
                      )
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
            SQL);
    }

    public function down(): void
    {
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
            SQL);

        DB::statement('DROP INDEX slc_reallocation_destination_reversal_unique');
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_reallocation_shape_check');
        DB::statement("ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_reallocation_shape_check CHECK (
            (purpose = 'ALLOCATION_REVERSAL' AND reverses_consumption_id IS NOT NULL AND recalculation_request_id IS NOT NULL)
            OR (purpose = 'ALLOCATION' AND reverses_consumption_id IS NULL)
            OR (purpose = 'REVERSAL' AND reverses_consumption_id IS NULL AND recalculation_request_id IS NULL)
        )");
    }
};
