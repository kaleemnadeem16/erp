<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 integrity audit: every table this touches already had an Eloquent
 * guard enforcing exactly these rules, but nothing stopped raw SQL — the
 * stock ledger and purchasing documents were the only Phase 2 history without
 * the database-level mirror every other immutable table has. Each guard below
 * is a one-to-one copy of the model's own updating()/deleting() rule, so any
 * Eloquent flow that passes the PHP guard passes the trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_stock_ledger_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'posted stock ledger rows are immutable' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_moves_immutable BEFORE UPDATE OR DELETE ON stock_moves FOR EACH ROW EXECUTE FUNCTION prevent_stock_ledger_mutation();
            CREATE TRIGGER stock_valuation_entries_immutable BEFORE UPDATE OR DELETE ON stock_valuation_entries FOR EACH ROW EXECUTE FUNCTION prevent_stock_ledger_mutation();

            CREATE OR REPLACE FUNCTION guard_stock_layer_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'stock layers cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF NEW.company_id <> OLD.company_id OR NEW.item_id <> OLD.item_id OR NEW.location_id <> OLD.location_id
                   OR NEW.qty_received <> OLD.qty_received OR NEW.unit_cost <> OLD.unit_cost OR NEW.currency_id <> OLD.currency_id
                   OR NEW.fx_rate <> OLD.fx_rate OR NEW.base_unit_cost <> OLD.base_unit_cost OR NEW.received_at <> OLD.received_at
                   OR NEW.source_type <> OLD.source_type OR NEW.source_id <> OLD.source_id OR NEW.source_line_no <> OLD.source_line_no
                   OR NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.serial_no IS DISTINCT FROM OLD.serial_no
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN RAISE EXCEPTION 'receipt-layer facts are immutable; only remaining quantity may be consumed' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_layers_guard BEFORE UPDATE OR DELETE ON stock_layers FOR EACH ROW EXECUTE FUNCTION guard_stock_layer_mutation();

            CREATE OR REPLACE FUNCTION guard_goods_receipt_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN RAISE EXCEPTION 'posted goods receipts cannot be deleted' USING ERRCODE = '23514'; END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status <> 'DRAFT' AND (
                    NEW.company_id <> OLD.company_id OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.supplier_account_id <> OLD.supplier_account_id OR NEW.physical_arrival_at <> OLD.physical_arrival_at
                    OR NEW.recorded_at <> OLD.recorded_at OR NEW.posting_date <> OLD.posting_date
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN RAISE EXCEPTION 'posted goods-receipt facts are immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER goods_receipts_guard BEFORE UPDATE OR DELETE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION guard_goods_receipt_mutation();

            CREATE OR REPLACE FUNCTION guard_goods_receipt_line_mutation() RETURNS trigger AS $$
            DECLARE receipt_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT status INTO receipt_status FROM goods_receipts WHERE id = OLD.goods_receipt_id;
                ELSE
                    SELECT status INTO receipt_status FROM goods_receipts WHERE id = NEW.goods_receipt_id;
                END IF;
                -- A NULL status means the parent is already gone: a draft receipt's own
                -- ON DELETE CASCADE, which must stay allowed.
                IF receipt_status IS NOT NULL AND receipt_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'posted goods-receipt lines are immutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER goods_receipt_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION guard_goods_receipt_line_mutation();

            CREATE OR REPLACE FUNCTION guard_purchase_order_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN RAISE EXCEPTION 'issued purchase orders cannot be deleted' USING ERRCODE = '23514'; END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status <> 'DRAFT' AND (
                    NEW.company_id <> OLD.company_id OR NEW.supplier_account_id <> OLD.supplier_account_id
                    OR NEW.supplier_site_id IS DISTINCT FROM OLD.supplier_site_id OR NEW.currency_id <> OLD.currency_id
                    OR NEW.fx_policy <> OLD.fx_policy OR NEW.fx_rate <> OLD.fx_rate OR NEW.fx_rate_date <> OLD.fx_rate_date
                    OR NEW.fx_rate_type <> OLD.fx_rate_type OR NEW.fx_rate_source <> OLD.fx_rate_source
                    OR NEW.order_date <> OLD.order_date OR NEW.expected_delivery_date IS DISTINCT FROM OLD.expected_delivery_date
                ) THEN RAISE EXCEPTION 'issued purchase-order commercial data is immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchase_orders_guard BEFORE UPDATE OR DELETE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION guard_purchase_order_mutation();

            CREATE OR REPLACE FUNCTION guard_purchase_order_line_mutation() RETURNS trigger AS $$
            DECLARE order_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT status INTO order_status FROM purchase_orders WHERE id = OLD.purchase_order_id;
                    IF order_status IS NOT NULL AND order_status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'issued purchase-order lines cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                SELECT status INTO order_status FROM purchase_orders WHERE id = NEW.purchase_order_id;
                IF TG_OP = 'INSERT' THEN
                    IF order_status <> 'DRAFT' THEN RAISE EXCEPTION 'lines can only be added to a draft purchase order' USING ERRCODE = '23514'; END IF;
                    RETURN NEW;
                END IF;
                IF order_status <> 'DRAFT' AND (
                    NEW.company_id <> OLD.company_id OR NEW.purchase_order_id <> OLD.purchase_order_id OR NEW.line_number <> OLD.line_number
                    OR NEW.item_id <> OLD.item_id OR NEW.item_code IS DISTINCT FROM OLD.item_code OR NEW.description <> OLD.description
                    OR NEW.supplier_item_code IS DISTINCT FROM OLD.supplier_item_code OR NEW.ordered_qty <> OLD.ordered_qty
                    OR NEW.purchase_uom_id <> OLD.purchase_uom_id OR NEW.ordered_stock_qty <> OLD.ordered_stock_qty
                    OR NEW.stock_uom_id <> OLD.stock_uom_id OR NEW.uom_factor <> OLD.uom_factor OR NEW.unit_price <> OLD.unit_price
                    OR NEW.discount_amount <> OLD.discount_amount OR NEW.tax_amount <> OLD.tax_amount OR NEW.line_amount <> OLD.line_amount
                    OR NEW.base_unit_price <> OLD.base_unit_price OR NEW.base_discount_amount <> OLD.base_discount_amount
                    OR NEW.base_tax_amount <> OLD.base_tax_amount OR NEW.base_line_amount <> OLD.base_line_amount
                    OR NEW.expected_delivery_date IS DISTINCT FROM OLD.expected_delivery_date OR NEW.qa_required <> OLD.qa_required
                    OR NEW.over_receipt_mode IS DISTINCT FROM OLD.over_receipt_mode
                    OR NEW.over_receipt_tolerance_percent IS DISTINCT FROM OLD.over_receipt_tolerance_percent
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN RAISE EXCEPTION 'issued purchase-order line snapshots are immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchase_order_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION guard_purchase_order_line_mutation();

            CREATE OR REPLACE FUNCTION enforce_recalculation_request_scope() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.location_id AND w.company_id = NEW.company_id)
                   OR (NEW.destination_location_id IS NOT NULL AND NOT EXISTS (
                       SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.destination_location_id AND w.company_id = NEW.company_id))
                THEN RAISE EXCEPTION 'recalculation request references a location outside its company' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER rr_scope BEFORE INSERT ON recalculation_requests FOR EACH ROW EXECUTE FUNCTION enforce_recalculation_request_scope();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS enforce_recalculation_request_scope() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_purchase_order_line_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_purchase_order_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_goods_receipt_line_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_goods_receipt_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_stock_layer_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS prevent_stock_ledger_mutation() CASCADE');
    }
};
