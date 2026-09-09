<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Real PostgreSQL proof for ADR 0043's cumulative receipt ceiling. Each
 * process posts a different draft receipt against the same PO line. The PO
 * header/line locks in PostGoodsReceipt must force the loser to re-read the
 * winner's committed quantity before validating its own receipt.
 *
 * The committed fixture and separately named database connections are
 * intentional. RefreshDatabase's ambient transaction is invisible to a
 * forked process and would retain row locks until the test ended. This is the
 * same isolation pattern used by AvcoPoolConcurrencyTest and
 * QualityDispositionPostingTest.
 */
class GoodsReceiptConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_concurrent_receipts_cannot_together_exceed_one_po_line(): void
    {
        $config = config('database.connections.testing');
        $pdo = new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
        );
        $suffix = bin2hex(random_bytes(4));
        [$companyId, $itemId, $orderLineId, $firstReceiptId, $secondReceiptId] = $this->createRawFixture(
            $pdo,
            "goods_receipt_concurrency_{$suffix}",
        );

        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $resultPath = sys_get_temp_dir().'/goods_receipt_concurrency_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/goods_receipt_concurrency_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);

        try {
            $childPid = pcntl_fork();
            if ($childPid === -1) {
                $this->fail('Unable to fork a process for the concurrency test.');
            }

            if ($childPid === 0) {
                config(['database.connections.goods_receipt_child' => config('database.connections.testing')]);
                config(['database.default' => 'goods_receipt_child']);

                $waited = 0;
                while (! file_exists($startPath) && $waited < 2_000_000) {
                    usleep(1000);
                    $waited += 1000;
                }

                try {
                    $receipt = GoodsReceipt::query()->findOrFail($secondReceiptId);
                    app(PostGoodsReceipt::class)($receipt);
                    file_put_contents($resultPath, json_encode(['success' => true]));
                } catch (\Throwable $e) {
                    file_put_contents($resultPath, json_encode(['success' => false, 'message' => $e->getMessage()]));
                }

                posix_kill(getmypid(), SIGKILL);
            }

            $originalDefaultConnection = config('database.default');
            config(['database.connections.goods_receipt_parent' => config('database.connections.testing')]);
            config(['database.default' => 'goods_receipt_parent']);
            $firstReceipt = GoodsReceipt::query()->findOrFail($firstReceiptId);
            file_put_contents($startPath, '1');

            try {
                app(PostGoodsReceipt::class)($firstReceipt);
                $parentOutcome = ['success' => true];
            } catch (\Throwable $e) {
                $parentOutcome = ['success' => false, 'message' => $e->getMessage()];
            } finally {
                config(['database.default' => $originalDefaultConnection]);
                DB::purge('goods_receipt_parent');
            }

            pcntl_waitpid($childPid, $status);
            $childOutcome = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($childOutcome, 'the forked child did not report an outcome');
            $this->assertNotSame(
                $parentOutcome['success'],
                $childOutcome['success'],
                'exactly one of two concurrent 6-unit receipts against a 10-unit order may post',
            );

            $failure = $parentOutcome['success'] ? $childOutcome : $parentOutcome;
            $this->assertStringContainsString('exceeds the issued PO tolerance', $failure['message']);

            $postedReceiptIds = DB::connection('testing')->table('goods_receipts')
                ->whereIn('id', [$firstReceiptId, $secondReceiptId])
                ->where('status', 'POSTED')
                ->pluck('id');
            $this->assertCount(1, $postedReceiptIds);
            $this->assertSame(
                '6.0000',
                (string) DB::connection('testing')->table('goods_receipt_lines')
                    ->where('purchase_order_line_id', $orderLineId)
                    ->whereIn('goods_receipt_id', $postedReceiptIds)
                    ->sum('stock_qty'),
            );
            $this->assertSame(1, DB::connection('testing')->table('stock_moves')->where('item_id', $itemId)->count());
            $this->assertSame(1, DB::connection('testing')->table('stock_valuation_entries')
                ->whereIn('stock_move_id', DB::connection('testing')->table('stock_moves')->where('item_id', $itemId)->pluck('id'))
                ->count());
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
            $this->cleanUpRawFixture($pdo, $companyId);
        }
    }

    /** @return array{int, int, int, int, int} */
    private function createRawFixture(PDO $pdo, string $prefix): array
    {
        $companyId = (int) $pdo->query("insert into companies (name, created_at, updated_at) values ('{$prefix}', now(), now()) returning id")->fetchColumn();
        $pdo->exec("insert into currencies (code, name, symbol, decimal_places, created_at, updated_at) select 'GCX', 'Goods Concurrency Currency', 'G', 2, now(), now() where not exists (select 1 from currencies where code = 'GCX')");
        $currencyId = (int) $pdo->query("select id from currencies where code = 'GCX'")->fetchColumn();
        $uomId = (int) $pdo->query("insert into uoms (company_id, code, name, dimension, factor_to_base, is_base, created_at, updated_at) values ({$companyId}, '{$prefix}_ea', '{$prefix} Each', 'COUNT', 1, false, now(), now()) returning id")->fetchColumn();
        $payableAccountId = (int) $pdo->query("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$companyId}, '{$prefix}-pay', '{$prefix} Payable', 'LIABILITY', true, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $inventoryAccountId = (int) $pdo->query("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$companyId}, '{$prefix}-inv', '{$prefix} Inventory', 'ASSET', true, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $categoryId = (int) $pdo->query("insert into categories (company_id, code, name, created_at, updated_at) values ({$companyId}, '{$prefix}_cat', '{$prefix} Category', now(), now()) returning id")->fetchColumn();
        $itemId = (int) $pdo->query("insert into items (company_id, code, name, type, stock_uom_id, is_stocked, valuation_method, tracking, category_id, inventory_account_id, status, created_at, updated_at) values ({$companyId}, '{$prefix}_item', '{$prefix} Item', 'RAW', {$uomId}, true, 'FIFO', 'NONE', {$categoryId}, {$inventoryAccountId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $warehouseId = (int) $pdo->query("insert into warehouses (company_id, code, name, is_default, active, created_at, updated_at) values ({$companyId}, '{$prefix}_wh', '{$prefix} Warehouse', false, true, now(), now()) returning id")->fetchColumn();
        $locationId = (int) $pdo->query("insert into locations (warehouse_id, code, name, active, created_at, updated_at) values ({$warehouseId}, '{$prefix}_loc', '{$prefix} Location', true, now(), now()) returning id")->fetchColumn();
        $partyId = (int) $pdo->query("insert into parties (company_id, code, name, status, created_at, updated_at) values ({$companyId}, '{$prefix}_party', '{$prefix} Party', 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $supplierId = (int) $pdo->query("insert into supplier_accounts (company_id, party_id, code, payment_terms_days, payable_account_id, status, created_at, updated_at) values ({$companyId}, {$partyId}, '{$prefix}_sup', 30, {$payableAccountId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $orderId = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$companyId}, {$supplierId}, {$currencyId}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $orderLineId = (int) $pdo->query("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$companyId}, {$orderId}, 1, {$itemId}, '{$prefix} line', 10, {$uomId}, 10, {$uomId}, 1, 10, 100, 10, 100, false, 'BLOCK', now(), now()) returning id")->fetchColumn();
        $pdo->exec("update purchase_orders set number = '{$prefix}_po', status = 'ISSUED', issued_at = now() where id = {$orderId}");

        $receiptIds = [];
        foreach ([1, 2] as $receiptNumber) {
            $receiptId = (int) $pdo->query("insert into goods_receipts (company_id, supplier_account_id, status, physical_arrival_at, recorded_at, posting_date, created_at, updated_at) values ({$companyId}, {$supplierId}, 'DRAFT', now(), now(), current_date, now(), now()) returning id")->fetchColumn();
            $pdo->exec("insert into goods_receipt_lines (goods_receipt_id, purchase_order_line_id, item_id, location_id, received_qty, received_uom_id, stock_qty, stock_uom_id, uom_factor, provisional_unit_cost, currency_id, fx_rate, base_unit_cost, fx_rate_date, fx_rate_type, fx_rate_source, created_at, updated_at) values ({$receiptId}, {$orderLineId}, {$itemId}, {$locationId}, 6, {$uomId}, 6, {$uomId}, 1, 10, {$currencyId}, 1, 10, current_date, 'SPOT', 'manual', now(), now())");
            $receiptIds[] = $receiptId;
        }

        return [$companyId, $itemId, $orderLineId, $receiptIds[0], $receiptIds[1]];
    }

    private function cleanUpRawFixture(PDO $pdo, int $companyId): void
    {
        foreach (['stock_valuation_entries', 'stock_layers', 'stock_moves', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders'] as $table) {
            $trigger = match ($table) {
                'stock_valuation_entries' => 'stock_valuation_entries_immutable',
                'stock_layers' => 'stock_layers_guard',
                'stock_moves' => 'stock_moves_immutable',
                'goods_receipt_lines' => 'goods_receipt_lines_guard',
                'goods_receipts' => 'goods_receipts_guard',
                'purchase_order_lines' => 'purchase_order_lines_guard',
                'purchase_orders' => 'purchase_orders_guard',
            };
            $pdo->exec("alter table {$table} disable trigger {$trigger}");
        }

        try {
            $pdo->exec("delete from stock_valuation_entries where stock_move_id in (select id from stock_moves where company_id = {$companyId})");
            $pdo->exec("delete from stock_layers where company_id = {$companyId}");
            $pdo->exec("delete from stock_moves where company_id = {$companyId}");
            $pdo->exec("delete from goods_receipt_lines where goods_receipt_id in (select id from goods_receipts where company_id = {$companyId})");
            $pdo->exec("delete from goods_receipts where company_id = {$companyId}");
            $pdo->exec("delete from purchase_order_lines where company_id = {$companyId}");
            $pdo->exec("delete from purchase_orders where company_id = {$companyId}");
        } finally {
            foreach (['stock_valuation_entries', 'stock_layers', 'stock_moves', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders'] as $table) {
                $trigger = match ($table) {
                    'stock_valuation_entries' => 'stock_valuation_entries_immutable',
                    'stock_layers' => 'stock_layers_guard',
                    'stock_moves' => 'stock_moves_immutable',
                    'goods_receipt_lines' => 'goods_receipt_lines_guard',
                    'goods_receipts' => 'goods_receipts_guard',
                    'purchase_order_lines' => 'purchase_order_lines_guard',
                    'purchase_orders' => 'purchase_orders_guard',
                };
                $pdo->exec("alter table {$table} enable trigger {$trigger}");
            }
        }

        $pdo->exec("delete from sequences where company_id = {$companyId}");
        $pdo->exec("delete from activity_log where company_id = {$companyId}");
        $pdo->exec("delete from supplier_accounts where company_id = {$companyId}");
        $pdo->exec("delete from parties where company_id = {$companyId}");
        $pdo->exec("delete from locations where warehouse_id in (select id from warehouses where company_id = {$companyId})");
        $pdo->exec("delete from warehouses where company_id = {$companyId}");
        $pdo->exec("delete from items where company_id = {$companyId}");
        $pdo->exec("delete from categories where company_id = {$companyId}");
        $pdo->exec("delete from chart_of_accounts where company_id = {$companyId}");
        $pdo->exec("delete from uoms where company_id = {$companyId}");
        $pdo->exec("delete from companies where id = {$companyId}");
    }
}
