<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Permanent invalidation for future pinned quotation shares; the existing security fence still runs first. */
return new class extends Migration
{
    private const ASSIGNMENTS = ['model_has_roles', 'model_has_permissions', 'access_scope_limits'];

    public function up(): void
    {
        $morph = DB::getPdo()->quote((new User)->getMorphClass());
        if (! is_string($morph)) {
            throw new RuntimeException('Cannot quote the user morph identity for actor epoch guards.');
        }
        DB::unprepared(str_replace('__USER_MORPH__', $morph, <<<'SQL'
CREATE OR REPLACE FUNCTION quotation_actor_epoch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.authorization_generation < OLD.authorization_generation THEN
  RAISE EXCEPTION 'Actor authorization generations cannot decrease' USING ERRCODE = '23514';
 END IF;
 IF NEW.company_id IS DISTINCT FROM OLD.company_id OR NEW.status IS DISTINCT FROM OLD.status THEN
  NEW.authorization_generation := GREATEST(NEW.authorization_generation, OLD.authorization_generation + 1);
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_actor_epoch_security BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION quotation_actor_epoch_guard();

CREATE OR REPLACE FUNCTION quotation_actor_epoch_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old_user bigint; new_user bigint; target bigint;
BEGIN
 PERFORM set_config('lock_timeout', LEAST(CASE WHEN setting::bigint = 0 THEN 1000 ELSE setting::bigint END, 1000)::text, true)
  FROM pg_settings WHERE name = 'lock_timeout';
 -- All these stores already acquire the exclusive all-company security fence BEFORE the
 -- statement. Row/user locks below therefore follow the same global order as real Actions.
 IF TG_TABLE_NAME = 'access_scope_limits' THEN
  IF TG_OP <> 'INSERT' THEN old_user := OLD.user_id; END IF;
  IF TG_OP <> 'DELETE' THEN new_user := NEW.user_id; END IF;
 ELSE
  IF TG_OP <> 'INSERT' THEN
   IF OLD.model_type = __USER_MORPH__ THEN old_user := OLD.model_id; END IF;
  END IF;
  IF TG_OP <> 'DELETE' THEN
   IF NEW.model_type = __USER_MORPH__ THEN new_user := NEW.model_id; END IF;
  END IF;
 END IF;
 FOR target IN SELECT id FROM users WHERE id = old_user OR id = new_user ORDER BY id LOOP
  UPDATE users SET authorization_generation = authorization_generation + 1 WHERE id = target;
 END LOOP;
 RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION quotation_actor_epoch_assignment_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target bigint;
BEGIN
 PERFORM set_config('lock_timeout', LEAST(CASE WHEN setting::bigint = 0 THEN 1000 ELSE setting::bigint END, 1000)::text, true)
  FROM pg_settings WHERE name = 'lock_timeout';
 -- TRUNCATE has no row images. Conservative permanent invalidation includes every actor,
 -- so restoring a truncated assignment store cannot revive an earlier pinned share.
 FOR target IN SELECT id FROM users ORDER BY id LOOP
  UPDATE users SET authorization_generation = authorization_generation + 1 WHERE id = target;
 END LOOP;
 RETURN NULL;
END $$;
SQL));
        foreach (self::ASSIGNMENTS as $table) {
            DB::statement("CREATE TRIGGER quotation_actor_epoch_assignment AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION quotation_actor_epoch_assignment()");
            DB::statement("CREATE TRIGGER quotation_actor_epoch_assignment_truncate AFTER TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION quotation_actor_epoch_assignment_truncate()");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
        if (DB::table('users')->where('authorization_generation', '<>', 0)->exists()
            || (Schema::hasTable('quotation_access_grants') && DB::table('quotation_access_grants')->exists())) {
            throw new RuntimeException('Actor authorization epochs are in use; disabling permanent invalidation needs a separately approved backout.');
        }
        foreach (self::ASSIGNMENTS as $table) {
            DB::statement("DROP TRIGGER IF EXISTS quotation_actor_epoch_assignment ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS quotation_actor_epoch_assignment_truncate ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS quotation_actor_epoch_security ON users');
        DB::statement('DROP FUNCTION IF EXISTS quotation_actor_epoch_assignment()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_actor_epoch_assignment_truncate()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_actor_epoch_guard()');
    }
};
