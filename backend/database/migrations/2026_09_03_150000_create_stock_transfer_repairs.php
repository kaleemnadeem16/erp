<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('stock_transfer_detail_id');
            $table->decimal('qty', 19, 4);
            $table->decimal('provisional_unit_cost', 19, 4);
            $table->decimal('actual_unit_cost', 19, 4);
            $table->decimal('unit_cost_difference', 19, 4);
            $table->decimal('valuation_adjustment', 19, 4);
            $table->timestampTz('repaired_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'stock_transfer_detail_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_repairs ADD CONSTRAINT str_detail_company_fk FOREIGN KEY (company_id, stock_transfer_detail_id) REFERENCES stock_transfer_details (company_id, id)');
        DB::statement('ALTER TABLE stock_transfer_repairs ADD CONSTRAINT str_values_check CHECK (qty > 0 AND provisional_unit_cost >= 0 AND actual_unit_cost >= 0 AND unit_cost_difference = actual_unit_cost - provisional_unit_cost)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_stock_transfer_repair_scope() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM stock_transfer_details d
                    WHERE d.id = NEW.stock_transfer_detail_id AND d.company_id = NEW.company_id
                      AND d.is_provisional = true
                      AND d.source_avco_pool_id IS NOT NULL AND d.destination_avco_pool_id IS NOT NULL
                ) THEN RAISE EXCEPTION 'transfer repair references a non-provisional or incomplete transfer detail' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_transfer_repair_scope BEFORE INSERT OR UPDATE ON stock_transfer_repairs
            FOR EACH ROW EXECUTE FUNCTION enforce_stock_transfer_repair_scope();

            CREATE OR REPLACE FUNCTION prevent_stock_transfer_repair_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'stock transfer repairs are immutable' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER stock_transfer_repair_immutable BEFORE UPDATE OR DELETE ON stock_transfer_repairs
            FOR EACH ROW EXECUTE FUNCTION prevent_stock_transfer_repair_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_stock_transfer_repair_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_stock_transfer_repair_scope() CASCADE');
        Schema::dropIfExists('stock_transfer_repairs');
    }
};
