<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_layer_consumptions', function (Blueprint $table) {
            $table->string('purpose')->default('ALLOCATION');
        });
        DB::statement("ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_purpose_check CHECK (purpose IN ('ALLOCATION', 'REVERSAL'))");
        DB::statement('CREATE TRIGGER slc_immutable BEFORE UPDATE OR DELETE ON stock_layer_consumptions FOR EACH ROW EXECUTE FUNCTION prevent_recalculation_workflow_mutation()');
        Schema::table('recalculation_impact_versions', function (Blueprint $table) {
            // Null on legacy versions forces fresh review on their next decision.
            $table->string('dependency_fingerprint', 64)->nullable();
        });
        Schema::create('recalculation_regeneration_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('impact_version_id');
            $table->unsignedBigInteger('reviewed_by');
            $table->unsignedInteger('invalidation_count');
            $table->string('escalation');
            $table->text('notes');
            $table->timestampTz('reviewed_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'impact_version_id']);
        });
        DB::statement('ALTER TABLE recalculation_regeneration_reviews ADD CONSTRAINT rgr_version_fk FOREIGN KEY (company_id, impact_version_id) REFERENCES recalculation_impact_versions (company_id, id)');
        DB::statement('ALTER TABLE recalculation_regeneration_reviews ADD CONSTRAINT rgr_actor_fk FOREIGN KEY (company_id, reviewed_by) REFERENCES users (company_id, id)');
        DB::statement("ALTER TABLE recalculation_regeneration_reviews ADD CONSTRAINT rgr_review_check CHECK (invalidation_count >= 3 AND btrim(notes) <> '' AND escalation IN ('PRIORITY_REVIEW', 'HIGHER_SEVERITY'))");
        DB::statement('CREATE TRIGGER rgr_immutable BEFORE UPDATE OR DELETE ON recalculation_regeneration_reviews FOR EACH ROW EXECUTE FUNCTION prevent_recalculation_workflow_mutation()');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_recalculation_impact_version_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'recalculation impact versions cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.is_stale IS TRUE OR NEW.is_stale IS NOT TRUE
                   OR (to_jsonb(NEW) - 'is_stale' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'is_stale' - 'updated_at')
                THEN RAISE EXCEPTION 'recalculation impact versions are immutable except is_stale false-to-true' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;

            -- Run before ra_guard: serialise raw inserts too, not just Action calls.
            CREATE OR REPLACE FUNCTION lock_recalculation_approval_version() RETURNS trigger AS $$
            DECLARE
                stale boolean;
                requester bigint;
            BEGIN
                SELECT v.is_stale, r.requested_by INTO stale, requester
                    FROM recalculation_impact_versions v JOIN recalculation_requests r ON r.id = v.recalculation_request_id
                    WHERE v.id = NEW.impact_version_id FOR UPDATE OF v;
                IF stale OR requester = NEW.decided_by THEN
                    RAISE EXCEPTION 'approval requires a current version and independent actor' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER ra_00_lock BEFORE INSERT ON recalculation_approvals FOR EACH ROW EXECUTE FUNCTION lock_recalculation_approval_version();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER ra_00_lock ON recalculation_approvals');
        DB::statement('DROP FUNCTION lock_recalculation_approval_version()');
        Schema::dropIfExists('recalculation_regeneration_reviews');
        Schema::table('recalculation_impact_versions', fn (Blueprint $table) => $table->dropColumn('dependency_fingerprint'));
        DB::statement('DROP TRIGGER slc_immutable ON stock_layer_consumptions');
        DB::statement('ALTER TABLE stock_layer_consumptions DROP CONSTRAINT slc_purpose_check');
        Schema::table('stock_layer_consumptions', fn (Blueprint $table) => $table->dropColumn('purpose'));
    }
};
