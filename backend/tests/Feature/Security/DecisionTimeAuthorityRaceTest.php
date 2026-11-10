<?php

namespace Tests\Feature\Security;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #91 (audit F4/F5): the actor who issues a purchase order, posts a goods receipt or posts a physical count is decided INSIDE the operation's transaction, from the
 * stored person, after the company access barrier. The test holds every company barrier so the Action has already passed its early check and is waiting; the actor's
 * authority is then changed and committed and the barrier released. The operation must refuse and leave nothing behind. Each scenario has a control that proves the same
 * staging lets an unchanged actor through.
 */
class DecisionTimeAuthorityRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const ISSUE = 'purchasing.purchase-order.issue';

    private const POST_RECEIPT = 'purchasing.goods-receipt.post';

    private const POST_COUNT = 'inventory.physical_count.post';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, other: int, po: int, receipt: int, count: int} */
    private function fixture(string $duty): array
    {
        $pdo = $this->raceSession();
        $prefix = 'dt'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $other = $this->raceUser($pdo, $r['company'], $prefix, 'other');
        $this->raceGrant($pdo, $r['company'], $actor, $duty);
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);

        $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $line = (int) $pdo->query("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$r['company']}, {$po}, 1, {$r['item']}, 'l', 10, {$r['uom']}, 10, {$r['uom']}, 1, 10, 100, 10, 100, false, 'BLOCK', now(), now()) returning id")->fetchColumn();
        $receipt = 0;
        $count = 0;
        if ($duty === self::POST_RECEIPT) {
            $pdo->exec("update purchase_orders set number = '{$prefix}_po', status = 'ISSUED', issued_at = now() where id = {$po}");
            $receipt = (int) $pdo->query("insert into goods_receipts (company_id, supplier_account_id, status, physical_arrival_at, recorded_at, posting_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, 'DRAFT', now(), now(), current_date, now(), now()) returning id")->fetchColumn();
            $pdo->exec("insert into goods_receipt_lines (goods_receipt_id, purchase_order_line_id, item_id, location_id, received_qty, received_uom_id, stock_qty, stock_uom_id, uom_factor, provisional_unit_cost, currency_id, fx_rate, base_unit_cost, fx_rate_date, fx_rate_type, fx_rate_source, created_at, updated_at) values ({$receipt}, {$line}, {$r['item']}, {$r['location']}, 6, {$r['uom']}, 6, {$r['uom']}, 1, 10, {$r['currency']}, 1, 10, current_date, 'SPOT', 'manual', now(), now())");
        }
        if ($duty === self::POST_COUNT) {
            $count = (int) $pdo->query("insert into physical_counts (company_id, warehouse_id, status, created_by, created_at, updated_at) values ({$r['company']}, {$r['warehouse']}, 'DRAFT', {$other}, now(), now()) returning id")->fetchColumn();
            $pdo->exec("insert into physical_count_lines (company_id, physical_count_id, item_id, location_id, book_qty, counted_qty, counted_by, counted_at, grouped, created_at, updated_at) values ({$r['company']}, {$count}, {$r['item']}, {$r['location']}, 0, 0, {$other}, now(), false, now(), now())");
        }

        return compact('pdo', 'r', 'actor', 'other', 'po', 'receipt', 'count');
    }

    /**
     * Every company barrier in ascending order (exactly as the security writers take them, so the holder's own later writes cannot deadlock with the waiting Action),
     * then the row the operation locks first. A fenced Action waits at the barrier; an unfenced one waits at the row, so the same staging proves both.
     *
     * @param  array<string, mixed>  $f
     */
    private function gate(array $f, string $table, string $key): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select id from {$table} where id = {$f[$key]} for update"];
    }

    private function scalar(array $f, string $sql): string
    {
        return (string) $f['pdo']->query($sql)->fetchColumn();
    }

    private function issue(array $f): \Closure
    {
        return fn () => app(IssuePurchaseOrder::class)(PurchaseOrder::query()->findOrFail($f['po']), User::query()->findOrFail($f['actor']));
    }

    private function receive(array $f): \Closure
    {
        return fn () => app(PostGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($f['receipt']), User::query()->findOrFail($f['actor']));
    }

    private function postCount(array $f): \Closure
    {
        return fn () => app(PostPhysicalCount::class)(PhysicalCount::query()->findOrFail($f['count']), [], User::query()->findOrFail($f['actor']));
    }

    private function suspend(array $f): \Closure
    {
        return fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}");
    }

    private function revoke(array $f, string $duty): \Closure
    {
        return fn ($h) => $this->raceRevoke($h, $f['actor'], $duty);
    }

    private function poStatus(array $f): string
    {
        return $this->scalar($f, "select status from purchase_orders where id = {$f['po']}");
    }

    private function receiptStatus(array $f): string
    {
        return $this->scalar($f, "select status from goods_receipts where id = {$f['receipt']}");
    }

    private function countStatus(array $f): string
    {
        return $this->scalar($f, "select status from physical_counts where id = {$f['count']}");
    }

    public function test_control_an_unchanged_actor_issues_the_order(): void
    {
        $f = $this->fixture(self::ISSUE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'purchase_orders', 'po'), $this->issue($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('ISSUED', $this->poStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_an_order_is_not_issued_after_the_issue_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::ISSUE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'purchase_orders', 'po'), $this->issue($f), $this->revoke($f, self::ISSUE));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->poStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_an_order_is_not_issued_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::ISSUE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'purchase_orders', 'po'), $this->issue($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->poStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_person_suspended_before_the_call_cannot_issue_an_order(): void
    {
        $f = $this->fixture(self::ISSUE);
        try {
            $f['pdo']->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}");
            $held = User::query()->findOrFail($f['actor']);
            $this->expectException(DomainInvariantException::class);
            app(IssuePurchaseOrder::class)(PurchaseOrder::query()->findOrFail($f['po']), $held);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_control_an_unchanged_actor_posts_the_receipt(): void
    {
        $f = $this->fixture(self::POST_RECEIPT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipts', 'receipt'), $this->receive($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('POSTED', $this->receiptStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_receipt_is_not_posted_after_the_post_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::POST_RECEIPT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipts', 'receipt'), $this->receive($f), $this->revoke($f, self::POST_RECEIPT));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->receiptStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from stock_moves where company_id = {$f['r']['company']}"), 'no stock moves on the strength of a revoked duty');
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_receipt_is_not_posted_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::POST_RECEIPT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'goods_receipts', 'receipt'), $this->receive($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->receiptStatus($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from stock_moves where company_id = {$f['r']['company']}"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_control_an_unchanged_actor_posts_the_count(): void
    {
        $f = $this->fixture(self::POST_COUNT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'physical_counts', 'count'), $this->postCount($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('POSTED', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_count_is_not_posted_after_the_post_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::POST_COUNT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'physical_counts', 'count'), $this->postCount($f), $this->revoke($f, self::POST_COUNT));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_count_is_not_posted_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::POST_COUNT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'physical_counts', 'count'), $this->postCount($f), $this->suspend($f));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_count_is_not_posted_into_a_warehouse_the_actor_stops_reaching_meanwhile(): void
    {
        $f = $this->fixture(self::POST_COUNT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f, 'physical_counts', 'count'), $this->postCount($f), fn ($h) => $h->exec(
                "insert into access_scope_limits (company_id, user_id, permission, scope, created_at, updated_at) values ({$f['r']['company']}, {$f['actor']}, '".self::POST_COUNT."', 'MY_UNITS', now(), now())"
            ));
            $this->assertFalse($o['success']);
            $this->assertSame('DRAFT', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
