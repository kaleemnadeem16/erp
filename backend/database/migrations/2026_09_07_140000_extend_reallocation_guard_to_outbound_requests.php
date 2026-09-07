<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * guard_stock_layer_allocation_reversal() has only ever authorised
 * correction rows belonging to a RECEIPT request — the only kind that could
 * cascade into a later move before this slice. Now a backdated ISSUE or
 * TRANSFER's own outbound insertion can cascade into later moves too
 * (FifoReceiptReallocator::applyCorrections(), driven by
 * LayerCounterfactualReplayer::planOutboundInsertion()), and those
 * correction rows carry a recalculation_request_id whose request is an
 * ISSUE/TRANSFER, not a RECEIPT — the hardcoded `r.movement_kind = 'RECEIPT'`
 * filter rejected every one of them. request.location_id means the same
 * thing for all three kinds (receipt destination or outbound physical
 * source), so the reachability logic itself needs no change — only which
 * request kinds are recognised at all.
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
                        WHERE r.company_id = NEW.company_id AND r.id = NEW.recalculation_request_id
                          AND r.movement_kind IN ('RECEIPT', 'ISSUE', 'TRANSFER');
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'reallocation row does not match its request, move and layer scope' USING ERRCODE = '23514';
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
                        RAISE EXCEPTION 'reallocation row does not match its request, move and layer scope' USING ERRCODE = '23514';
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
};
