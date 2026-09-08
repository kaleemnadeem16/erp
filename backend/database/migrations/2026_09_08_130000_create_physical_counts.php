<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('reference')->nullable();
            $table->string('status')->default('DRAFT');
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('physical_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('physical_count_id');
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('serial_no')->nullable();
            $table->decimal('book_qty', 19, 4);
            $table->decimal('counted_qty', 19, 4)->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->decimal('found_unit_cost', 19, 4)->nullable();
            $table->unsignedBigInteger('posted_stock_move_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });
        DB::statement("CREATE UNIQUE INDEX physical_count_line_identity ON physical_count_lines (company_id, physical_count_id, item_id, location_id, COALESCE(batch_id, 0), COALESCE(serial_no, ''))");
        DB::statement('CREATE UNIQUE INDEX physical_count_lines_move_unique ON physical_count_lines (company_id, posted_stock_move_id) WHERE posted_stock_move_id IS NOT NULL');

        Schema::create('physical_count_line_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('physical_count_line_id');
            $table->decimal('counted_qty', 19, 4);
            $table->unsignedBigInteger('counted_by');
            $table->timestampTz('counted_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        DB::statement('ALTER TABLE physical_counts ADD CONSTRAINT pc_warehouse_fk FOREIGN KEY (company_id, warehouse_id) REFERENCES warehouses (company_id, id)');
        DB::statement('ALTER TABLE physical_counts ADD CONSTRAINT pc_created_actor_fk FOREIGN KEY (company_id, created_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE physical_counts ADD CONSTRAINT pc_posted_actor_fk FOREIGN KEY (company_id, posted_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE physical_counts ADD CONSTRAINT pc_cancelled_actor_fk FOREIGN KEY (company_id, cancelled_by) REFERENCES users (company_id, id)');
        DB::statement("ALTER TABLE physical_counts ADD CONSTRAINT pc_status_check CHECK (status IN ('DRAFT', 'POSTED', 'CANCELLED'))");
        DB::statement("ALTER TABLE physical_counts ADD CONSTRAINT pc_audit_shape_check CHECK ((posted_by IS NULL) = (posted_at IS NULL) AND (cancelled_by IS NULL) = (cancelled_at IS NULL) AND (status <> 'DRAFT' OR (posted_by IS NULL AND cancelled_by IS NULL)) AND (status <> 'POSTED' OR posted_by IS NOT NULL) AND (status <> 'CANCELLED' OR cancelled_by IS NOT NULL))");

        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_count_fk FOREIGN KEY (company_id, physical_count_id) REFERENCES physical_counts (company_id, id)');
        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_item_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_batch_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_counted_actor_fk FOREIGN KEY (company_id, counted_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_move_fk FOREIGN KEY (company_id, posted_stock_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_qty_check CHECK (book_qty >= 0 AND (counted_qty IS NULL OR counted_qty >= 0) AND (found_unit_cost IS NULL OR found_unit_cost > 0))');
        DB::statement('ALTER TABLE physical_count_lines ADD CONSTRAINT pcl_counted_shape_check CHECK ((counted_qty IS NULL) = (counted_by IS NULL) AND (counted_qty IS NULL) = (counted_at IS NULL))');

        DB::statement('ALTER TABLE physical_count_line_entries ADD CONSTRAINT pcle_line_fk FOREIGN KEY (company_id, physical_count_line_id) REFERENCES physical_count_lines (company_id, id)');
        DB::statement('ALTER TABLE physical_count_line_entries ADD CONSTRAINT pcle_counted_actor_fk FOREIGN KEY (company_id, counted_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE physical_count_line_entries ADD CONSTRAINT pcle_qty_check CHECK (counted_qty >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_physical_count_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'physical counts cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF NEW.company_id <> OLD.company_id OR NEW.warehouse_id <> OLD.warehouse_id
                   OR NEW.reference IS DISTINCT FROM OLD.reference OR NEW.created_by <> OLD.created_by
                   OR NEW.created_at <> OLD.created_at
                THEN RAISE EXCEPTION 'physical count header is immutable' USING ERRCODE = '23514'; END IF;
                IF (OLD.posted_by IS NOT NULL AND (NEW.posted_by IS DISTINCT FROM OLD.posted_by OR NEW.posted_at IS DISTINCT FROM OLD.posted_at))
                   OR (OLD.cancelled_by IS NOT NULL AND (NEW.cancelled_by IS DISTINCT FROM OLD.cancelled_by OR NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at))
                THEN RAISE EXCEPTION 'physical count audit decisions are immutable' USING ERRCODE = '23514'; END IF;
                IF NEW.status <> OLD.status AND NOT (OLD.status = 'DRAFT' AND NEW.status IN ('POSTED', 'CANCELLED'))
                THEN RAISE EXCEPTION 'invalid physical count status transition' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER physical_counts_guard BEFORE UPDATE OR DELETE ON physical_counts FOR EACH ROW EXECUTE FUNCTION guard_physical_count_mutation();

            CREATE OR REPLACE FUNCTION guard_physical_count_line() RETURNS trigger AS $$
            DECLARE count_status text; count_warehouse bigint; location_warehouse bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'physical count lines cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT status, warehouse_id INTO count_status, count_warehouse FROM physical_counts WHERE id = NEW.physical_count_id;
                    SELECT w.id INTO location_warehouse FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.location_id;
                    IF count_status <> 'DRAFT' THEN RAISE EXCEPTION 'lines can only be added to a draft physical count' USING ERRCODE = '23514'; END IF;
                    IF location_warehouse IS DISTINCT FROM count_warehouse THEN RAISE EXCEPTION 'physical count line location must belong to the count warehouse' USING ERRCODE = '23514'; END IF;
                    RETURN NEW;
                END IF;
                IF NEW.company_id <> OLD.company_id OR NEW.physical_count_id <> OLD.physical_count_id
                   OR NEW.item_id <> OLD.item_id OR NEW.location_id <> OLD.location_id
                   OR NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.serial_no IS DISTINCT FROM OLD.serial_no
                   OR NEW.book_qty <> OLD.book_qty
                THEN RAISE EXCEPTION 'physical count line identity is immutable' USING ERRCODE = '23514'; END IF;
                IF OLD.posted_stock_move_id IS NOT NULL AND NEW.posted_stock_move_id IS DISTINCT FROM OLD.posted_stock_move_id
                THEN RAISE EXCEPTION 'a posted physical count line cannot be re-posted' USING ERRCODE = '23514'; END IF;
                IF (NEW.counted_qty IS DISTINCT FROM OLD.counted_qty OR NEW.found_unit_cost IS DISTINCT FROM OLD.found_unit_cost) THEN
                    SELECT status INTO count_status FROM physical_counts WHERE id = NEW.physical_count_id;
                    IF count_status <> 'DRAFT' THEN RAISE EXCEPTION 'a line can only be counted while its physical count is draft' USING ERRCODE = '23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER physical_count_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON physical_count_lines FOR EACH ROW EXECUTE FUNCTION guard_physical_count_line();

            CREATE OR REPLACE FUNCTION guard_physical_count_line_entry() RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN RAISE EXCEPTION 'physical count line entries are immutable' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER physical_count_line_entries_guard BEFORE UPDATE OR DELETE ON physical_count_line_entries FOR EACH ROW EXECUTE FUNCTION guard_physical_count_line_entry();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_physical_count_line_entry() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_physical_count_line() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_physical_count_mutation() CASCADE');
        Schema::dropIfExists('physical_count_line_entries');
        Schema::dropIfExists('physical_count_lines');
        Schema::dropIfExists('physical_counts');
    }
};
