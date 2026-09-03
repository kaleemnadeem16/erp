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
            $table->string('negative_stock_mode')->default('BLOCK');
            $table->string('negative_stock_authorisation_mode')->default('PRIOR_APPROVAL');
            $table->unsignedSmallInteger('negative_stock_review_sla_hours')->default(24);
            $table->boolean('negative_stock_allow_self_review')->default(false);
            $table->boolean('negative_stock_sales_despatch_enabled')->default(true);
            $table->boolean('negative_stock_material_consumption_enabled')->default(true);
            $table->boolean('negative_stock_supplier_return_enabled')->default(false);
            $table->boolean('negative_stock_scrap_writeoff_enabled')->default(false);
        });

        Schema::create('inventory_policy_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('policy');
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->text('reason');
            $table->unsignedBigInteger('changed_by');
            $table->timestampTz('changed_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'policy', 'changed_at']);
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_negative_stock_mode_check CHECK (negative_stock_mode IN ('BLOCK', 'AUTHORISED_OVERRIDE'))");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_negative_stock_authorisation_check CHECK (negative_stock_authorisation_mode IN ('PRIOR_APPROVAL', 'IMMEDIATE_WITH_REVIEW'))");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_negative_stock_review_sla_check CHECK (negative_stock_review_sla_hours BETWEEN 1 AND 168)');
        DB::statement('ALTER TABLE inventory_policy_changes ADD CONSTRAINT inventory_policy_changes_actor_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_inventory_policy_change_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'inventory policy audit records are immutable' USING ERRCODE = '23514';
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER inventory_policy_changes_immutable
            BEFORE UPDATE OR DELETE ON inventory_policy_changes
            FOR EACH ROW EXECUTE FUNCTION prevent_inventory_policy_change_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_inventory_policy_change_mutation() CASCADE');
        Schema::dropIfExists('inventory_policy_changes');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_negative_stock_review_sla_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_negative_stock_authorisation_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_negative_stock_mode_check');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'negative_stock_mode', 'negative_stock_authorisation_mode', 'negative_stock_review_sla_hours',
                'negative_stock_allow_self_review', 'negative_stock_sales_despatch_enabled',
                'negative_stock_material_consumption_enabled', 'negative_stock_supplier_return_enabled',
                'negative_stock_scrap_writeoff_enabled',
            ]);
        });
    }
};
