<?php

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Phase 2 acceptance criterion, proven for real: "simultaneous receipts into
 * one AVCO pool produce the mathematically correct quantity/value and average
 * under a real PostgreSQL concurrency test." Two genuinely separate OS
 * processes (pcntl_fork) each post a receipt into the same pool through
 * PostInventoryMovement; without AvcoPoolManager's row lock both would read
 * the same pre-update pool state and one receipt's value would silently
 * vanish from the average.
 *
 * Same technique and the same reasoning as QualityDispositionPostingTest's
 * concurrency test: the fixture is built via raw, immediately-committed PDO
 * inserts (RefreshDatabase's uncommitted transaction is invisible to other
 * connections) under a throwaway company, and each process uses its own
 * separately-named connection so a savepoint on the ambient 'testing'
 * connection can never hold the row lock forever.
 *
 * Unlike that test, everything here IS torn down: the committed moves would
 * otherwise sit in stock_moves for the rest of the run and break every other
 * test's exact `assertDatabaseCount('stock_moves', …)`. The ledger is
 * database-immutable by design, so the teardown alone switches those guards
 * off for its own deletes — a test-fixture escape hatch, never application
 * behaviour.
 */
class AvcoPoolConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_concurrent_receipts_into_one_pool_produce_the_exact_weighted_average(): void
    {
        $config = config('database.connections.testing');
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $companyId = (int) $pdo->query("insert into companies (name, created_at, updated_at) values ('AVCO Pool Concurrency Test Company', now(), now()) returning id")->fetchColumn();
        $suffix = bin2hex(random_bytes(4));
        [$itemId, $locationId, $uomId, $currencyId] = $this->createRawFixture($pdo, $companyId, "avco_concurrency_{$suffix}");

        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $movedAt = '2026-09-01T00:00:00+00:00';
        $receipt = fn (string $unitCost, int $sourceId): array => [
            'item_id' => $itemId, 'location_id' => $locationId, 'qty' => '10.0000', 'uom_id' => $uomId,
            'moved_at' => $movedAt, 'source_type' => 'AVCO_CONCURRENCY_TEST', 'source_id' => $sourceId,
            'unit_cost' => $unitCost, 'currency_id' => $currencyId, 'fx_rate' => '1.000000000', 'base_unit_cost' => $unitCost,
        ];
        $resultPath = sys_get_temp_dir().'/avco_pool_concurrency_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/avco_pool_concurrency_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);

        try {
            $childPid = pcntl_fork();
            if ($childPid === -1) {
                $this->fail('Unable to fork a process for the concurrency test.');
            }

            if ($childPid === 0) {
                config(['database.connections.avco_concurrency_child' => config('database.connections.testing')]);
                config(['database.default' => 'avco_concurrency_child']);
                $waited = 0;
                while (! file_exists($startPath) && $waited < 2_000_000) {
                    usleep(1000);
                    $waited += 1000;
                }
                try {
                    app(PostInventoryMovement::class)($receipt('20.0000', random_int(1, 1_000_000_000)));
                    file_put_contents($resultPath, json_encode(['success' => true]));
                } catch (\Throwable $e) {
                    file_put_contents($resultPath, json_encode(['success' => false, 'message' => $e->getMessage()]));
                }
                posix_kill(getmypid(), SIGKILL);
            }

            $originalDefaultConnection = config('database.default');
            config(['database.connections.avco_concurrency_parent' => config('database.connections.testing')]);
            config(['database.default' => 'avco_concurrency_parent']);
            file_put_contents($startPath, '1');
            try {
                app(PostInventoryMovement::class)($receipt('10.0000', random_int(1, 1_000_000_000)));
                $parentOutcome = ['success' => true];
            } catch (\Throwable $e) {
                $parentOutcome = ['success' => false, 'message' => $e->getMessage()];
            } finally {
                config(['database.default' => $originalDefaultConnection]);
                DB::purge('avco_concurrency_parent');
            }

            pcntl_waitpid($childPid, $status);
            $childOutcome = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($childOutcome, 'the forked child did not report an outcome');
            $this->assertTrue($parentOutcome['success'], $parentOutcome['message'] ?? '');
            $this->assertTrue($childOutcome['success'], $childOutcome['message'] ?? '');

            $pool = DB::connection('testing')->table('avco_pools')->where('company_id', $companyId)->where('item_id', $itemId)->sole();
            $this->assertSame('20.0000', $pool->qty_on_hand);
            $this->assertSame('300.0000', $pool->inventory_value, '10 @ 10 plus 10 @ 20 — a stale-read race would lose one receipt entirely');
            $this->assertSame('15.0000', $pool->avg_unit_cost);
            $this->assertSame(2, DB::connection('testing')->table('stock_moves')->where('company_id', $companyId)->where('item_id', $itemId)->count());
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
            $this->cleanUpRawFixture($pdo, $companyId, $itemId);
        }

        // The raw fixture commits outside RefreshDatabase's transaction, so it must remove everything it committed — the currency too.
        $this->assertFalse(DB::connection('testing')->table('currencies')->where('code', 'AVX')->exists(), 'the raw fixture left its currency in the shared test database');
    }

    private function cleanUpRawFixture(PDO $pdo, int $companyId, int $itemId): void
    {
        $pdo->exec('alter table stock_valuation_entries disable trigger stock_valuation_entries_immutable');
        $pdo->exec('alter table stock_layers disable trigger stock_layers_guard');
        $pdo->exec('alter table stock_moves disable trigger stock_moves_immutable');
        try {
            $pdo->exec("delete from stock_valuation_entries where stock_move_id in (select id from stock_moves where company_id = {$companyId})");
            $pdo->exec("delete from stock_layers where company_id = {$companyId}");
            $pdo->exec("delete from stock_moves where company_id = {$companyId}");
            $pdo->exec("delete from avco_pools where company_id = {$companyId}");
        } finally {
            $pdo->exec('alter table stock_moves enable trigger stock_moves_immutable');
            $pdo->exec('alter table stock_layers enable trigger stock_layers_guard');
            $pdo->exec('alter table stock_valuation_entries enable trigger stock_valuation_entries_immutable');
        }
        $pdo->exec("delete from activity_log where company_id = {$companyId}");
        $pdo->exec("delete from items where id = {$itemId}");
        $pdo->exec("delete from categories where company_id = {$companyId}");
        $pdo->exec("delete from chart_of_accounts where company_id = {$companyId}");
        $pdo->exec("delete from locations where warehouse_id in (select id from warehouses where company_id = {$companyId})");
        $pdo->exec("delete from warehouses where company_id = {$companyId}");
        $pdo->exec("delete from uoms where company_id = {$companyId}");
        $pdo->exec("delete from companies where id = {$companyId}");
        // Created by createRawFixture() outside the test transaction; every row that referenced it is gone by now.
        $pdo->exec("delete from currencies where code = 'AVX'");
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} [item_id, location_id, uom_id, currency_id] */
    private function createRawFixture(PDO $pdo, int $companyId, string $prefix): array
    {
        $pdo->exec("insert into currencies (code, name, symbol, decimal_places, created_at, updated_at) select 'AVX', 'AVX Currency', 'A', 2, now(), now() where not exists (select 1 from currencies where code = 'AVX')");
        $currencyId = (int) $pdo->query("select id from currencies where code = 'AVX'")->fetchColumn();
        $uomId = (int) $pdo->query("insert into uoms (company_id, code, name, dimension, factor_to_base, is_base, created_at, updated_at) values ({$companyId}, '{$prefix}_ea', '{$prefix} Each', 'COUNT', 1, false, now(), now()) returning id")->fetchColumn();
        $accountId = (int) $pdo->query("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$companyId}, '{$prefix}-inv', '{$prefix} Inventory', 'ASSET', true, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $categoryId = (int) $pdo->query("insert into categories (company_id, code, name, created_at, updated_at) values ({$companyId}, '{$prefix}_cat', '{$prefix} Category', now(), now()) returning id")->fetchColumn();
        $itemId = (int) $pdo->query("insert into items (company_id, code, name, type, stock_uom_id, is_stocked, valuation_method, avco_pool_scope, tracking, category_id, inventory_account_id, status, created_at, updated_at) values ({$companyId}, '{$prefix}_item', '{$prefix} Item', 'RAW', {$uomId}, true, 'AVCO', 'COMPANY_ITEM', 'NONE', {$categoryId}, {$accountId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $warehouseId = (int) $pdo->query("insert into warehouses (company_id, code, name, is_default, active, created_at, updated_at) values ({$companyId}, '{$prefix}_wh', '{$prefix} Warehouse', false, true, now(), now()) returning id")->fetchColumn();
        $locationId = (int) $pdo->query("insert into locations (warehouse_id, code, name, active, created_at, updated_at) values ({$warehouseId}, '{$prefix}_loc', '{$prefix} Location', true, now(), now()) returning id")->fetchColumn();

        return [$itemId, $locationId, $uomId, $currencyId];
    }
}
