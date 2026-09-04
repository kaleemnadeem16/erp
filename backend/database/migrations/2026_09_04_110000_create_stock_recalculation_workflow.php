<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('authorised_forward_recalculation_enabled')->default(false);
            $table->unsignedSmallInteger('recalculation_review_sla_hours')->default(72);
            $table->unsignedSmallInteger('recalculation_approval_ttl_hours')->default(24);
        });
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_recalculation_review_sla_check CHECK (recalculation_review_sla_hours BETWEEN 1 AND 168)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_recalculation_approval_ttl_check CHECK (recalculation_approval_ttl_hours BETWEEN 1 AND 168)');

        Schema::create('recalculation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('item_id');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->decimal('qty', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->foreignId('currency_id')->constrained();
            $table->decimal('fx_rate', 24, 9);
            $table->decimal('base_unit_cost', 19, 4);
            $table->timestampTz('moved_at');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('horizon_mode');
            $table->date('horizon_permitted_floor')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->timestampTz('requested_at');
            $table->timestampTz('review_due_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('recalculation_impact_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('recalculation_request_id');
            $table->unsignedSmallInteger('version_no');
            $table->unsignedBigInteger('avco_pool_id');
            $table->decimal('original_qty_on_hand', 19, 4);
            $table->decimal('original_inventory_value', 19, 4);
            $table->decimal('original_avg_unit_cost', 19, 4);
            $table->decimal('counterfactual_qty_on_hand', 19, 4);
            $table->decimal('counterfactual_inventory_value', 19, 4);
            $table->decimal('counterfactual_avg_unit_cost', 19, 4);
            $table->decimal('valuation_adjustment', 19, 4);
            $table->timestampTz('computed_at');
            $table->boolean('is_stale')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['recalculation_request_id', 'version_no']);
        });

        Schema::create('recalculation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('impact_version_id');
            $table->string('decision');
            $table->text('notes');
            $table->unsignedBigInteger('decided_by');
            $table->timestampTz('decided_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'impact_version_id']);
        });

        DB::statement('ALTER TABLE recalculation_requests ADD CONSTRAINT rr_item_company_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE recalculation_requests ADD CONSTRAINT rr_batch_company_fk FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)');
        DB::statement('ALTER TABLE recalculation_requests ADD CONSTRAINT rr_actor_company_fk FOREIGN KEY (company_id, requested_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_request_company_fk FOREIGN KEY (company_id, recalculation_request_id) REFERENCES recalculation_requests (company_id, id)');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_pool_company_fk FOREIGN KEY (company_id, avco_pool_id) REFERENCES avco_pools (company_id, id)');
        DB::statement('ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_version_company_fk FOREIGN KEY (company_id, impact_version_id) REFERENCES recalculation_impact_versions (company_id, id)');
        DB::statement('ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_actor_company_fk FOREIGN KEY (company_id, decided_by) REFERENCES users (company_id, id)');

        DB::statement("ALTER TABLE recalculation_requests ADD CONSTRAINT rr_horizon_mode_check CHECK (horizon_mode IN ('CURRENT_AND_PREVIOUS_PERIOD', 'PERIOD_LOCK_ONLY', 'POSTING_FLOOR'))");
        DB::statement('ALTER TABLE recalculation_requests ADD CONSTRAINT rr_qty_check CHECK (qty > 0 AND unit_cost >= 0 AND base_unit_cost > 0 AND fx_rate > 0)');
        DB::statement("ALTER TABLE recalculation_requests ADD CONSTRAINT rr_text_check CHECK (btrim(source_type) <> '')");
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_version_no_check CHECK (version_no > 0)');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_qty_check CHECK (original_qty_on_hand > 0 AND counterfactual_qty_on_hand > 0)');
        DB::statement('ALTER TABLE recalculation_impact_versions ADD CONSTRAINT riv_adjustment_check CHECK (valuation_adjustment = counterfactual_inventory_value - original_inventory_value)');
        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_decision_check CHECK (decision IN ('APPROVED', 'REJECTED'))");
        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_notes_check CHECK (btrim(notes) <> '')");
        DB::statement("ALTER TABLE recalculation_approvals ADD CONSTRAINT ra_expiry_shape_check CHECK ((decision = 'APPROVED') = (expires_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_recalculation_workflow_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'recalculation workflow records are immutable' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER rr_immutable BEFORE UPDATE OR DELETE ON recalculation_requests FOR EACH ROW EXECUTE FUNCTION prevent_recalculation_workflow_mutation();
            CREATE TRIGGER ra_immutable BEFORE UPDATE OR DELETE ON recalculation_approvals FOR EACH ROW EXECUTE FUNCTION prevent_recalculation_workflow_mutation();

            -- Impact versions are append-only except for one controlled transition:
            -- is_stale flipping false -> true when a replacement version supersedes
            -- them. Every other column, and any other direction of is_stale, is
            -- rejected the same as a full update would be on the tables above.
            CREATE OR REPLACE FUNCTION prevent_recalculation_impact_version_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'recalculation impact versions cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.is_stale IS TRUE OR NEW.is_stale IS NOT TRUE
                   OR NEW.id <> OLD.id OR NEW.company_id <> OLD.company_id
                   OR NEW.recalculation_request_id <> OLD.recalculation_request_id
                   OR NEW.version_no <> OLD.version_no OR NEW.avco_pool_id <> OLD.avco_pool_id
                   OR NEW.original_qty_on_hand <> OLD.original_qty_on_hand
                   OR NEW.original_inventory_value <> OLD.original_inventory_value
                   OR NEW.original_avg_unit_cost <> OLD.original_avg_unit_cost
                   OR NEW.counterfactual_qty_on_hand <> OLD.counterfactual_qty_on_hand
                   OR NEW.counterfactual_inventory_value <> OLD.counterfactual_inventory_value
                   OR NEW.counterfactual_avg_unit_cost <> OLD.counterfactual_avg_unit_cost
                   OR NEW.valuation_adjustment <> OLD.valuation_adjustment
                   OR NEW.computed_at <> OLD.computed_at
                THEN RAISE EXCEPTION 'recalculation impact versions are immutable except is_stale false-to-true' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER riv_guard BEFORE UPDATE OR DELETE ON recalculation_impact_versions FOR EACH ROW EXECUTE FUNCTION prevent_recalculation_impact_version_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_recalculation_impact_version_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS prevent_recalculation_workflow_mutation() CASCADE');
        Schema::dropIfExists('recalculation_approvals');
        Schema::dropIfExists('recalculation_impact_versions');
        Schema::dropIfExists('recalculation_requests');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_recalculation_approval_ttl_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_recalculation_review_sla_check');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['authorised_forward_recalculation_enabled', 'recalculation_review_sla_hours', 'recalculation_approval_ttl_hours']);
        });
    }
};
