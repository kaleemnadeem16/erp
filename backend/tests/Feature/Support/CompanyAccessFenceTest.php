<?php

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompanyAccessFenceTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_generation_row_follows_company_deletion_but_direct_deletion_is_refused(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Temporary company', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_access_generations')->insert(['company_id' => $company, 'generation' => 3]);
        try {
            DB::transaction(fn () => DB::table('company_access_generations')->where('company_id', $company)->delete());
            $this->fail('A live company access generation cannot be deleted directly.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        DB::table('companies')->where('id', $company)->delete();
        $this->assertDatabaseMissing('company_access_generations', ['company_id' => $company]);
    }

    public function test_an_enclosing_transaction_is_required(): void
    {
        $this->expectException(LogicException::class);
        app(CompanyAccessFence::class)->lock();
    }

    public function test_populated_barrier_refuses_downgrade_before_dropping_triggers(): void
    {
        User::factory()->create();
        $migration = require database_path('migrations/2026_11_01_110000_fence_company_access_security_writers.php');
        try {
            DB::transaction(fn () => $migration->down());
            $this->fail('A live security barrier must not be removed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('requires a separately approved backout', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('pg_trigger')->where('tgname', 'company_access_security_barrier')->whereRaw("tgrelid = 'users'::regclass")->count());
    }

    public function test_raw_security_writers_use_the_barrier_and_advance_epochs_atomically(): void
    {
        $user = User::factory()->create();
        $before = (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation');
        DB::table('users')->where('id', $user->id)->update(['status' => 'SUSPENDED']);
        $after = (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation');
        $this->assertGreaterThan($before, $after);

        DB::beginTransaction();
        DB::table('users')->where('id', $user->id)->update(['status' => 'ACTIVE']);
        $this->assertGreaterThan($after, (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation'));
        DB::rollBack();
        $this->assertSame($after, (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation'));
        $this->assertSame('SUSPENDED', DB::table('users')->where('id', $user->id)->value('status'));

        try {
            DB::table('company_access_generations')->where('company_id', 1)->update(['generation' => 0]);
            $this->fail('A generation cannot be decreased.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_role_direct_scope_and_unit_membership_writers_advance_the_shared_barrier(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $user = User::factory()->create();
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $permission = Permission::findOrCreate('fence-fixture', 'web');
        $role = Role::create(['team_id' => 1, 'name' => 'Fence role']);

        $generation = fn (int $company): int => (int) DB::table('company_access_generations')->where('company_id', $company)->value('generation');
        $beforeOne = $generation(1);
        $beforeOther = $generation($otherCompany);
        $user->givePermissionTo($permission);
        $this->assertGreaterThan($beforeOne, $generation(1));
        $this->assertGreaterThan($beforeOther, $generation($otherCompany));

        $beforeOne = $generation(1);
        $user->assignRole($role);
        $this->assertGreaterThan($beforeOne, $generation(1));
        $beforeOne = $generation(1);
        $role->givePermissionTo($permission);
        $this->assertGreaterThan($beforeOne, $generation(1));

        $beforeOne = $generation(1);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $user->id, 'permission' => 'fence-fixture', 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan($beforeOne, $generation(1));

        $beforeOrganisation = (int) DB::table('company_access_generations')->where('company_id', 1)->value('organisation_generation');
        $unit = DB::table('organisation_units')->insertGetId(['company_id' => 1, 'code' => 'FENCE', 'name' => 'Fence', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan($beforeOrganisation, (int) DB::table('company_access_generations')->where('company_id', 1)->value('organisation_generation'));
    }

    private function otherSession(): PDO
    {
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec("SET lock_timeout = '250ms'");

        return $other;
    }

    public function test_a_sign_in_never_fences_but_a_membership_or_status_change_still_does(): void
    {
        $user = User::factory()->create();
        $generation = fn (): int => (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation');
        $before = $generation();

        // What a login does (AuthController): only the sign-in timestamp (and, elsewhere, remember tokens, names, passwords) is written.
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());
        DB::table('users')->where('id', $user->id)->update(['name' => 'Renamed', 'remember_token' => 'abc']);
        $this->assertSame($before, $generation(), 'a sign-in or profile write advances no generation');

        DB::table('users')->where('id', $user->id)->update(['status' => 'SUSPENDED']);
        $afterStatus = $generation();
        $this->assertGreaterThan($before, $afterStatus);
        DB::table('users')->where('id', $user->id)->update(['company_id' => 1]);
        $this->assertGreaterThan($afterStatus, $generation(), 'setting the membership column fences even to the same value');
        $this->assertSame(1, DB::table('pg_trigger')->where('tgname', 'company_access_security_barrier_columns')->whereRaw("tgrelid = 'users'::regclass")->count());
    }

    public function test_a_sign_in_does_not_wait_for_a_protected_read_or_an_issue_but_a_status_change_does(): void
    {
        $user = User::factory()->create();
        $other = $this->otherSession();
        $login = $other->prepare('UPDATE users SET last_login_at = now() WHERE id = ?');
        $status = $other->prepare("UPDATE users SET status = 'SUSPENDED' WHERE id = ?");

        // A mutation (an issue takes the all-company fence first) and a protected read are both open on the main connection.
        foreach (['lockAll', 'lock'] as $holder) {
            DB::beginTransaction();
            try {
                app(CompanyAccessFence::class)->{$holder}();
                $login->execute([$user->id]);
                $this->assertSame(1, $login->rowCount(), "a login proceeds while {$holder} is held");
                try {
                    $status->execute([$user->id]);
                    $this->fail('A status change must wait for the open decision.');
                } catch (PDOException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }
                DB::commit();
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        }
    }

    public function test_a_deadlock_or_serialisation_victim_restarts_the_whole_operation_only_when_outermost(): void
    {
        $user = User::factory()->create();
        $context = SalesActorContext::forUser($user, 'fixture');
        $family = DB::table('quotation_families')->insertGetId(['company_id' => 1, 'created_by' => $user->id, 'responsible_owner_id' => null, 'ownership_generation' => 0,
            'access_baseline_state' => 'UNRESOLVED', 'created_at' => now(), 'updated_at' => now()]);
        $victim = function (): QueryException {
            $pdo = new PDOException('deadlock detected');
            $pdo->errorInfo = ['40P01', 7, 'deadlock detected'];

            return new QueryException('testing', 'select 1', [], $pdo);
        };
        $operation = app(QuotationOperation::class);

        // One victim, then success: the work and the subject lock ran again from the start, and the receipt is stored once.
        $locks = 0;
        $calls = 0;
        $out = $operation->run($context, 'fixture', 'key-1', ['x' => 1], function () use (&$locks): int {
            return ++$locks;
        }, function () use (&$calls, $victim, $family): array {
            if (++$calls === 1) {
                throw $victim();
            }

            return ['ok' => true, 'family_id' => $family];
        });
        $this->assertSame([false, 2, 2], [$out['replayed'], $locks, $calls]);

        // Persistent victims stop after the bounded number of restarts; a caller's transaction is never restarted underneath it.
        $calls = 0;
        try {
            $operation->run($context, 'fixture', 'key-2', ['x' => 2], fn () => null, function () use (&$calls, $victim): array {
                $calls++;
                throw $victim();
            });
            $this->fail('Persistent deadlocks must surface.');
        } catch (PDOException) {
            $this->assertSame(QuotationOperation::DEADLOCK_RETRIES + 1, $calls);
        }
        $calls = 0;
        DB::beginTransaction();
        try {
            $operation->run($context, 'fixture', 'key-3', ['x' => 3], fn () => null, function () use (&$calls, $victim): array {
                $calls++;
                throw $victim();
            });
            $this->fail('A caller-owned transaction is not restarted.');
        } catch (PDOException) {
            $this->assertSame(1, $calls);
        } finally {
            DB::rollBack();
        }
    }

    public function test_every_table_that_can_change_access_is_behind_the_barrier_or_named_as_exempt(): void
    {
        // Ratchet: any table with a foreign key to an authority source (roles, permissions, organisation units, users' membership via those) must carry the statement-level
        // barrier, or be listed here with the reason it cannot change a quotation access decision. A new writer of such a table fails this test until it is fenced or justified.
        $fenced = collect(DB::select("select c.relname as t from pg_trigger g join pg_class c on c.oid = g.tgrelid where g.tgname like 'company_access_security_barrier%' and not g.tgisinternal"))->pluck('t')->unique()->all();
        $referencing = collect(DB::select("select distinct c.relname as t from pg_constraint k join pg_class c on c.oid = k.conrelid join pg_class r on r.oid = k.confrelid
            where k.contype = 'f' and r.relname in ('roles', 'permissions', 'organisation_units', 'access_scope_limits')"))->pluck('t')->unique()->all();
        $exempt = [
            // table => why it cannot change who may see or change a quotation family
            'approval_delegations' => 'approval engine delegation, a different authority domain than quotation family access',
            'quotation_ownership_events' => 'append-only history of a change; the change itself updates quotation_families, which is fenced',
            'approval_rules' => 'approval routing configuration, not a record-access grant',
        ];
        $unfenced = array_values(array_diff($referencing, $fenced, array_keys($exempt)));
        sort($unfenced);
        $this->assertSame([], $unfenced, 'These tables reference an authority source but are neither fenced nor justified as exempt: '.implode(', ', $unfenced));
        foreach (['users', 'quotation_families', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'access_scope_limits', 'organisation_units', 'organisation_unit_members', 'organisation_unit_resources'] as $table) {
            $this->assertContains($table, $fenced, "{$table} must stay behind the barrier");
        }
    }

    public function test_raw_family_writer_waits_for_a_protected_read(): void
    {
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec("SET lock_timeout = '250ms'");

        DB::beginTransaction();
        try {
            app(CompanyAccessFence::class)->lock();
            try {
                $other->exec('UPDATE quotation_families SET access_unit_id = NULL WHERE id = -1');
                $this->fail('A raw family writer bypassed the barrier.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(0, $other->exec('UPDATE quotation_families SET access_unit_id = NULL WHERE id = -1'));
    }

    public function test_raw_member_status_change_waits_for_a_protected_read(): void
    {
        $user = User::factory()->create();
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec("SET lock_timeout = '250ms'");
        $change = $other->prepare("UPDATE users SET status = 'SUSPENDED' WHERE id = ?");

        DB::beginTransaction();
        try {
            app(CompanyAccessFence::class)->lock();
            try {
                $change->execute([$user->id]);
                $this->fail('A raw security writer bypassed the barrier.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $change->execute([$user->id]);
        $this->assertSame('SUSPENDED', DB::table('users')->where('id', $user->id)->value('status'));
    }

    public function test_shared_read_fence_blocks_an_independent_security_writer_until_commit(): void
    {
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec("SET lock_timeout = '250ms'");

        DB::beginTransaction();
        try {
            $this->assertSame(0, app(CompanyAccessFence::class)->lock()->generation);
            try {
                $other->exec('UPDATE company_access_generations SET generation = generation + 1 WHERE company_id = 1');
                $this->fail('A security writer bypassed the shared fence.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(1, $other->exec('UPDATE company_access_generations SET generation = generation + 1 WHERE company_id = 1'));
        DB::transaction(fn () => $this->assertSame(1, app(CompanyAccessFence::class)->lock(true)->generation));
    }
}
