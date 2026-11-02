<?php

namespace Tests\Feature\Support;

use App\Support\Scope\CompanyAccessFence;
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
