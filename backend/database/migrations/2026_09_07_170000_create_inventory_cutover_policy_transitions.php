<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cutover_policy_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('inventory_cutover_id');
            $table->unsignedBigInteger('item_id');
            $table->string('source_valuation_method');
            $table->string('source_avco_scope')->nullable();
            $table->string('source_pool_identity')->nullable();
            $table->string('target_valuation_method');
            $table->string('target_avco_scope')->nullable();
            $table->timestampTz('effective_at');
            $table->jsonb('revaluation_basis')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });
        DB::statement('ALTER TABLE inventory_cutover_policy_transitions ADD CONSTRAINT icpt_cutover_fk FOREIGN KEY (company_id, inventory_cutover_id) REFERENCES inventory_cutovers (company_id, id)');
        DB::statement('ALTER TABLE inventory_cutover_policy_transitions ADD CONSTRAINT icpt_item_fk FOREIGN KEY (company_id, item_id) REFERENCES items (company_id, id)');
        DB::statement("ALTER TABLE inventory_cutover_policy_transitions ADD CONSTRAINT icpt_source_method_check CHECK (source_valuation_method IN ('AVCO', 'FIFO', 'SPECIFIC'))");
        DB::statement("ALTER TABLE inventory_cutover_policy_transitions ADD CONSTRAINT icpt_target_method_check CHECK (target_valuation_method IN ('AVCO', 'FIFO', 'SPECIFIC'))");
        DB::statement("ALTER TABLE inventory_cutover_policy_transitions ADD CONSTRAINT icpt_source_scope_check CHECK (source_avco_scope IS NULL OR source_avco_scope IN ('COMPANY_ITEM', 'WAREHOUSE_ITEM', 'COMPANY_ITEM_BATCH'))");
        DB::statement("ALTER TABLE inventory_cutover_policy_transitions ADD CONSTRAINT icpt_target_scope_check CHECK ((target_valuation_method = 'AVCO') = (target_avco_scope IS NOT NULL) AND (target_avco_scope IS NULL OR target_avco_scope IN ('COMPANY_ITEM', 'WAREHOUSE_ITEM', 'COMPANY_ITEM_BATCH')))");
        DB::statement("CREATE UNIQUE INDEX inventory_cutover_policy_transition_identity ON inventory_cutover_policy_transitions (company_id, inventory_cutover_id, item_id, source_valuation_method, COALESCE(source_avco_scope, ''), COALESCE(source_pool_identity, ''))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_inventory_cutover_policy_transition() RETURNS trigger AS $$
            DECLARE cutover_time timestamptz; source_exists boolean;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'inventory cutover policy transitions are immutable' USING ERRCODE = '23514';
                END IF;
                SELECT cutover_at INTO cutover_time FROM inventory_cutovers
                    WHERE company_id = NEW.company_id AND id = NEW.inventory_cutover_id;
                SELECT EXISTS (
                    SELECT 1 FROM inventory_source_facts f
                    WHERE f.company_id = NEW.company_id AND f.inventory_cutover_id = NEW.inventory_cutover_id
                      AND f.item_id = NEW.item_id AND f.source_valuation_method = NEW.source_valuation_method
                      AND f.source_avco_scope IS NOT DISTINCT FROM NEW.source_avco_scope
                      AND f.source_valuation_pool_identity IS NOT DISTINCT FROM NEW.source_pool_identity
                ) INTO source_exists;
                IF cutover_time IS DISTINCT FROM NEW.effective_at OR NOT source_exists THEN
                    RAISE EXCEPTION 'policy transition must match its cutover timestamp and source facts' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_cutover_policy_transitions_guard
                BEFORE INSERT OR UPDATE OR DELETE ON inventory_cutover_policy_transitions
                FOR EACH ROW EXECUTE FUNCTION guard_inventory_cutover_policy_transition();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_inventory_cutover_policy_transition() CASCADE');
        Schema::dropIfExists('inventory_cutover_policy_transitions');
    }
};
