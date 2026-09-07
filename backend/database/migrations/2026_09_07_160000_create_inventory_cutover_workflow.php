<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cutovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('mode');
            $table->timestampTz('cutover_at');
            $table->string('source_system');
            $table->jsonb('target_valuation_policy');
            $table->string('status')->default('DRAFT');
            $table->decimal('control_quantity', 19, 4);
            $table->decimal('control_value', 19, 4);
            $table->decimal('base_control_value', 19, 4);
            $table->jsonb('exception_summary')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('finalised_by')->nullable();
            $table->timestampTz('finalised_at')->nullable();
            $table->unique(['company_id', 'id']);
        });
        DB::statement("CREATE UNIQUE INDEX inventory_cutovers_one_active ON inventory_cutovers (company_id) WHERE status <> 'FAILED'");

        Schema::create('inventory_cutover_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('inventory_cutover_id');
            $table->unsignedBigInteger('import_batch_id');
            $table->string('entity_type');
            $table->unsignedInteger('source_record_count');
            $table->unsignedInteger('accepted_record_count');
            $table->unsignedInteger('rejected_record_count');
            $table->decimal('source_quantity', 19, 4);
            $table->decimal('source_value', 19, 4);
            $table->decimal('base_source_value', 19, 4);
            $table->string('status')->default('STAGED');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'import_batch_id']);
        });

        Schema::create('inventory_source_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('inventory_cutover_id');
            $table->unsignedBigInteger('inventory_cutover_batch_id');
            $table->foreignId('import_batch_row_id')->constrained()->restrictOnDelete();
            $table->string('source_system');
            $table->string('source_entity_type');
            $table->string('source_record_id');
            $table->timestampTz('source_effective_at')->nullable();
            $table->date('source_posting_date')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('source_valuation_method');
            $table->string('source_avco_scope')->nullable();
            $table->string('source_valuation_pool_identity')->nullable();
            $table->decimal('qty', 19, 4);
            $table->unsignedBigInteger('uom_id');
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->decimal('amount', 19, 4);
            $table->decimal('base_amount', 19, 4);
            $table->foreignId('currency_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('fx_rate', 19, 9)->nullable();
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->jsonb('source_payload');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source_system', 'source_entity_type', 'source_record_id']);
            $table->unique(['inventory_cutover_batch_id', 'import_batch_row_id']);
        });

        DB::statement('ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_created_actor_fk FOREIGN KEY (company_id, created_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_validated_actor_fk FOREIGN KEY (company_id, validated_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_approved_actor_fk FOREIGN KEY (company_id, approved_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_finalised_actor_fk FOREIGN KEY (company_id, finalised_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_batches ADD CONSTRAINT icb_cutover_fk FOREIGN KEY (company_id, inventory_cutover_id) REFERENCES inventory_cutovers (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_batches ADD CONSTRAINT icb_import_batch_fk FOREIGN KEY (company_id, import_batch_id) REFERENCES import_batches (company_id, id)');
        DB::statement('ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_cutover_fk FOREIGN KEY (company_id, inventory_cutover_id) REFERENCES inventory_cutovers (company_id, id)');
        DB::statement('ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_cutover_batch_fk FOREIGN KEY (company_id, inventory_cutover_batch_id) REFERENCES inventory_cutover_batches (company_id, id)');
        DB::statement('ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_item_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_uom_fk FOREIGN KEY (company_id, uom_id) REFERENCES uoms (company_id, id)');

        DB::statement("ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_mode_check CHECK (mode IN ('OPENING_STATE', 'OPERATIONAL', 'FULL_HISTORY'))");
        DB::statement("ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_status_check CHECK (status IN ('DRAFT', 'VALIDATED', 'APPROVED', 'FINALISED', 'FAILED'))");
        DB::statement("ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_source_check CHECK (btrim(source_system) <> '')");
        DB::statement("ALTER TABLE inventory_cutovers ADD CONSTRAINT ic_audit_shape_check CHECK ((validated_by IS NULL) = (validated_at IS NULL) AND (approved_by IS NULL) = (approved_at IS NULL) AND (finalised_by IS NULL) = (finalised_at IS NULL) AND (approved_by IS NULL OR validated_by IS NOT NULL) AND (finalised_by IS NULL OR approved_by IS NOT NULL) AND (status <> 'DRAFT' OR (validated_by IS NULL AND approved_by IS NULL AND finalised_by IS NULL)) AND (status <> 'VALIDATED' OR (validated_by IS NOT NULL AND approved_by IS NULL AND finalised_by IS NULL)) AND (status <> 'APPROVED' OR (approved_by IS NOT NULL AND finalised_by IS NULL)) AND (status <> 'FINALISED' OR finalised_by IS NOT NULL) AND (status <> 'FAILED' OR finalised_by IS NULL))");
        DB::statement("ALTER TABLE inventory_cutover_batches ADD CONSTRAINT icb_status_check CHECK (status IN ('STAGED', 'VALIDATED', 'COMMITTED', 'FAILED'))");
        DB::statement('ALTER TABLE inventory_cutover_batches ADD CONSTRAINT icb_counts_check CHECK (source_record_count = accepted_record_count + rejected_record_count)');
        DB::statement("ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_method_check CHECK (source_valuation_method IN ('AVCO', 'FIFO', 'SPECIFIC'))");
        DB::statement("ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_scope_check CHECK (source_avco_scope IS NULL OR source_avco_scope IN ('COMPANY_ITEM', 'WAREHOUSE_ITEM', 'COMPANY_ITEM_BATCH'))");
        DB::statement("ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_text_check CHECK (btrim(source_system) <> '' AND btrim(source_entity_type) <> '' AND btrim(source_record_id) <> '')");
        DB::statement('ALTER TABLE inventory_source_facts ADD CONSTRAINT isf_fx_shape_check CHECK ((currency_id IS NULL) = (fx_rate IS NULL) AND (fx_rate IS NULL OR fx_rate > 0))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_inventory_cutover_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'inventory cutovers cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF NEW.company_id <> OLD.company_id OR NEW.mode <> OLD.mode OR NEW.cutover_at <> OLD.cutover_at
                   OR NEW.source_system <> OLD.source_system OR NEW.target_valuation_policy <> OLD.target_valuation_policy
                   OR NEW.control_quantity <> OLD.control_quantity OR NEW.control_value <> OLD.control_value
                   OR NEW.base_control_value <> OLD.base_control_value OR NEW.created_by <> OLD.created_by
                   OR NEW.created_at <> OLD.created_at
                THEN RAISE EXCEPTION 'inventory cutover definition is immutable' USING ERRCODE = '23514'; END IF;
                IF (OLD.validated_by IS NOT NULL AND (NEW.validated_by IS DISTINCT FROM OLD.validated_by OR NEW.validated_at IS DISTINCT FROM OLD.validated_at))
                   OR (OLD.approved_by IS NOT NULL AND (NEW.approved_by IS DISTINCT FROM OLD.approved_by OR NEW.approved_at IS DISTINCT FROM OLD.approved_at))
                   OR (OLD.finalised_by IS NOT NULL AND (NEW.finalised_by IS DISTINCT FROM OLD.finalised_by OR NEW.finalised_at IS DISTINCT FROM OLD.finalised_at))
                THEN RAISE EXCEPTION 'inventory cutover audit decisions are immutable' USING ERRCODE = '23514'; END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('VALIDATED', 'FAILED'))
                    OR (OLD.status = 'VALIDATED' AND NEW.status IN ('APPROVED', 'FAILED'))
                    OR (OLD.status = 'APPROVED' AND NEW.status IN ('FINALISED', 'FAILED'))
                ) THEN RAISE EXCEPTION 'invalid inventory cutover status transition' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_cutovers_guard BEFORE UPDATE OR DELETE ON inventory_cutovers FOR EACH ROW EXECUTE FUNCTION guard_inventory_cutover_mutation();

            CREATE OR REPLACE FUNCTION guard_inventory_cutover_batch_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'inventory cutover batches cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF NEW.company_id <> OLD.company_id OR NEW.inventory_cutover_id <> OLD.inventory_cutover_id
                   OR NEW.import_batch_id <> OLD.import_batch_id OR NEW.entity_type <> OLD.entity_type
                   OR NEW.source_record_count <> OLD.source_record_count OR NEW.accepted_record_count <> OLD.accepted_record_count
                   OR NEW.rejected_record_count <> OLD.rejected_record_count OR NEW.source_quantity <> OLD.source_quantity
                   OR NEW.source_value <> OLD.source_value OR NEW.base_source_value <> OLD.base_source_value
                   OR NEW.created_at <> OLD.created_at
                THEN RAISE EXCEPTION 'inventory cutover batch facts are immutable' USING ERRCODE = '23514'; END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'STAGED' AND NEW.status IN ('VALIDATED', 'FAILED'))
                    OR (OLD.status = 'VALIDATED' AND NEW.status IN ('COMMITTED', 'FAILED'))
                ) THEN RAISE EXCEPTION 'invalid inventory cutover batch status transition' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_cutover_batches_guard BEFORE UPDATE OR DELETE ON inventory_cutover_batches FOR EACH ROW EXECUTE FUNCTION guard_inventory_cutover_batch_mutation();

            CREATE OR REPLACE FUNCTION guard_inventory_source_fact() RETURNS trigger AS $$
            DECLARE linked_import_batch bigint; linked_company bigint; row_import_batch bigint; location_company bigint;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN RAISE EXCEPTION 'inventory source facts are immutable' USING ERRCODE = '23514'; END IF;
                SELECT import_batch_id, company_id INTO linked_import_batch, linked_company FROM inventory_cutover_batches WHERE id = NEW.inventory_cutover_batch_id;
                SELECT import_batch_id INTO row_import_batch FROM import_batch_rows WHERE id = NEW.import_batch_row_id;
                SELECT w.company_id INTO location_company FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.location_id;
                IF linked_import_batch IS DISTINCT FROM row_import_batch OR linked_company IS DISTINCT FROM NEW.company_id
                   OR location_company IS DISTINCT FROM NEW.company_id
                THEN RAISE EXCEPTION 'inventory source fact provenance crosses its cutover scope' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_source_facts_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_source_facts FOR EACH ROW EXECUTE FUNCTION guard_inventory_source_fact();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_inventory_source_fact() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_inventory_cutover_batch_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_inventory_cutover_mutation() CASCADE');
        Schema::dropIfExists('inventory_source_facts');
        Schema::dropIfExists('inventory_cutover_batches');
        Schema::dropIfExists('inventory_cutovers');
    }
};
