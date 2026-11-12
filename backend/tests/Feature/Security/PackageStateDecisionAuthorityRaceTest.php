<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\IssuePackagedStock;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #91, secondary package-state permissions (Reviewer A, R3): a grouped issue decides `inventory.package-state.issue` and `.view` INSIDE its transaction from
 * the stored actor. Revoked and suspended meanwhile, a replay of an already recorded request (the receipt does not bypass the decision), and two concurrent
 * requests with one key from the same actor (exactly one issue, one replay).
 * Also the delegated path: ReverseGoodsReceipt hands its fresh actor to ReverseGroupedReceiptLine, which checks `inventory.package-state.reverse` and `.view`
 * on it; revoked and suspended meanwhile, with a replay of an already reversed receipt.
 * Not covered here: receipt-evidence, QA-evidence and count-capture sub-actions (same hand-over pattern, no dedicated test); the static guard lists them.
 */
class PackageStateDecisionAuthorityRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const ISSUE = 'inventory.package-state.issue';

    private const VIEW = 'inventory.package-state.view';

    private const KEY = 'race-issue-1';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, can: int, count: int, moved_at: string} */
    private function fixture(bool $alreadyIssued = false): array
    {
        $pdo = $this->raceSession();
        $prefix = 'ps'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $setup = $this->raceUser($pdo, $r['company'], $prefix, 'setup');
        foreach ([self::ISSUE, self::VIEW] as $duty) {
            $this->raceGrant($pdo, $r['company'], $actor, $duty);
        }
        foreach (['inventory.physical_count.create', self::VIEW] as $duty) {
            $this->raceGrant($pdo, $r['company'], $setup, $duty);
        }
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);
        $can = (int) $pdo->query("insert into item_packagings (company_id, item_id, code, name, stock_quantity, active, created_at, updated_at) values ({$r['company']}, {$r['item']}, 'can', '5 L can', 5, true, now(), now()) returning id")->fetchColumn();

        $count = $this->raceCommitted(function () use ($r, $setup, $can): int {
            app(PostInventoryMovement::class)([
                'item_id' => $r['item'], 'location_id' => $r['location'], 'qty' => '20', 'uom_id' => $r['uom'], 'moved_at' => now()->startOfSecond()->subMinute(),
                'source_type' => 'DIRECT_RECEIPT', 'source_id' => 99101, 'unit_cost' => '10', 'currency_id' => $r['currency'], 'fx_rate' => '1.000000000', 'base_unit_cost' => '10',
            ]);

            return app(RecordPackageCheckpoint::class)([
                'item_id' => $r['item'], 'location_id' => $r['location'], 'request_key' => 'race-count', 'reason' => 'Opening count', 'expected_quantity' => '20',
                'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can, 'count' => 3], ['quantity' => '5']],
            ], User::query()->findOrFail($setup))->id;
        });
        $f = ['pdo' => $pdo, 'r' => $r, 'actor' => $actor, 'can' => $can, 'count' => $count, 'moved_at' => now()->startOfSecond()->toIso8601String()];
        if ($alreadyIssued) {
            $this->raceCommitted(fn () => ($this->issue($f))());
        }

        return $f;
    }

    private function issue(array $f): \Closure
    {
        return fn () => app(IssuePackagedStock::class)([
            'predecessor_id' => $f['count'], 'request_key' => self::KEY, 'reason' => 'Job paint', 'source_type' => 'TEST_JOB_ISSUE', 'source_id' => 77001,
            'moved_at' => $f['moved_at'], 'draw' => [['kind' => 'SEALED', 'packaging_id' => $f['can'], 'count' => 1]],
        ], User::query()->findOrFail($f['actor']));
    }

    private function gate(): array
    {
        return ['select company_id from company_access_generations order by company_id for update'];
    }

    private function issues(array $f): string
    {
        return (string) $f['pdo']->query("select count(*) from inventory_package_operations where request_key = '".self::KEY."' and evidence_kind = 'ISSUE'")->fetchColumn()
            .'/'.(string) $f['pdo']->query("select count(*) from stock_moves where company_id = {$f['r']['company']} and source_type = 'TEST_JOB_ISSUE'")->fetchColumn();
    }

    private function mutation(array $f, string $how, string $duty): \Closure
    {
        return $how === 'suspend'
            ? fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}")
            : fn ($h) => $this->raceRevoke($h, $f['actor'], $duty);
    }

    /** @param 'issue'|'replay' $mode */
    private function scenario(string $mode, ?string $how, string $duty, string $expectedState): void
    {
        $f = $this->fixture($mode === 'replay');
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->issue($f), $how === null ? fn () => null : $this->mutation($f, $how, $duty));
            if ($how === null) {
                $this->assertTrue($o['success'], (string) $o['message']);
            } else {
                $this->assertFalse($o['success']);
                $this->assertStringContainsString('not permitted', (string) $o['message']);
            }
            $this->assertSame($expectedState, $this->issues($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_control_an_unchanged_actor_issues_once(): void
    {
        $this->scenario('issue', null, self::ISSUE, '1/1');
    }

    public function test_nothing_is_issued_after_the_issue_permission_is_revoked_meanwhile(): void
    {
        $this->scenario('issue', 'revoke', self::ISSUE, '0/0');
    }

    public function test_nothing_is_issued_after_the_view_permission_is_revoked_meanwhile(): void
    {
        $this->scenario('issue', 'revoke', self::VIEW, '0/0');
    }

    public function test_nothing_is_issued_after_the_actor_is_suspended_meanwhile(): void
    {
        $this->scenario('issue', 'suspend', self::ISSUE, '0/0');
    }

    public function test_control_an_unchanged_replay_returns_the_recorded_issue_and_posts_nothing_more(): void
    {
        $this->scenario('replay', null, self::ISSUE, '1/1');
    }

    public function test_a_replay_is_refused_after_the_issue_permission_is_revoked_meanwhile(): void
    {
        $this->scenario('replay', 'revoke', self::ISSUE, '1/1');
    }

    public function test_a_replay_is_refused_after_the_view_permission_is_revoked_meanwhile(): void
    {
        $this->scenario('replay', 'revoke', self::VIEW, '1/1');
    }

    public function test_a_replay_is_refused_after_the_actor_is_suspended_meanwhile(): void
    {
        $this->scenario('replay', 'suspend', self::ISSUE, '1/1');
    }

    public function test_two_concurrent_requests_with_one_key_from_one_actor_issue_exactly_once(): void
    {
        $f = $this->fixture();
        $files = [];
        $pids = [];
        $f['pdo']->beginTransaction();
        try {
            foreach ($this->gate() as $sql) {
                $f['pdo']->query($sql)->fetchAll();
            }
            foreach ([0, 1] as $n) {
                $files[$n] = sys_get_temp_dir().'/ps_race_'.getmypid().'_'.$n.'_'.bin2hex(random_bytes(3)).'.json';
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    config(['database.connections.race_child' => config('database.connections.testing'), 'database.default' => 'race_child']);
                    try {
                        ($this->issue($f))();
                        file_put_contents($files[$n], json_encode(['success' => true, 'message' => null]));
                    } catch (\Throwable $e) {
                        file_put_contents($files[$n], json_encode(['success' => false, 'message' => $e->getMessage()]));
                    }
                    posix_kill(getmypid(), SIGKILL);
                }
                $pids[] = $pid;
            }
            $monitor = $this->raceSession();
            $blocked = 0;
            for ($i = 0; $i < 400 && $blocked < 2; $i++) {
                $blocked = (int) $monitor->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->fetchColumn();
                $blocked >= 2 || usleep(25_000);
            }
            $this->assertSame(2, $blocked, 'both requests must be waiting at the company access barrier');
            $f['pdo']->commit();
        } finally {
            if ($f['pdo']->inTransaction()) {
                $f['pdo']->rollBack();
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
        }
        try {
            foreach ($files as $file) {
                $o = json_decode((string) @file_get_contents($file), true);
                @unlink($file);
                $this->assertTrue($o['success'] ?? false, (string) ($o['message'] ?? 'no outcome'));
            }
            $this->assertSame('1/1', $this->issues($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- delegated: goods receipt reversal of grouped stock (ReverseGroupedReceiptLine)

    private const REVERSE_RECEIPT = 'purchasing.goods-receipt.reverse';

    private const REVERSE = 'inventory.package-state.reverse';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, receipt: int} */
    private function reversalFixture(bool $alreadyReversed = false): array
    {
        $pdo = $this->raceSession();
        $prefix = 'pr'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $setup = $this->raceUser($pdo, $r['company'], $prefix, 'setup');
        foreach ([self::REVERSE_RECEIPT, self::REVERSE, self::VIEW] as $duty) {
            $this->raceGrant($pdo, $r['company'], $actor, $duty);
        }
        foreach (['inventory.physical_count.create', self::VIEW, 'inventory.package-state.activate', 'purchasing.goods-receipt.post', 'purchasing.goods-receipt.create'] as $duty) {
            $this->raceGrant($pdo, $r['company'], $setup, $duty);
        }
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);
        $can = (int) $pdo->query("insert into item_packagings (company_id, item_id, code, name, stock_quantity, active, created_at, updated_at) values ({$r['company']}, {$r['item']}, 'can', '5 L can', 5, true, now(), now()) returning id")->fetchColumn();
        $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $poLine = (int) $pdo->query("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$r['company']}, {$po}, 1, {$r['item']}, 'l', 60, {$r['uom']}, 60, {$r['uom']}, 1, 10, 600, 10, 600, false, 'BLOCK', now(), now()) returning id")->fetchColumn();
        $pdo->exec("update purchase_orders set number = '{$prefix}_po', status = 'ISSUED', issued_at = now() where id = {$po}");
        $receipt = (int) $pdo->query("insert into goods_receipts (company_id, supplier_account_id, status, physical_arrival_at, recorded_at, posting_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, 'DRAFT', now() - interval '2 days', now() - interval '2 days', current_date - 2, now(), now()) returning id")->fetchColumn();
        $line = (int) $pdo->query("insert into goods_receipt_lines (goods_receipt_id, purchase_order_line_id, item_id, location_id, received_qty, received_uom_id, stock_qty, stock_uom_id, uom_factor, provisional_unit_cost, currency_id, fx_rate, base_unit_cost, fx_rate_date, fx_rate_type, fx_rate_source, created_at, updated_at) values ({$receipt}, {$poLine}, {$r['item']}, {$r['location']}, 12, {$r['uom']}, 12, {$r['uom']}, 1, 10, {$r['currency']}, 1, 10, current_date, 'SPOT', 'manual', now(), now()) returning id")->fetchColumn();

        $this->raceCommitted(function () use ($r, $setup, $can, $receipt, $line, $actor, $alreadyReversed): void {
            $by = User::query()->findOrFail($setup);
            app(PostInventoryMovement::class)([
                'item_id' => $r['item'], 'location_id' => $r['location'], 'qty' => '20', 'uom_id' => $r['uom'], 'moved_at' => now()->startOfSecond()->subDays(5),
                'source_type' => 'DIRECT_RECEIPT', 'source_id' => 99101, 'unit_cost' => '10', 'currency_id' => $r['currency'], 'fx_rate' => '1.000000000', 'base_unit_cost' => '10',
            ]);
            $count = app(RecordPackageCheckpoint::class)([
                'item_id' => $r['item'], 'location_id' => $r['location'], 'request_key' => 'race-count', 'reason' => 'Opening count', 'expected_quantity' => '20',
                'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can, 'count' => 3], ['quantity' => '5']],
            ], $by);
            app(ActivateGroupedPackageTracking::class)(['item_id' => $r['item'], 'request_key' => 'race-activate', 'reason' => 'Start', 'checkpoints' => [$count->id]], $by);
            app(RecordReceiptComposition::class)(\App\Modules\Purchasing\Models\GoodsReceiptLine::query()->findOrFail($line), [['packaging_id' => $can, 'count' => 2], ['quantity' => '2']], 'Observed on arrival', $by);
            app(PostGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($receipt), $by);
            if ($alreadyReversed) {
                app(ReverseGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($receipt), 'wrong delivery', $actor);
            }
        });

        return compact('pdo', 'r', 'actor', 'receipt');
    }

    private function reversalGate(array $f): array
    {
        return array_merge($this->gate(), ["select id from goods_receipts where id = {$f['receipt']} for update"]);
    }

    private function reversalScenario(bool $replay, ?string $how, string $duty, string $expectedStatus, bool $refused = true): void
    {
        $f = $this->reversalFixture($replay);
        try {
            $action = fn () => app(ReverseGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($f['receipt']), 'wrong delivery', $f['actor']);
            $o = $this->raceAction($f['pdo'], $this->reversalGate($f), $action, $how === null ? fn () => null : $this->mutation($f, $how, $duty));
            if ($how === null) {
                $this->assertTrue($o['success'], (string) $o['message']);
            } else {
                $this->assertSame($refused, ! $o['success'], (string) $o['message']);
                if ($refused) {
                    $this->assertMatchesRegularExpression('/not permitted|authority/', (string) $o['message']);
                }
            }
            $this->assertSame($expectedStatus, (string) $f['pdo']->query("select status from goods_receipts where id = {$f['receipt']}")->fetchColumn());
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_control_an_unchanged_actor_reverses_a_grouped_receipt(): void
    {
        $this->reversalScenario(false, null, self::REVERSE, 'REVERSED');
    }

    public function test_a_grouped_receipt_is_not_reversed_after_the_package_reverse_permission_is_revoked_meanwhile(): void
    {
        $this->reversalScenario(false, 'revoke', self::REVERSE, 'POSTED');
    }

    public function test_a_grouped_receipt_is_not_reversed_after_the_package_view_permission_is_revoked_meanwhile(): void
    {
        $this->reversalScenario(false, 'revoke', self::VIEW, 'POSTED');
    }

    public function test_a_grouped_receipt_is_not_reversed_after_the_actor_is_suspended_meanwhile(): void
    {
        $this->reversalScenario(false, 'suspend', self::REVERSE, 'POSTED');
    }

    public function test_a_replayed_reversal_is_refused_after_the_receipt_reverse_duty_is_revoked_meanwhile(): void
    {
        $this->reversalScenario(true, 'revoke', self::REVERSE_RECEIPT, 'REVERSED');
    }

    /** Documented behaviour: an already reversed receipt returns its recorded state before any package work, so the package permissions have nothing to decide. */
    public function test_a_replayed_reversal_changes_nothing_when_only_the_package_reverse_permission_is_revoked_meanwhile(): void
    {
        $this->reversalScenario(true, 'revoke', self::REVERSE, 'REVERSED', refused: false);
    }
}
