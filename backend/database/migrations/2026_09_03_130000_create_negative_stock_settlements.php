<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negative_stock_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('shortage_id');
            $table->unsignedBigInteger('inbound_move_id');
            $table->decimal('qty_settled', 19, 4);
            $table->decimal('provisional_unit_cost', 19, 4);
            $table->decimal('actual_unit_cost', 19, 4);
            $table->decimal('unit_cost_difference', 19, 4);
            $table->decimal('valuation_adjustment', 19, 4);
            $table->timestampTz('settled_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'shortage_id', 'inbound_move_id']);
        });
        DB::statement('ALTER TABLE negative_stock_settlements ADD CONSTRAINT nss_shortage_company_fk FOREIGN KEY (company_id, shortage_id) REFERENCES negative_stock_shortages (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_settlements ADD CONSTRAINT nss_move_company_fk FOREIGN KEY (company_id, inbound_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_settlements ADD CONSTRAINT nss_values_check CHECK (qty_settled > 0 AND provisional_unit_cost >= 0 AND actual_unit_cost >= 0 AND unit_cost_difference = actual_unit_cost - provisional_unit_cost)');
        DB::statement('ALTER TABLE stock_valuation_entries DROP CONSTRAINT stock_valuation_entries_pool_effect_check');
        DB::statement("ALTER TABLE stock_valuation_entries ADD CONSTRAINT stock_valuation_entries_pool_effect_check CHECK (pool_effect IS NULL OR pool_effect IN ('SOURCE_OUT', 'DESTINATION_IN', 'VALUATION_ADJUSTMENT'))");
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
            CREATE TRIGGER negative_settlements_scope BEFORE INSERT OR UPDATE ON negative_stock_settlements
            FOR EACH ROW EXECUTE FUNCTION enforce_negative_settlement_scope();

            CREATE OR REPLACE FUNCTION prevent_negative_settlement_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'negative stock settlements are immutable' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER negative_settlements_immutable BEFORE UPDATE OR DELETE ON negative_stock_settlements
            FOR EACH ROW EXECUTE FUNCTION prevent_negative_settlement_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_negative_settlement_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_negative_settlement_scope() CASCADE');
        Schema::dropIfExists('negative_stock_settlements');
        DB::statement('ALTER TABLE stock_valuation_entries DROP CONSTRAINT stock_valuation_entries_pool_effect_check');
        DB::statement("ALTER TABLE stock_valuation_entries ADD CONSTRAINT stock_valuation_entries_pool_effect_check CHECK (pool_effect IS NULL OR pool_effect IN ('SOURCE_OUT', 'DESTINATION_IN'))");
    }
};
