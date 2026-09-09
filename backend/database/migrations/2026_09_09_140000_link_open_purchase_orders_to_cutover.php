<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_cutover_id')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('source_system')->nullable();
            $table->string('source_record_id')->nullable();
        });
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->foreignId('import_batch_row_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_record_id')->nullable();
        });

        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT po_cutover_fk FOREIGN KEY (company_id, inventory_cutover_id) REFERENCES inventory_cutovers (company_id, id)');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT po_import_batch_fk FOREIGN KEY (company_id, import_batch_id) REFERENCES import_batches (company_id, id)');
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_cutover_source_shape_check CHECK ((inventory_cutover_id IS NULL AND import_batch_id IS NULL AND source_system IS NULL AND source_record_id IS NULL) OR (inventory_cutover_id IS NOT NULL AND import_batch_id IS NOT NULL AND btrim(source_system) <> '' AND btrim(source_record_id) <> ''))");
        DB::statement("ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_cutover_source_shape_check CHECK ((import_batch_row_id IS NULL AND source_record_id IS NULL) OR (import_batch_row_id IS NOT NULL AND btrim(source_record_id) <> ''))");
        DB::statement('CREATE UNIQUE INDEX purchase_orders_cutover_source_unique ON purchase_orders (company_id, inventory_cutover_id, source_system, source_record_id) WHERE inventory_cutover_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX purchase_order_lines_import_row_unique ON purchase_order_lines (import_batch_row_id) WHERE import_batch_row_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_open_po_cutover_provenance() RETURNS trigger AS $$
            DECLARE row_batch bigint; order_batch bigint; order_cutover bigint;
            BEGIN
                IF TG_TABLE_NAME = 'purchase_orders' THEN
                    IF NEW.inventory_cutover_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM inventory_cutover_batches b
                        WHERE b.company_id = NEW.company_id AND b.inventory_cutover_id = NEW.inventory_cutover_id
                          AND b.import_batch_id = NEW.import_batch_id AND b.entity_type = 'OPEN_PURCHASE_ORDER'
                    ) THEN RAISE EXCEPTION 'open purchase order is not linked to its cutover batch' USING ERRCODE = '23503'; END IF;
                ELSE
                    IF NEW.import_batch_row_id IS NOT NULL THEN
                        SELECT import_batch_id INTO row_batch FROM import_batch_rows WHERE id = NEW.import_batch_row_id;
                        SELECT import_batch_id, inventory_cutover_id INTO order_batch, order_cutover FROM purchase_orders WHERE id = NEW.purchase_order_id;
                        IF row_batch IS DISTINCT FROM order_batch OR order_cutover IS NULL THEN
                            RAISE EXCEPTION 'open purchase-order line provenance crosses its order batch' USING ERRCODE = '23503';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchase_orders_cutover_scope BEFORE INSERT OR UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION enforce_open_po_cutover_provenance();
            CREATE TRIGGER purchase_order_lines_cutover_scope BEFORE INSERT OR UPDATE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION enforce_open_po_cutover_provenance();

            CREATE OR REPLACE FUNCTION guard_open_po_cutover_provenance() RETURNS trigger AS $$
            BEGIN
                IF OLD.inventory_cutover_id IS NOT NULL AND (
                    NEW.inventory_cutover_id IS DISTINCT FROM OLD.inventory_cutover_id
                    OR NEW.import_batch_id IS DISTINCT FROM OLD.import_batch_id
                    OR NEW.source_system IS DISTINCT FROM OLD.source_system
                    OR NEW.source_record_id IS DISTINCT FROM OLD.source_record_id
                ) THEN RAISE EXCEPTION 'open purchase-order provenance is immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchase_orders_cutover_immutable BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION guard_open_po_cutover_provenance();

            CREATE OR REPLACE FUNCTION guard_open_po_line_provenance() RETURNS trigger AS $$
            BEGIN
                IF OLD.import_batch_row_id IS NOT NULL AND (
                    NEW.import_batch_row_id IS DISTINCT FROM OLD.import_batch_row_id
                    OR NEW.source_record_id IS DISTINCT FROM OLD.source_record_id
                ) THEN RAISE EXCEPTION 'open purchase-order line provenance is immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchase_order_lines_cutover_immutable BEFORE UPDATE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION guard_open_po_line_provenance();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_open_po_line_provenance() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_open_po_cutover_provenance() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_open_po_cutover_provenance() CASCADE');
        DB::statement('DROP INDEX IF EXISTS purchase_order_lines_import_row_unique');
        DB::statement('DROP INDEX IF EXISTS purchase_orders_cutover_source_unique');
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_row_id');
            $table->dropColumn('source_record_id');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['inventory_cutover_id', 'import_batch_id', 'source_system', 'source_record_id']);
        });
    }
};
