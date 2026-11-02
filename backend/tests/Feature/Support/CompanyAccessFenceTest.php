<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
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
