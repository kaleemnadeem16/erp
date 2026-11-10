<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Inventory\Actions\CreateStockScrap;
use App\Modules\Inventory\Actions\PostStockScrap;
use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreateSupplierReturn;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\PostSupplierReturn;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #91 (audit F4/F5), second batch: the Purchasing and Inventory Actions that move stock decide their actor INSIDE the transaction, from the stored person, after the
 * company access barrier (see DecisionTimeAuthorityRaceTest for the staging). For each: duty revoked meanwhile, actor suspended meanwhile, and a control with an unchanged
 * actor that proves the same staging lets the operation through. The fixture is a posted, inspection-required delivery whose stock has been dispositioned for return or scrap.
 */
class DecisionTimeAuthorityStockRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const REVERSE = 'purchasing.goods-receipt.reverse';

    private const QA = 'purchasing.quality-disposition.post';

    private const RETURN_POST = 'purchasing.supplier-return.post';

    private const SCRAP_POST = 'inventory.scrap.post';

    private const CANCEL = 'purchasing.purchase-order.cancel';

    /**
     * @param  'reverse'|'qa'|'return'|'scrap'|'cancel'  $kind
     * @return array{pdo: PDO, r: array<string,int>, actor: int, po: int, receipt: int, line: int, document: int}
     */
    private function fixture(string $kind): array
    {
        $duty = ['reverse' => self::REVERSE, 'qa' => self::QA, 'return' => self::RETURN_POST, 'scrap' => self::SCRAP_POST, 'cancel' => self::CANCEL][$kind];
        $pdo = $this->raceSession();
        $prefix = 'st'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $setup = $this->raceUser($pdo, $r['company'], $prefix, 'setup');
        $this->raceGrant($pdo, $r['company'], $actor, $duty);
        foreach (['purchasing.goods-receipt.post', self::QA, 'purchasing.supplier-return.create', 'inventory.scrap.create'] as $d) {
            $this->raceGrant($pdo, $r['company'], $setup, $d);
        }
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);

        $qa = in_array($kind, ['qa', 'return', 'scrap'], true) ? 'true' : 'false';
        $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $poLine = (int) $pdo->query("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$r['company']}, {$po}, 1, {$r['item']}, 'l', 10, {$r['uom']}, 10, {$r['uom']}, 1, 10, 100, 10, 100, {$qa}, 'BLOCK', now(), now()) returning id")->fetchColumn();
        $receipt = 0;
        $line = 0;
        $document = 0;
        if ($kind !== 'cancel') {
            $pdo->exec("update purchase_orders set number = '{$prefix}_po', status = 'ISSUED', issued_at = now() where id = {$po}");
            $receipt = (int) $pdo->query("insert into goods_receipts (company_id, supplier_account_id, status, physical_arrival_at, recorded_at, posting_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, 'DRAFT', now() - interval '2 days', now() - interval '2 days', current_date - 2, now(), now()) returning id")->fetchColumn();
            $line = (int) $pdo->query("insert into goods_receipt_lines (goods_receipt_id, purchase_order_line_id, item_id, location_id, received_qty, received_uom_id, stock_qty, stock_uom_id, uom_factor, provisional_unit_cost, currency_id, fx_rate, base_unit_cost, fx_rate_date, fx_rate_type, fx_rate_source, created_at, updated_at) values ({$receipt}, {$poLine}, {$r['item']}, {$r['location']}, 6, {$r['uom']}, 6, {$r['uom']}, 1, 10, {$r['currency']}, 1, 10, current_date, 'SPOT', 'manual', now(), now()) returning id")->fetchColumn();
            // Committed setup through the real Actions on a connection with no ambient transaction.
            $document = $this->raceCommitted(function () use ($kind, $receipt, $line, $setup): int {
                $by = User::query()->findOrFail($setup);
                app(PostGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($receipt), $by);
                if ($kind === 'return' || $kind === 'scrap') {
                    app(PostQualityDisposition::class)(GoodsReceiptLine::query()->findOrFail($line), [
                        'disposition' => $kind === 'return' ? 'RETURN_TO_SUPPLIER' : 'SCRAP', 'qty' => '6', 'reason' => 'damaged',
                    ], $setup);
                    $input = ['reason' => 'damaged', 'lines' => [['goods_receipt_line_id' => $line, 'quantity' => '2']]];

                    return $kind === 'return' ? app(CreateSupplierReturn::class)($input, $by)->id : app(CreateStockScrap::class)($input, $by)->id;
                }

                return 0;
            });
        }

        return compact('pdo', 'r', 'actor', 'po', 'receipt', 'line', 'document');
    }

    /** @param array<string, mixed> $f */
    private function gate(array $f, string $table, int $id): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select id from {$table} where id = {$id} for update"];
    }

    private function scalar(array $f, string $sql): string
    {
        return (string) $f['pdo']->query($sql)->fetchColumn();
    }

    private function suspend(array $f): \Closure
    {
        return fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}");
    }

    private function revoke(array $f, string $duty): \Closure
    {
        return fn ($h) => $this->raceRevoke($h, $f['actor'], $duty);
    }

    private function actorUser(array $f): User
    {
        return User::query()->findOrFail($f['actor']);
    }

    // ---- goods receipt reversal

    private function reverse(array $f): \Closure
    {
        return fn () => app(ReverseGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($f['receipt']), 'wrong delivery', $f['actor']);
    }

    private function receiptStatus(array $f): string
    {
        return $this->scalar($f, "select status from goods_receipts where id = {$f['receipt']}");
    }

    public function test_control_an_unchanged_actor_reverses_the_receipt(): void
    {
        $f = $this->fixture('reverse');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipts', $f['receipt']), $this->reverse($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('REVERSED', $this->receiptStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_receipt_is_not_reversed_after_the_reverse_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture('reverse');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipts', $f['receipt']), $this->reverse($f), $this->revoke($f, self::REVERSE));
            $this->assertFalse($o['success']);
            $this->assertSame('POSTED', $this->receiptStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from stock_moves where company_id = {$f['r']['company']} and source_type like '%REVERS%'"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_receipt_is_not_reversed_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture('reverse');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipts', $f['receipt']), $this->reverse($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('POSTED', $this->receiptStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- quality disposition

    private function dispose(array $f): \Closure
    {
        return fn () => app(PostQualityDisposition::class)(GoodsReceiptLine::query()->findOrFail($f['line']), ['disposition' => 'SCRAP', 'qty' => '6', 'reason' => 'damaged'], $f['actor']);
    }

    private function dispositions(array $f): string
    {
        return $this->scalar($f, "select count(*) from quality_dispositions where goods_receipt_line_id = {$f['line']}");
    }

    public function test_control_an_unchanged_actor_posts_the_disposition(): void
    {
        $f = $this->fixture('qa');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipt_lines', $f['line']), $this->dispose($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('1', $this->dispositions($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_disposition_is_not_posted_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture('qa');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipt_lines', $f['line']), $this->dispose($f), $this->revoke($f, self::QA));
            $this->assertFalse($o['success']);
            $this->assertSame('0', $this->dispositions($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_disposition_is_not_posted_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture('qa');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipt_lines', $f['line']), $this->dispose($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('0', $this->dispositions($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- supplier return

    private function postReturn(array $f): \Closure
    {
        return fn () => app(PostSupplierReturn::class)(SupplierReturn::query()->findOrFail($f['document']), $this->actorUser($f));
    }

    private function returnStatus(array $f): string
    {
        return $this->scalar($f, "select status from supplier_returns where id = {$f['document']}");
    }

    public function test_control_an_unchanged_actor_posts_the_supplier_return(): void
    {
        $f = $this->fixture('return');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'supplier_returns', $f['document']), $this->postReturn($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('POSTED', $this->returnStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_supplier_return_is_not_posted_after_the_post_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture('return');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'supplier_returns', $f['document']), $this->postReturn($f), $this->revoke($f, self::RETURN_POST));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->returnStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from rejected_stock_exits where company_id = {$f['r']['company']}"), 'no stock leaves on the strength of a revoked duty');
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_supplier_return_is_not_posted_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture('return');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'supplier_returns', $f['document']), $this->postReturn($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->returnStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from rejected_stock_exits where company_id = {$f['r']['company']}"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- stock scrap

    private function postScrap(array $f): \Closure
    {
        return fn () => app(PostStockScrap::class)(StockScrap::query()->findOrFail($f['document']), $this->actorUser($f));
    }

    private function scrapStatus(array $f): string
    {
        return $this->scalar($f, "select status from stock_scraps where id = {$f['document']}");
    }

    public function test_control_an_unchanged_actor_posts_the_scrap(): void
    {
        $f = $this->fixture('scrap');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'stock_scraps', $f['document']), $this->postScrap($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('POSTED', $this->scrapStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_scrap_is_not_posted_after_the_post_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture('scrap');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'stock_scraps', $f['document']), $this->postScrap($f), $this->revoke($f, self::SCRAP_POST));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->scrapStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from rejected_stock_exits where company_id = {$f['r']['company']}"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_scrap_is_not_posted_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture('scrap');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'stock_scraps', $f['document']), $this->postScrap($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->scrapStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from rejected_stock_exits where company_id = {$f['r']['company']}"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- purchase order cancellation

    private function cancel(array $f): \Closure
    {
        return fn () => app(CancelPurchaseOrder::class)(PurchaseOrder::query()->findOrFail($f['po']), 'no longer needed', $this->actorUser($f));
    }

    private function poStatus(array $f): string
    {
        return $this->scalar($f, "select status from purchase_orders where id = {$f['po']}");
    }

    public function test_control_an_unchanged_actor_cancels_the_order(): void
    {
        $f = $this->fixture('cancel');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'purchase_orders', $f['po']), $this->cancel($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('CANCELLED', $this->poStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_an_order_is_not_cancelled_after_the_cancel_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture('cancel');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'purchase_orders', $f['po']), $this->cancel($f), $this->revoke($f, self::CANCEL));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->poStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_an_order_is_not_cancelled_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture('cancel');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'purchase_orders', $f['po']), $this->cancel($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->poStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
