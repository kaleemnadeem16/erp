<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostRejectedStockExit;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/** Committed fixtures and real PostgreSQL wait: the losing request misses early replay, then finds the winner under the receipt-line lock. */
class RejectedExitDocumentIdentityRaceTest extends TestCase
{
    use RefreshDatabase, TwoSessionRace;

    public static function identities(): array
    {
        return ['same' => [[], true], 'changed id' => [['document_id' => 202], false],
            'changed type' => [['document_type' => 'STOCK_SCRAP_LINE'], false]];
    }

    #[DataProvider('identities')]
    public function test_real_competing_replay_validates_document_identity(array $change, bool $allowed): void
    {
        $pdo = $this->raceSession();
        $prefix = 'exit'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        foreach (['purchasing.goods-receipt.post', 'purchasing.quality-disposition.post', 'purchasing.supplier-return.post'] as $duty) {
            $this->raceGrant($pdo, $r['company'], $actor, $duty);
        }
        $this->raceBindCompany($r['company']);
        $resultFile = tempnam(sys_get_temp_dir(), 'exit_identity_');
        $pid = null;
        try {
            $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
            $poLine = (int) $pdo->query("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$r['company']}, {$po}, 1, {$r['item']}, 'l', 12, {$r['uom']}, 12, {$r['uom']}, 1, 10, 120, 10, 120, true, 'BLOCK', now(), now()) returning id")->fetchColumn();
            $pdo->exec("update purchase_orders set number = '{$prefix}_po', status = 'ISSUED', issued_at = now() where id = {$po}");
            $receipt = (int) $pdo->query("insert into goods_receipts (company_id, supplier_account_id, status, physical_arrival_at, recorded_at, posting_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, 'DRAFT', now() - interval '2 days', now() - interval '2 days', current_date - 2, now(), now()) returning id")->fetchColumn();
            $line = (int) $pdo->query("insert into goods_receipt_lines (goods_receipt_id, purchase_order_line_id, item_id, location_id, received_qty, received_uom_id, stock_qty, stock_uom_id, uom_factor, provisional_unit_cost, currency_id, fx_rate, base_unit_cost, fx_rate_date, fx_rate_type, fx_rate_source, created_at, updated_at) values ({$receipt}, {$poLine}, {$r['item']}, {$r['location']}, 12, {$r['uom']}, 12, {$r['uom']}, 1, 10, {$r['currency']}, 1, 10, current_date, 'SPOT', 'manual', now(), now()) returning id")->fetchColumn();
            $this->raceCommitted(function () use ($receipt, $line, $actor): void {
                app(PostGoodsReceipt::class)(GoodsReceipt::findOrFail($receipt), User::findOrFail($actor));
                app(PostQualityDisposition::class)(GoodsReceiptLine::findOrFail($line), ['disposition' => 'RETURN_TO_SUPPLIER', 'qty' => '5', 'reason' => 'Rejected'], $actor);
            });
            $input = ['kind' => 'SUPPLIER_RETURN', 'goods_receipt_line_id' => $line, 'quantity' => '5', 'request_key' => 'identity-race',
                'reason' => 'Rejected', 'document_type' => 'SUPPLIER_RETURN_LINE', 'document_id' => 101];
            config(['database.connections.exit_holder' => config('database.connections.testing')]);
            $original = config('database.default');
            config(['database.default' => 'exit_holder']);
            DB::beginTransaction();
            try {
                $winner = app(PostRejectedStockExit::class)($input, User::findOrFail($actor));
                $this->assertSame(0, (int) $pdo->query("select count(*) from rejected_stock_exits where company_id = {$r['company']}")->fetchColumn(), 'Winner must be uncommitted.');
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    config(['database.connections.exit_child' => config('database.connections.testing'), 'database.default' => 'exit_child']);
                    try {
                        $exit = app(PostRejectedStockExit::class)(array_replace($input, $change), User::findOrFail($actor));
                        file_put_contents($resultFile, json_encode(['success' => true, 'id' => $exit->id]));
                    } catch (\Throwable $error) {
                        file_put_contents($resultFile, json_encode(['success' => false, 'class' => $error::class, 'message' => $error->getMessage()]));
                    }
                    posix_kill(getmypid(), SIGKILL);
                }
                $blocked = false;
                for ($i = 0; $i < 200 && ! $blocked; $i++) {
                    $blocked = (int) $pdo->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and query like '%goods_receipt_lines%' and query like '%for update%'")->fetchColumn() > 0;
                    $blocked || usleep(10_000);
                }
                $this->assertTrue($blocked, 'Child must miss early lookup and wait for the actual receipt-line lock.');
                DB::commit();
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($pid !== null && $pid > 0) {
                    pcntl_waitpid($pid, $status);
                }
                config(['database.default' => $original]);
                DB::purge('exit_holder');
            }
            $outcome = json_decode(file_get_contents($resultFile), true);
            $this->assertSame($allowed, $outcome['success'], json_encode($outcome));
            if ($allowed) {
                $this->assertSame($winner->id, $outcome['id']);
            } else {
                $this->assertSame(DomainInvariantException::class, $outcome['class']);
                $this->assertStringContainsString('different rejected-stock exit', $outcome['message']);
            }
            $this->assertSame(1, (int) $pdo->query("select count(*) from rejected_stock_exits where company_id = {$r['company']}")->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("select count(*) from stock_moves where company_id = {$r['company']} and source_type = 'SUPPLIER_RETURN'")->fetchColumn());
            $this->assertSame('7.0000', (string) $pdo->query("select qty_remaining from stock_layers where company_id = {$r['company']}")->fetchColumn());
        } finally {
            @unlink($resultFile);
            $this->purgeRaceCompany($pdo, $r['company']);
        }
    }
}
