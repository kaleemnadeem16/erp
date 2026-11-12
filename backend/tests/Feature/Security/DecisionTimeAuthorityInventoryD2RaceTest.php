<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Inventory\Actions\DeleteWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Owner decision D2 (issue #91): item-packaging maintenance and warehouse-location-role removal decide their actor INSIDE the transaction, from the stored
 * person, after the company access barrier. Each is refused when the duty is revoked or the actor suspended between the early check and the commit, with a control.
 */
class DecisionTimeAuthorityInventoryD2RaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const ITEM_MANAGE = 'inventory.item.manage';

    private const LOCATION_MANAGE = 'inventory.location.manage';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, package: int, role: int} */
    private function fixture(string $duty): array
    {
        $pdo = $this->raceSession();
        $prefix = 'd2'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $this->raceGrant($pdo, $r['company'], $actor, $duty);
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);
        $package = (int) $pdo->query("insert into item_packagings (company_id, item_id, code, name, stock_quantity, active, created_at, updated_at) values ({$r['company']}, {$r['item']}, 'box', 'Box', 6, true, now(), now()) returning id")->fetchColumn();
        $role = (int) $pdo->query("insert into warehouse_location_roles (company_id, warehouse_id, location_id, role, created_at, updated_at) values ({$r['company']}, {$r['warehouse']}, {$r['location']}, 'RECEIVING', now(), now()) returning id")->fetchColumn();

        return compact('pdo', 'r', 'actor', 'package', 'role');
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

    private function refused(array $f, \Closure $action, string $how, string $duty): void
    {
        $mutate = $how === 'suspend'
            ? fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}")
            : fn ($h) => $this->raceRevoke($h, $f['actor'], $duty);
        $o = $this->raceAction($f['pdo'], $this->gate(), $action, $mutate);
        $this->assertFalse($o['success']);
        $this->assertStringContainsString('not permitted', (string) $o['message']);
    }

    private function scenario(string $duty, \Closure $make, ?string $how, \Closure $assertState): void
    {
        $f = $this->fixture($duty);
        try {
            $action = $make($f);
            if ($how === null) {
                $o = $this->raceAction($f['pdo'], $this->gate(), $action, fn () => null);
                $this->assertTrue($o['success'], (string) $o['message']);
                $assertState($f, true);
            } else {
                $this->refused($f, $action, $how, $duty);
                $assertState($f, false);
            }
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    // ---- item packaging: update and create

    private function rename(): \Closure
    {
        return fn (array $f) => fn () => app(MaintainItemPackaging::class)(['name' => 'Renamed'], $this->actorUser($f), ItemPackaging::query()->findOrFail($f['package']));
    }

    private function renamed(array $f, bool $expected): void
    {
        $this->assertSame($expected ? '1' : '0', $this->scalar($f, "select count(*) from item_packagings where id = {$f['package']} and name = 'Renamed'"));
    }

    private function create(): \Closure
    {
        return fn (array $f) => fn () => app(MaintainItemPackaging::class)([
            'item_id' => $f['r']['item'], 'code' => 'pallet', 'name' => 'Pallet', 'stock_quantity' => '60',
        ], $this->actorUser($f));
    }

    private function created(array $f, bool $expected): void
    {
        $this->assertSame($expected ? '1' : '0', $this->scalar($f, "select count(*) from item_packagings where company_id = {$f['r']['company']} and code = 'pallet'"));
    }

    public function test_control_an_unchanged_actor_updates_a_package(): void
    {
        $this->scenario(self::ITEM_MANAGE, $this->rename(), null, fn ($f, $e) => $this->renamed($f, $e));
    }

    public function test_a_package_is_not_updated_after_the_duty_is_revoked_meanwhile(): void
    {
        $this->scenario(self::ITEM_MANAGE, $this->rename(), 'revoke', fn ($f, $e) => $this->renamed($f, $e));
    }

    public function test_a_package_is_not_updated_after_the_actor_is_suspended_meanwhile(): void
    {
        $this->scenario(self::ITEM_MANAGE, $this->rename(), 'suspend', fn ($f, $e) => $this->renamed($f, $e));
    }

    public function test_control_an_unchanged_actor_creates_a_package(): void
    {
        $this->scenario(self::ITEM_MANAGE, $this->create(), null, fn ($f, $e) => $this->created($f, $e));
    }

    public function test_a_package_is_not_created_after_the_duty_is_revoked_meanwhile(): void
    {
        $this->scenario(self::ITEM_MANAGE, $this->create(), 'revoke', fn ($f, $e) => $this->created($f, $e));
    }

    public function test_a_package_is_not_created_after_the_actor_is_suspended_meanwhile(): void
    {
        $this->scenario(self::ITEM_MANAGE, $this->create(), 'suspend', fn ($f, $e) => $this->created($f, $e));
    }

    // ---- warehouse location role: remove

    private function remove(): \Closure
    {
        return fn (array $f) => fn () => app(DeleteWarehouseLocationAssignment::class)(WarehouseLocationAssignment::query()->findOrFail($f['role']), $this->actorUser($f));
    }

    private function removed(array $f, bool $expected): void
    {
        $this->assertSame($expected ? '0' : '1', $this->scalar($f, "select count(*) from warehouse_location_roles where id = {$f['role']}"));
    }

    public function test_control_an_unchanged_actor_removes_a_location_role(): void
    {
        $this->scenario(self::LOCATION_MANAGE, $this->remove(), null, fn ($f, $e) => $this->removed($f, $e));
    }

    public function test_a_location_role_is_not_removed_after_the_duty_is_revoked_meanwhile(): void
    {
        $this->scenario(self::LOCATION_MANAGE, $this->remove(), 'revoke', fn ($f, $e) => $this->removed($f, $e));
    }

    public function test_a_location_role_is_not_removed_after_the_actor_is_suspended_meanwhile(): void
    {
        $this->scenario(self::LOCATION_MANAGE, $this->remove(), 'suspend', fn ($f, $e) => $this->removed($f, $e));
    }
}
