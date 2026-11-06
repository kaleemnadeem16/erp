<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0155: the receipt of the create-once bootstrap of the synthetic demo access operator. One row per (company, operator) records the EXACT duty set that was handed out and who/what did
 * it. The row is append-only (UPDATE, DELETE and TRUNCATE are refused by the database), so a later rerun can prove the grant set was fixed at success and refuses a different one; the
 * bootstrap Action never replenishes grants from it. The table grants nothing and is read by nothing except that Action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_access_operator_bootstraps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('user_id');
            $t->string('email', 255);
            $t->jsonb('grants');
            $t->string('provenance', 255);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'user_id'], 'demo_access_operator_bootstraps_once');
            $t->foreign(['company_id', 'user_id'], 'demo_access_operator_bootstraps_user_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE demo_access_operator_bootstraps ADD CONSTRAINT demo_access_operator_bootstraps_shape CHECK (jsonb_typeof(grants) = 'array' AND jsonb_array_length(grants) > 0 AND length(trim(provenance)) > 0)");
        DB::unprepared("CREATE OR REPLACE FUNCTION demo_access_operator_bootstraps_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'The demo access operator bootstrap receipt is append-only.' USING ERRCODE = 'integrity_constraint_violation'; END \$\$");
        DB::statement('CREATE TRIGGER demo_access_operator_bootstraps_immutable BEFORE UPDATE OR DELETE ON demo_access_operator_bootstraps FOR EACH ROW EXECUTE FUNCTION demo_access_operator_bootstraps_immutable()');
        DB::statement('CREATE TRIGGER demo_access_operator_bootstraps_no_truncate BEFORE TRUNCATE ON demo_access_operator_bootstraps FOR EACH STATEMENT EXECUTE FUNCTION demo_access_operator_bootstraps_immutable()');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE demo_access_operator_bootstraps IN ACCESS EXCLUSIVE MODE');
        if (DB::table('demo_access_operator_bootstraps')->exists()) {
            throw new RuntimeException('A demo access operator bootstrap receipt exists; rolling back would erase evidence.');
        }
        Schema::dropIfExists('demo_access_operator_bootstraps');
        DB::statement('DROP FUNCTION IF EXISTS demo_access_operator_bootstraps_immutable()');
    }
};
