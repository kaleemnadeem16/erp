<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 purchasing completeness (ADR 0191): the four purchase-order facts the first release deferred.
 *
 *  - `ship_to_warehouse_id` / `ship_to_location_id`: where the goods are to be delivered, a warehouse of this company and optionally one of its locations. The warehouse is a
 *    composite company key; the location is tied to that very warehouse by a composite key, so a location of another warehouse (or company) cannot be named.
 *  - `created_by`: the person who drafted the order, set by the server once and never changed (it is the identity the later record-scope slice, Q26, will scope on).
 *  - `buyer_user_id`: the optional named buyer/requester (a user of this company).
 *  - `payment_terms_days` / `payment_terms_source`: a per-order override of the supplier's default terms. On a draft, NULL means "use the supplier's default"; issuing freezes the
 *    effective value and where it came from (`PURCHASE_ORDER` or `SUPPLIER_DEFAULT`), so a later supplier edit cannot move an issued order's terms.
 *  - `shipping_method`: the agreed way the goods travel (free text; no company list exists yet).
 *
 * ADDITIVE and nullable: every existing order stays valid and readable (no creator, buyer, ship-to or terms were ever recorded for it, and none is invented). The existing issued-order
 * guard trigger is replaced so the new commercial facts are as immutable as the rest, and the creator can never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `locations (warehouse_id, id)` is already unique (2026_09_02_100000), which the ship-to location key below relies on.
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('buyer_user_id')->nullable();
            $table->unsignedBigInteger('ship_to_warehouse_id')->nullable();
            $table->unsignedBigInteger('ship_to_location_id')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('payment_terms_source', 20)->nullable();
            $table->string('shipping_method', 100)->nullable();
            $table->foreign(['company_id', 'created_by'], 'purchase_orders_created_by_company_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'buyer_user_id'], 'purchase_orders_buyer_company_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'ship_to_warehouse_id'], 'purchase_orders_ship_to_warehouse_company_fk')->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['ship_to_warehouse_id', 'ship_to_location_id'], 'purchase_orders_ship_to_location_warehouse_fk')->references(['warehouse_id', 'id'])->on('locations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_logistics_shape CHECK (
            (ship_to_location_id IS NULL OR ship_to_warehouse_id IS NOT NULL)
            AND (payment_terms_source IS NULL OR (payment_terms_source IN ('PURCHASE_ORDER', 'SUPPLIER_DEFAULT') AND payment_terms_days IS NOT NULL))
            AND (shipping_method IS NULL OR length(trim(shipping_method)) > 0)
        )");
        DB::unprepared(self::guard(true));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE purchase_orders IN ACCESS EXCLUSIVE MODE');
        if (DB::table('purchase_orders')->where(fn ($q) => $q->whereNotNull('created_by')->orWhereNotNull('buyer_user_id')->orWhereNotNull('ship_to_warehouse_id')
            ->orWhereNotNull('payment_terms_days')->orWhereNotNull('shipping_method'))->exists()) {
            throw new RuntimeException('Purchase orders carry ship-to, buyer, creator, payment-terms or shipping-method facts: rolling back would erase them. Plan a separately approved backout first.');
        }
        DB::unprepared(self::guard(false));
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_logistics_shape');
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropForeign('purchase_orders_created_by_company_fk');
            $table->dropForeign('purchase_orders_buyer_company_fk');
            $table->dropForeign('purchase_orders_ship_to_warehouse_company_fk');
            $table->dropForeign('purchase_orders_ship_to_location_warehouse_fk');
            $table->dropColumn(['created_by', 'buyer_user_id', 'ship_to_warehouse_id', 'ship_to_location_id', 'payment_terms_days', 'payment_terms_source', 'shipping_method']);
        });
    }

    /** The issued-order guard of 2026_09_08_140000, with the new commercial facts (and the creator, always) added. */
    private static function guard(bool $extended): string
    {
        $more = $extended ? <<<'SQL'

                    OR NEW.ship_to_warehouse_id IS DISTINCT FROM OLD.ship_to_warehouse_id OR NEW.ship_to_location_id IS DISTINCT FROM OLD.ship_to_location_id
                    OR NEW.buyer_user_id IS DISTINCT FROM OLD.buyer_user_id OR NEW.payment_terms_days IS DISTINCT FROM OLD.payment_terms_days
                    OR NEW.payment_terms_source IS DISTINCT FROM OLD.payment_terms_source OR NEW.shipping_method IS DISTINCT FROM OLD.shipping_method
SQL : '';
        $creator = $extended ? <<<'SQL'

                IF NEW.created_by IS DISTINCT FROM OLD.created_by THEN RAISE EXCEPTION 'the creator of a purchase order never changes' USING ERRCODE = '23514'; END IF;
SQL : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION guard_purchase_order_mutation() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN RAISE EXCEPTION 'issued purchase orders cannot be deleted' USING ERRCODE = '23514'; END IF;
                    RETURN OLD;
                END IF;{$creator}
                IF OLD.status <> 'DRAFT' AND (
                    NEW.company_id <> OLD.company_id OR NEW.supplier_account_id <> OLD.supplier_account_id
                    OR NEW.supplier_site_id IS DISTINCT FROM OLD.supplier_site_id OR NEW.currency_id <> OLD.currency_id
                    OR NEW.fx_policy <> OLD.fx_policy OR NEW.fx_rate <> OLD.fx_rate OR NEW.fx_rate_date <> OLD.fx_rate_date
                    OR NEW.fx_rate_type <> OLD.fx_rate_type OR NEW.fx_rate_source <> OLD.fx_rate_source
                    OR NEW.order_date <> OLD.order_date OR NEW.expected_delivery_date IS DISTINCT FROM OLD.expected_delivery_date{$more}
                ) THEN RAISE EXCEPTION 'issued purchase-order commercial data is immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; \$\$ LANGUAGE plpgsql;
SQL;
    }
};
