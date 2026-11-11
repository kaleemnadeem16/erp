<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Inventory\Actions\UpdateInventorySettings;
use App\Modules\Inventory\Actions\UpdateWarehouse;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\ArchiveItemSupplier;
use App\Modules\Purchasing\Actions\ReconcilePurchaseOrderFx;
use App\Modules\Purchasing\Actions\UpdatePurchasingSettings;
use App\Modules\Purchasing\Models\ItemSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #91 row 9c: settings, Purchasing FX reconciliation and master-data Actions decide their actor INSIDE the transaction, from the stored person, after the company
 * access barrier (staging: see DecisionTimeAuthorityRaceTest). Narrow on purpose: one Action per family, each refused when the duty is revoked or the actor suspended
 * meanwhile, with a control that proves the same call succeeds when nothing changed.
 */
class DecisionTimeAuthorityMasterDataRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const WAREHOUSE = 'inventory.warehouse.manage';

    private const SUPPLIER_LINK = 'purchasing.item-supplier.manage';

    private const OVER_RECEIPT = 'purchasing.over-receipt-policy.manage';

    private const RECALCULATION = 'inventory.recalculation-policy.manage';

    private const FX_OVERRIDE = 'purchasing.purchase-order.fx-override';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, link: int} */
    private function fixture(string $duty): array
    {
        $pdo = $this->raceSession();
        $prefix = 'dm'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $this->raceGrant($pdo, $r['company'], $actor, $duty);
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);
        $link = (int) $pdo->query("insert into item_suppliers (company_id, item_id, supplier_account_id, active, created_at, updated_at) values ({$r['company']}, {$r['item']}, {$r['supplier']}, true, now(), now()) returning id")->fetchColumn();

        return compact('pdo', 'r', 'actor', 'link');
    }

    private function gate(): array
    {
        return ['select company_id from company_access_generations order by company_id for update'];
    }

    private function scalar(array $f, string $sql): string
    {
        return (string) $f['pdo']->query($sql)->fetchColumn();
    }

    private function actorUser(array $f): User
    {
        return User::query()->findOrFail($f['actor']);
    }

    private function suspend(array $f): \Closure
    {
        return fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}");
    }

    private function revoke(array $f, string $duty): \Closure
    {
        return fn ($h) => $this->raceRevoke($h, $f['actor'], $duty);
    }

    private function refused(array $f, \Closure $action, \Closure $mutate): void
    {
        $o = $this->raceAction($f['pdo'], $this->gate(), $action, $mutate);
        $this->assertFalse($o['success']);
        $this->assertStringContainsString('not permitted', (string) $o['message']);
    }

    // ---- master data (Inventory): edit a warehouse

    private function editWarehouse(array $f): \Closure
    {
        return fn () => app(UpdateWarehouse::class)(Warehouse::query()->findOrFail($f['r']['warehouse']), ['name' => 'Renamed'], $this->actorUser($f));
    }

    private function renamed(array $f): string
    {
        return $this->scalar($f, "select count(*) from warehouses where id = {$f['r']['warehouse']} and name = 'Renamed'");
    }

    public function test_control_an_unchanged_actor_edits_the_warehouse(): void
    {
        $f = $this->fixture(self::WAREHOUSE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->editWarehouse($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('1', $this->renamed($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_warehouse_is_not_edited_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::WAREHOUSE);
        try {
            $this->refused($f, $this->editWarehouse($f), $this->revoke($f, self::WAREHOUSE));
            $this->assertSame('0', $this->renamed($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_warehouse_is_not_edited_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::WAREHOUSE);
        try {
            $this->refused($f, $this->editWarehouse($f), $this->suspend($f));
            $this->assertSame('0', $this->renamed($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- master data (Purchasing): archive an item-supplier link

    private function archiveLink(array $f): \Closure
    {
        return fn () => app(ArchiveItemSupplier::class)(ItemSupplier::query()->findOrFail($f['link']), $this->actorUser($f));
    }

    private function linkActive(array $f): string
    {
        return $this->scalar($f, "select active::int from item_suppliers where id = {$f['link']}");
    }

    public function test_control_an_unchanged_actor_archives_the_item_supplier_link(): void
    {
        $f = $this->fixture(self::SUPPLIER_LINK);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->archiveLink($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('0', $this->linkActive($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_an_item_supplier_link_is_not_archived_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::SUPPLIER_LINK);
        try {
            $this->refused($f, $this->archiveLink($f), $this->revoke($f, self::SUPPLIER_LINK));
            $this->assertSame('1', $this->linkActive($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_an_item_supplier_link_is_not_archived_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::SUPPLIER_LINK);
        try {
            $this->refused($f, $this->archiveLink($f), $this->suspend($f));
            $this->assertSame('1', $this->linkActive($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- settings (Purchasing): over-receipt policy

    private function changePurchasingPolicy(array $f): \Closure
    {
        return fn () => app(UpdatePurchasingSettings::class)(['over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE', 'over_receipt_tolerance_percent' => '7'], $this->actorUser($f), 'race');
    }

    private function purchasingPolicyChanges(array $f): string
    {
        return $this->scalar($f, "select count(*) from purchasing_settings where over_receipt_mode = 'ALLOW_WITHIN_TOLERANCE'");
    }

    public function test_control_an_unchanged_actor_changes_the_purchasing_policy(): void
    {
        $f = $this->fixture(self::OVER_RECEIPT);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->changePurchasingPolicy($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('1', $this->purchasingPolicyChanges($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_the_purchasing_policy_is_not_changed_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::OVER_RECEIPT);
        try {
            $this->refused($f, $this->changePurchasingPolicy($f), $this->revoke($f, self::OVER_RECEIPT));
            $this->assertSame('0', $this->purchasingPolicyChanges($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_the_purchasing_policy_is_not_changed_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::OVER_RECEIPT);
        try {
            $this->refused($f, $this->changePurchasingPolicy($f), $this->suspend($f));
            $this->assertSame('0', $this->purchasingPolicyChanges($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- settings (Inventory): recalculation policy

    private function changeInventoryPolicy(array $f): \Closure
    {
        return fn () => app(UpdateInventorySettings::class)(['recalculation_review_sla_hours' => 13], $this->actorUser($f), 'race');
    }

    private function inventoryPolicyChanges(array $f): string
    {
        return $this->scalar($f, "select count(*) from companies where id = {$f['r']['company']} and recalculation_review_sla_hours = 13");
    }

    public function test_control_an_unchanged_actor_changes_the_inventory_policy(): void
    {
        $f = $this->fixture(self::RECALCULATION);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->changeInventoryPolicy($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('1', $this->inventoryPolicyChanges($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_the_inventory_policy_is_not_changed_after_the_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::RECALCULATION);
        try {
            $this->refused($f, $this->changeInventoryPolicy($f), $this->revoke($f, self::RECALCULATION));
            $this->assertSame('0', $this->inventoryPolicyChanges($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_the_inventory_policy_is_not_changed_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::RECALCULATION);
        try {
            $this->refused($f, $this->changeInventoryPolicy($f), $this->suspend($f));
            $this->assertSame('0', $this->inventoryPolicyChanges($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- Purchasing FX reconciliation: a fixed rate needs the override duty at decision time

    /** The calling Action runs this inside its own transaction; the test does the same so the access barrier applies. */
    private function fixRate(array $f): \Closure
    {
        return fn () => DB::transaction(function () use ($f): array {
            $header = ['currency_id' => $f['r']['currency'], 'fx_rate' => '2', 'fx_rate_date' => now()->toDateString(), 'fx_rate_type' => 'SPOT', 'fx_policy' => 'FIXED', 'fx_override_reason' => 'Contract reference FWD-2026-001'];
            $evidence = app(ReconcilePurchaseOrderFx::class)($header, $this->actorUser($f));
            $this->assertSame($f['actor'], $evidence['fx_override_by']);

            return $evidence;
        });
    }

    public function test_control_an_unchanged_actor_fixes_a_rate_with_the_override_duty(): void
    {
        $f = $this->fixture(self::FX_OVERRIDE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->fixRate($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_rate_is_not_fixed_after_the_override_duty_is_revoked_meanwhile(): void
    {
        $f = $this->fixture(self::FX_OVERRIDE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate(), $this->fixRate($f), $this->revoke($f, self::FX_OVERRIDE));
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('not accepted', (string) $o['message']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_rate_is_not_fixed_after_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::FX_OVERRIDE);
        try {
            $this->refused($f, $this->fixRate($f), $this->suspend($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
