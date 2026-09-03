<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negative_stock_override_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('movement_class');
            $table->decimal('requested_qty', 19, 4);
            $table->decimal('available_qty', 19, 4);
            $table->decimal('shortage_qty', 19, 4);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('reason_code');
            $table->text('explanation');
            $table->string('authorisation_mode');
            $table->boolean('allow_self_review');
            $table->unsignedBigInteger('requested_by');
            $table->timestampTz('requested_at');
            $table->timestampTz('review_due_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'authorisation_mode', 'requested_at']);
        });
        Schema::create('negative_stock_override_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('request_id');
            $table->string('decision');
            $table->text('notes');
            $table->unsignedBigInteger('decided_by');
            $table->timestampTz('decided_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'request_id']);
        });
        Schema::create('negative_stock_override_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('request_id');
            $table->string('outcome');
            $table->text('notes');
            $table->unsignedBigInteger('reviewed_by');
            $table->timestampTz('reviewed_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'request_id']);
        });

        DB::statement('ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_batch_company_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_actor_company_fk FOREIGN KEY (company_id, requested_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_override_approvals ADD CONSTRAINT nsoa_request_company_fk FOREIGN KEY (company_id, request_id) REFERENCES negative_stock_override_requests (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_override_approvals ADD CONSTRAINT nsoa_actor_company_fk FOREIGN KEY (company_id, decided_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_override_reviews ADD CONSTRAINT nsov_request_company_fk FOREIGN KEY (company_id, request_id) REFERENCES negative_stock_override_requests (company_id, id)');
        DB::statement('ALTER TABLE negative_stock_override_reviews ADD CONSTRAINT nsov_actor_company_fk FOREIGN KEY (company_id, reviewed_by) REFERENCES users (company_id, id)');
        DB::statement("ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_class_check CHECK (movement_class IN ('SALES_DESPATCH','MATERIAL_CONSUMPTION','SUPPLIER_RETURN','SCRAP_WRITEOFF'))");
        DB::statement("ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_reason_check CHECK (reason_code IN ('LATE_RECEIPT','PAPERWORK_DELAY','OPERATIONAL_EMERGENCY','OTHER'))");
        DB::statement("ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_mode_check CHECK (authorisation_mode IN ('PRIOR_APPROVAL','IMMEDIATE_WITH_REVIEW'))");
        DB::statement('ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_qty_check CHECK (requested_qty > 0 AND available_qty >= 0 AND shortage_qty > 0 AND shortage_qty = requested_qty - available_qty)');
        DB::statement("ALTER TABLE negative_stock_override_requests ADD CONSTRAINT nsor_text_check CHECK (btrim(source_type) <> '' AND btrim(explanation) <> '')");
        DB::statement("ALTER TABLE negative_stock_override_approvals ADD CONSTRAINT nsoa_decision_check CHECK (decision IN ('APPROVED','REJECTED'))");
        DB::statement("ALTER TABLE negative_stock_override_approvals ADD CONSTRAINT nsoa_notes_check CHECK (btrim(notes) <> '')");
        DB::statement("ALTER TABLE negative_stock_override_reviews ADD CONSTRAINT nsov_outcome_check CHECK (outcome IN ('JUSTIFIED','FLAGGED'))");
        DB::statement("ALTER TABLE negative_stock_override_reviews ADD CONSTRAINT nsov_notes_check CHECK (btrim(notes) <> '')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_negative_override_scope() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.location_id AND w.company_id = NEW.company_id)
                   OR (NEW.batch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stock_batches b WHERE b.id = NEW.batch_id AND b.company_id = NEW.company_id AND b.item_id = NEW.item_id))
                THEN RAISE EXCEPTION 'negative stock request references incompatible location or batch' USING ERRCODE = '23503'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER nsor_scope BEFORE INSERT OR UPDATE ON negative_stock_override_requests FOR EACH ROW EXECUTE FUNCTION enforce_negative_override_scope();
            CREATE OR REPLACE FUNCTION prevent_negative_override_workflow_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'negative stock workflow records are immutable' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER nsor_immutable BEFORE UPDATE OR DELETE ON negative_stock_override_requests FOR EACH ROW EXECUTE FUNCTION prevent_negative_override_workflow_mutation();
            CREATE TRIGGER nsoa_immutable BEFORE UPDATE OR DELETE ON negative_stock_override_approvals FOR EACH ROW EXECUTE FUNCTION prevent_negative_override_workflow_mutation();
            CREATE TRIGGER nsov_immutable BEFORE UPDATE OR DELETE ON negative_stock_override_reviews FOR EACH ROW EXECUTE FUNCTION prevent_negative_override_workflow_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_negative_override_workflow_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS enforce_negative_override_scope() CASCADE');
        Schema::dropIfExists('negative_stock_override_reviews');
        Schema::dropIfExists('negative_stock_override_approvals');
        Schema::dropIfExists('negative_stock_override_requests');
    }
};
