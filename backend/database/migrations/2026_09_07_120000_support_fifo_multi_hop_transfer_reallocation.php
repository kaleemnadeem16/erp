<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generalises the reallocation guard from exactly one transfer hop to
 * however many a real transfer chain actually contains. FifoReceiptReallocator
 * no longer caps its own recursion depth, so the database authorises a
 * correction row wherever its layer sits: the request's own location, or any
 * location reachable from it by following only TRANSFER moves of the same
 * item — the same transfer-connected-component idea MovementReplayScope uses
 * for the separate issue/transfer preview feature, computed here with a
 * recursive CTE instead of PHP-side discovery since the guard runs inside a
 * trigger. A transfer-typed row is authorised as long as its layer is
 * reachable at all; the recursion itself is what used to be checked one hop
 * at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_stock_layer_allocation_reversal() RETURNS trigger AS $$
            DECLARE
                source_row stock_layer_consumptions%ROWTYPE;
                already_reversed numeric(19,4);
                req RECORD;
                authorised boolean;
            BEGIN
                IF NEW.recalculation_request_id IS NOT NULL THEN
                    SELECT r.company_id, r.item_id, r.location_id INTO req
                        FROM recalculation_requests r
                        WHERE r.company_id = NEW.company_id AND r.id = NEW.recalculation_request_id AND r.movement_kind = 'RECEIPT';
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'reallocation row does not match its receipt request, issue and layer scope' USING ERRCODE = '23514';
                    END IF;

                    SELECT EXISTS (
                        SELECT 1
                        FROM stock_moves move
                        JOIN stock_layers layer ON layer.company_id = move.company_id
                            AND layer.id = NEW.stock_layer_id AND layer.item_id = req.item_id
                        WHERE move.company_id = req.company_id AND move.id = NEW.stock_move_id AND move.item_id = req.item_id
                          AND layer.location_id IN (
                                WITH RECURSIVE reachable(location_id) AS (
                                    SELECT req.location_id
                                    UNION
                                    SELECT hop.to_location_id
                                    FROM stock_moves hop
                                    JOIN reachable ON reachable.location_id = hop.from_location_id
                                    WHERE hop.company_id = req.company_id AND hop.item_id = req.item_id
                                      AND hop.movement_kind = 'TRANSFER'
                                )
                                SELECT location_id FROM reachable
                          )
                    ) INTO authorised;

                    IF NOT authorised THEN
                        RAISE EXCEPTION 'reallocation row does not match its receipt request, issue and layer scope' USING ERRCODE = '23514';
                    END IF;
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
};
