<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0045: a transfer's exact source location is always fully physically
 * backed (TransferPoster blocks any shortfall, and TRANSFER is not a legal
 * negative_stock_override_requests.movement_class), so a non-positive source
 * pool never means the transfer itself is provisional — only that some other,
 * unrelated location under the same pool has open shortage debt. The
 * transfer's rate is final at post time, exactly like a physically-covered
 * issue from the same frozen pool. stock_transfer_repairs and
 * stock_transfer_details.is_provisional existed solely to repair a case that
 * can never occur; this migration removes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS stock_transfer_repair_immutable ON stock_transfer_repairs');
        DB::statement('DROP TRIGGER IF EXISTS stock_transfer_repair_scope ON stock_transfer_repairs');
        DB::statement('DROP FUNCTION IF EXISTS prevent_stock_transfer_repair_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_stock_transfer_repair_scope() CASCADE');
        Schema::dropIfExists('stock_transfer_repairs');

        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT std_pool_shape_check');
        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->dropColumn('is_provisional');
        });
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT std_pool_shape_check CHECK ((source_avco_pool_id IS NULL AND destination_avco_pool_id IS NULL) OR (source_avco_pool_id IS NOT NULL AND destination_avco_pool_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT std_pool_shape_check');
        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->boolean('is_provisional')->default(false)->after('destination_avco_pool_id');
        });
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT std_pool_shape_check CHECK ((source_avco_pool_id IS NULL AND destination_avco_pool_id IS NULL AND is_provisional = false) OR (source_avco_pool_id IS NOT NULL AND destination_avco_pool_id IS NOT NULL))');

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
};
