<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Conservative first barrier: every security-writer statement serialises all companies in ID
 * order. This deliberately over-invalidates, but also covers raw DML and roles shared across
 * companies without confusing a Spatie team ID with a company ID. No quotation access is
 * enabled by this migration.
 */
return new class extends Migration
{
    /** Fenced on every write. `users` is fenced separately and column-aware (below): a sign-in timestamp or a profile edit must not serialise every tenant. */
    private const TABLES = [
        'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
        'role_has_permissions', 'access_scope_limits', 'organisation_units',
        'organisation_unit_members', 'organisation_unit_resources',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION company_access_generation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM companies WHERE id = OLD.company_id) THEN RETURN OLD; END IF;
 IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Access generations cannot be removed' USING ERRCODE = '23514'; END IF;
 IF NEW.company_id <> OLD.company_id OR NEW.generation < OLD.generation OR NEW.organisation_generation < OLD.organisation_generation OR NEW.capability_generation < OLD.capability_generation THEN
  RAISE EXCEPTION 'Access generations cannot decrease' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER company_access_generation_update_guard BEFORE UPDATE OR DELETE ON company_access_generations FOR EACH ROW EXECUTE FUNCTION company_access_generation_guard();
CREATE TRIGGER company_access_generation_truncate_guard BEFORE TRUNCATE ON company_access_generations FOR EACH STATEMENT EXECUTE FUNCTION company_access_generation_guard();

CREATE OR REPLACE FUNCTION company_access_security_writer_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cid bigint;
BEGIN
 INSERT INTO company_access_generations(company_id)
   SELECT id FROM companies ORDER BY id ON CONFLICT DO NOTHING;
 FOR cid IN SELECT company_id FROM company_access_generations ORDER BY company_id FOR UPDATE LOOP
  UPDATE company_access_generations SET generation = generation + 1,
   organisation_generation = organisation_generation + CASE WHEN TG_TABLE_NAME IN ('organisation_units','organisation_unit_members','organisation_unit_resources') THEN 1 ELSE 0 END,
   capability_generation = capability_generation + CASE WHEN TG_TABLE_NAME IN ('roles','permissions','role_has_permissions') THEN 1 ELSE 0 END
  WHERE company_id = cid;
 END LOOP;
 RETURN NULL;
END $$;
SQL);
        // A user row changes a decision only when its membership, status or authorization generation changes (or the row appears, goes or is truncated). The column list
        // fires on any statement that SETS one of these columns; `last_login_at`, remember tokens, names and passwords never fence.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR DELETE OR TRUNCATE ON users FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier();
CREATE TRIGGER company_access_security_barrier_columns BEFORE UPDATE OF company_id, status, authorization_generation ON users FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier();
SQL);
        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier()");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE company_access_generations IN ACCESS EXCLUSIVE MODE');
        if (DB::table('company_access_generations')->where('generation', '<>', 0)->exists()) {
            throw new RuntimeException('Access generations are in use; disabling the security barrier requires a separately approved backout.');
        }
        DB::statement('DROP TRIGGER IF EXISTS company_access_security_barrier ON users');
        DB::statement('DROP TRIGGER IF EXISTS company_access_security_barrier_columns ON users');
        foreach (self::TABLES as $table) {
            DB::statement("DROP TRIGGER IF EXISTS company_access_security_barrier ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS company_access_generation_update_guard ON company_access_generations');
        DB::statement('DROP TRIGGER IF EXISTS company_access_generation_truncate_guard ON company_access_generations');
        DB::statement('DROP FUNCTION IF EXISTS company_access_generation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS company_access_security_writer_barrier()');
    }
};
