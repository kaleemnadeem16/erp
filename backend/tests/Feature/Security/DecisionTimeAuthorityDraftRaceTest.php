<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Inventory\Actions\CancelPhysicalCount;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #91 row 9b: the remaining Inventory and Purchasing Actions that open a controlled process or write a draft decide their actor INSIDE the transaction, from the stored
 * person, after the company access barrier (staging: see DecisionTimeAuthorityRaceTest). Narrow on purpose: one Action per family, each refused when the duty is revoked or the
 * actor suspended meanwhile, with a control for the two physical-count Actions.
 */
class DecisionTimeAuthorityDraftRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const COUNT = 'inventory.physical_count.create';

    private const CUTOVER = 'inventory.cutover.create';

    private const PO = 'purchasing.purchase-order.create';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, po: int, count: int} */
    private function fixture(string $duty): array
    {
        $pdo = $this->raceSession();
        $prefix = 'dr'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $this->raceGrant($pdo, $r['company'], $actor, $duty);
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);
        $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $count = (int) $pdo->query("insert into physical_counts (company_id, warehouse_id, status, created_by, created_at, updated_at) values ({$r['company']}, {$r['warehouse']}, 'DRAFT', {$actor}, now(), now()) returning id")->fetchColumn();

        return compact('pdo', 'r', 'actor', 'po', 'count');
    }

    private function gate(): array
    {
        return ['select company_id from company_access_generations order by company_id for update'];
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

    /** Runs the Action staged behind the barrier and asserts it was refused for lack of authority, not for another reason. */
    private function refused(array $f, \Closure $action, \Closure $mutate): void
    {
        $o = $this->raceAction($f['pdo'], $this->gate(), $action, $mutate);
        $this->assertFalse($o['success']);
        $this->assertStringContainsString('not permitted', (string) $o['message']);
    }

    // ---- physical count: create

    private function createCount(array $f): \Closure
    {
        return fn () => app(CreatePhysicalCount::class)(['warehouse_id' => $f['r']['warehouse'], 'reference' => 'race'], $this->actorUser($f));
    }

    private function counts(array $f): string
    {
        return $this->scalar($f, "select count(*) from physical_counts where company_id = {$f['r']['company']}");
    }

    public function test_control_an_unchanged_actor_creates_a_physical_count(): void
    {
        $f = $this->fixture(self::COUNT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->createCount($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('2', $this->counts($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_physical_count_is_not_created_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::COUNT);
        try {
            $this->refused($f, $this->createCount($f), $this->revoke($f, self::COUNT));
            $this->assertSame('1', $this->counts($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_physical_count_is_not_created_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::COUNT);
        try {
            $this->refused($f, $this->createCount($f), $this->suspend($f));
            $this->assertSame('1', $this->counts($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- physical count: cancel

    private function cancelCount(array $f): \Closure
    {
        return fn () => app(CancelPhysicalCount::class)(PhysicalCount::query()->findOrFail($f['count']), $this->actorUser($f));
    }

    private function countStatus(array $f): string
    {
        return $this->scalar($f, "select status from physical_counts where id = {$f['count']}");
    }

    public function test_control_an_unchanged_actor_cancels_the_physical_count(): void
    {
        $f = $this->fixture(self::COUNT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->cancelCount($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('CANCELLED', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_physical_count_is_not_cancelled_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::COUNT);
        try {
            $this->refused($f, $this->cancelCount($f), $this->revoke($f, self::COUNT));
            $this->assertSame('DRAFT', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_physical_count_is_not_cancelled_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::COUNT);
        try {
            $this->refused($f, $this->cancelCount($f), $this->suspend($f));
            $this->assertSame('DRAFT', $this->countStatus($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- inventory cutover: create (opens the controlled process)

    private function createCutover(array $f): \Closure
    {
        return fn () => app(CreateInventoryCutover::class)([
            'mode' => 'OPENING_STATE', 'cutover_at' => '2026-09-01T00:00:00+05:00',
            'source_system' => 'Legacy ERP', 'target_valuation_policy' => ['SKU-100' => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM']],
            'control_quantity' => '10.0000', 'control_value' => '125.0000', 'base_control_value' => '125.0000',
        ], $this->actorUser($f));
    }

    private function cutovers(array $f): string
    {
        return $this->scalar($f, "select count(*) from inventory_cutovers where company_id = {$f['r']['company']}");
    }

    public function test_a_cutover_is_not_created_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::CUTOVER);
        try {
            $this->refused($f, $this->createCutover($f), $this->revoke($f, self::CUTOVER));
            $this->assertSame('0', $this->cutovers($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_cutover_is_not_created_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::CUTOVER);
        try {
            $this->refused($f, $this->createCutover($f), $this->suspend($f));
            $this->assertSame('0', $this->cutovers($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- purchase order draft: edit

    private function editOrder(array $f): \Closure
    {
        return fn () => app(UpdatePurchaseOrder::class)(PurchaseOrder::query()->findOrFail($f['po']), ['notes' => 'changed'], $this->actorUser($f));
    }

    public function test_a_draft_order_is_not_edited_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::PO);
        try {
            $this->refused($f, $this->editOrder($f), $this->revoke($f, self::PO));
            $this->assertSame('0', $this->scalar($f, "select count(*) from purchase_orders where id = {$f['po']} and notes = 'changed'"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_draft_order_is_not_edited_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::PO);
        try {
            $this->refused($f, $this->editOrder($f), $this->suspend($f));
            $this->assertSame('0', $this->scalar($f, "select count(*) from purchase_orders where id = {$f['po']} and notes = 'changed'"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
