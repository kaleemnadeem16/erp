<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing's own-receipt tolerance policy is as consequential as any
 * Inventory one — it decides how much more than ordered may be received —
 * but had no audit trail at all. Mirrors inventory_policy_changes one-to-one
 * rather than generalising it: the two modules own their own audit history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_policy_changes', function (Blueprint $table) {
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

        DB::statement('ALTER TABLE purchasing_policy_changes ADD CONSTRAINT purchasing_policy_changes_actor_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_purchasing_policy_change_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'purchasing policy audit records are immutable' USING ERRCODE = '23514';
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER purchasing_policy_changes_immutable
            BEFORE UPDATE OR DELETE ON purchasing_policy_changes
            FOR EACH ROW EXECUTE FUNCTION prevent_purchasing_policy_change_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_purchasing_policy_change_mutation() CASCADE');
        Schema::dropIfExists('purchasing_policy_changes');
    }
};
