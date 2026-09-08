<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cutover_negative_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('inventory_cutover_id');
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('source_valuation_method');
            $table->decimal('source_quantity', 19, 4);
            $table->decimal('source_value', 19, 4);
            $table->decimal('base_source_value', 19, 4);
            $table->decimal('provisional_unit_cost', 19, 4);
            $table->text('reason');
            $table->jsonb('supporting_evidence');
            $table->unsignedBigInteger('responsible_owner_id');
            $table->text('resolution_plan');
            $table->date('target_date');
            $table->text('target_date_reason')->nullable();
            $table->string('status')->default('REQUESTED');
            $table->unsignedBigInteger('requested_by');
            $table->timestampTz('requested_at');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX inventory_cutover_negative_opening_identity ON inventory_cutover_negative_openings (company_id, inventory_cutover_id, item_id, location_id, COALESCE(batch_id, 0))');

        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_cutover_fk FOREIGN KEY (company_id, inventory_cutover_id) REFERENCES inventory_cutovers (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_item_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_batch_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_owner_fk FOREIGN KEY (company_id, responsible_owner_id) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_requested_fk FOREIGN KEY (company_id, requested_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_approved_fk FOREIGN KEY (company_id, approved_by) REFERENCES users (company_id, id)');

        DB::statement("ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_method_check CHECK (source_valuation_method IN ('AVCO', 'FIFO'))");
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_quantity_check CHECK (source_quantity < 0 AND provisional_unit_cost > 0)');
        DB::statement("ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_status_check CHECK (status IN ('REQUESTED', 'APPROVED'))");
        DB::statement('ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_independence_check CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        DB::statement("ALTER TABLE inventory_cutover_negative_openings ADD CONSTRAINT icno_audit_shape_check CHECK ((approved_by IS NULL) = (approved_at IS NULL) AND (status <> 'REQUESTED' OR approved_by IS NULL) AND (status <> 'APPROVED' OR approved_by IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_inventory_cutover_negative_opening() RETURNS trigger AS $$
            DECLARE source_exists boolean;
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'inventory cutover negative-opening exceptions cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT EXISTS (
                        SELECT 1 FROM inventory_source_facts f
                        WHERE f.company_id = NEW.company_id AND f.inventory_cutover_id = NEW.inventory_cutover_id
                          AND f.item_id = NEW.item_id AND f.location_id = NEW.location_id
                          AND f.source_valuation_method = NEW.source_valuation_method AND f.qty < 0
                    ) INTO source_exists;
                    IF NOT source_exists THEN
                        RAISE EXCEPTION 'negative-opening exception has no matching negative source fact' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.company_id <> OLD.company_id OR NEW.inventory_cutover_id <> OLD.inventory_cutover_id
                   OR NEW.item_id <> OLD.item_id OR NEW.location_id <> OLD.location_id OR NEW.batch_id IS DISTINCT FROM OLD.batch_id
                   OR NEW.source_valuation_method <> OLD.source_valuation_method OR NEW.source_quantity <> OLD.source_quantity
                   OR NEW.source_value <> OLD.source_value OR NEW.base_source_value <> OLD.base_source_value
                   OR NEW.provisional_unit_cost <> OLD.provisional_unit_cost OR NEW.reason <> OLD.reason
                   OR NEW.supporting_evidence <> OLD.supporting_evidence OR NEW.responsible_owner_id <> OLD.responsible_owner_id
                   OR NEW.resolution_plan <> OLD.resolution_plan OR NEW.target_date <> OLD.target_date
                   OR NEW.target_date_reason IS DISTINCT FROM OLD.target_date_reason
                   OR NEW.requested_by <> OLD.requested_by OR NEW.requested_at <> OLD.requested_at
                THEN RAISE EXCEPTION 'inventory cutover negative-opening evidence is immutable' USING ERRCODE = '23514'; END IF;
                IF NEW.status <> OLD.status AND NOT (OLD.status = 'REQUESTED' AND NEW.status = 'APPROVED')
                THEN RAISE EXCEPTION 'invalid inventory cutover negative-opening status transition' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_cutover_negative_openings_guard
                BEFORE INSERT OR UPDATE OR DELETE ON inventory_cutover_negative_openings
                FOR EACH ROW EXECUTE FUNCTION guard_inventory_cutover_negative_opening();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_inventory_cutover_negative_opening() CASCADE');
        Schema::dropIfExists('inventory_cutover_negative_openings');
    }
};
