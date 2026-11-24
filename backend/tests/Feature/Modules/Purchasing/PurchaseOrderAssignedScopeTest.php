<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\Company;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Administration\Support\PermissionVocabulary as AdministrationVocabulary;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderScopeAdapter;
use App\Modules\Sales\Support\PermissionVocabulary as SalesVocabulary;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\Support\PurchasingActor;

/**
 * Q26 / ADR 0192: ASSIGNED is its own scope — the order's named buyer — additive with OWN and MY_UNITS on the same duty, never a capability, never inferred from a missing buyer,
 * checked on create and draft edit so nobody files an order out of their own sight, and validated where limits are assigned.
 */
class PurchaseOrderAssignedScopeTest extends PurchaseOrderFoundationTest
{
    private const VIEW = 'purchasing.purchase-order.view';

    private const CREATE = 'purchasing.purchase-order.create';

    private User $buyer;

    private User $other;

    /** @var array<string, PurchaseOrder> */
    private array $po = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = PurchasingActor::make([]);
        $this->other = PurchasingActor::make([]);
    }

    private function orders(): void
    {
        if ($this->po !== []) {
            return;
        }
        [$base] = $this->makeOrder();
        $make = fn (string $key, ?User $creator, ?User $buyer) => $this->po[$key] = PurchaseOrder::create([
            'supplier_account_id' => $base->supplier_account_id, 'supplier_site_id' => $base->supplier_site_id, 'currency_id' => $base->currency_id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02', 'created_by' => $creator?->id, 'buyer_user_id' => $buyer?->id,
        ])->refresh();
        $make('mine', $this->other, $this->buyer);          // somebody else drafted it, the buyer is named
        $make('drafted', $this->buyer, $this->other);       // the buyer drafted it, but somebody else is the buyer
        $make('both', $this->buyer, $this->buyer);
        $make('unassigned', $this->buyer, null);            // no buyer named: nobody reaches it by assignment
        $make('legacy', null, null);
    }

    /** @return list<string> */
    private function listed(User $by): array
    {
        $ids = collect($this->actingAsUnprivileged($by)->getJson('/api/v1/purchase-orders?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        return collect($this->po)->filter(fn (PurchaseOrder $o) => in_array($o->id, $ids, true))->keys()->sort()->values()->all();
    }

    private function limit(User $user, string $duty, string $scope): void
    {
        AccessScopeLimit::create(['user_id' => $user->id, 'permission' => $duty, 'scope' => $scope]);
    }

    public function test_assigned_reaches_only_the_orders_that_name_the_person_as_buyer(): void
    {
        $this->orders();
        $reader = PurchasingActor::grant($this->buyer, [self::VIEW]);
        $this->limit($reader, self::VIEW, 'ASSIGNED');

        $this->assertSame(['both', 'mine'], $this->listed($reader), 'drafting an order is not being its buyer; a missing buyer and legacy orders give no path');
        $this->assertSame(2, $this->actingAsUnprivileged($reader)->getJson('/api/v1/purchase-orders')->json('meta.total'));
        $this->actingAsUnprivileged($reader)->getJson("/api/v1/purchase-orders/{$this->po['mine']->id}")->assertOk();
        foreach (['drafted', 'unassigned', 'legacy'] as $hidden) {
            $this->actingAsUnprivileged($reader)->getJson("/api/v1/purchase-orders/{$this->po[$hidden]->id}")->assertNotFound();
        }
    }

    public function test_being_named_buyer_confers_no_capability(): void
    {
        $this->orders();
        // Named on two orders, holding no purchasing duty at all.
        $this->actingAsUnprivileged($this->buyer)->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->actingAsUnprivileged($this->buyer)->getJson("/api/v1/purchase-orders/{$this->po['mine']->id}")->assertForbidden();
        $this->actingAsUnprivileged($this->buyer)->postJson("/api/v1/purchase-orders/{$this->po['mine']->id}/cancel", ['reason' => 'x'])->assertForbidden();
        // Limited to ASSIGNED on view only: they may read what they are named on, but not cancel it.
        $reader = PurchasingActor::grant($this->buyer, [self::VIEW]);
        $this->limit($reader, self::VIEW, 'ASSIGNED');
        $this->actingAsUnprivileged($reader)->postJson("/api/v1/purchase-orders/{$this->po['mine']->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_assigned_is_additive_with_own_and_units_on_the_same_duty_and_across_duties(): void
    {
        $this->orders();
        $user = PurchasingActor::grant($this->buyer, [self::VIEW, self::CREATE]);
        $this->limit($user, self::VIEW, 'ASSIGNED');
        $this->limit($user, self::CREATE, 'OWN');

        $this->assertSame(['both', 'drafted', 'mine', 'unassigned'], $this->listed($user), 'assigned (view) plus own-created (create) add up; legacy still out');
    }

    public function test_writes_follow_the_assigned_path_of_their_own_duty(): void
    {
        $this->orders();
        $user = PurchasingActor::grant($this->buyer, [self::VIEW, 'purchasing.purchase-order.cancel']);
        $this->limit($user, 'purchasing.purchase-order.cancel', 'ASSIGNED');
        $as = $this->actingAsUnprivileged($user);

        $as->postJson("/api/v1/purchase-orders/{$this->po['drafted']->id}/cancel", ['reason' => 'x'])->assertNotFound();
        $as->postJson("/api/v1/purchase-orders/{$this->po['mine']->id}/cancel", ['reason' => 'x'])->assertOk();
    }

    public function test_an_assigned_only_creator_must_name_themselves_buyer_and_may_not_move_the_buyer_away(): void
    {
        $this->orders();
        $drafter = PurchasingActor::grant($this->buyer, [self::CREATE]);
        $this->limit($drafter, self::CREATE, 'ASSIGNED');
        [$like, $item, $uom] = [$this->po['mine'], ...array_slice([Item::query()->first(), Uom::query()->first()], 0, 2)];
        $payload = fn (array $more) => $more + ['supplier_account_id' => $like->supplier_account_id, 'currency_id' => $like->currency_id, 'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-02',
            'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02',
            'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uom->id, 'unit_price' => '10.0000']]];
        $as = $this->actingAsUnprivileged($drafter);

        $as->postJson('/api/v1/purchase-orders', $payload([]))->assertStatus(422)->assertJsonValidationErrors('buyer_user_id');
        $as->postJson('/api/v1/purchase-orders', $payload(['buyer_user_id' => $this->other->id]))->assertStatus(422)->assertJsonValidationErrors('buyer_user_id');
        $created = $as->postJson('/api/v1/purchase-orders', $payload(['buyer_user_id' => $drafter->id]))->assertCreated()->json('data');
        $this->assertSame($drafter->id, $created['buyer_user_id']);

        // On a draft they reach through assignment they may edit, but cannot hand it to somebody else (they would lose sight of it) or clear the buyer.
        $as->patchJson("/api/v1/purchase-orders/{$created['id']}", ['notes' => 'ok'])->assertOk();
        $as->patchJson("/api/v1/purchase-orders/{$created['id']}", ['buyer_user_id' => $this->other->id])->assertStatus(422)->assertJsonValidationErrors('buyer_user_id');
        $as->patchJson("/api/v1/purchase-orders/{$created['id']}", ['buyer_user_id' => null])->assertStatus(422);
        $this->assertSame($drafter->id, PurchaseOrder::query()->find($created['id'])->buyer_user_id);

        // Another authorised path for the same duty frees the choice: created-by-me reaches it whoever the buyer is.
        $both = PurchasingActor::grant($this->other, [self::CREATE]);
        $this->limit($both, self::CREATE, 'ASSIGNED');
        $role = Role::create(['team_id' => 1, 'guard_name' => 'web', 'name' => 'own drafters']);
        $role->givePermissionTo(Permission::findOrCreate(self::CREATE, 'web'));
        $both->assignRole($role);
        AccessScopeLimit::create(['user_id' => $both->id, 'role_id' => $role->id, 'scope' => 'OWN']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAsUnprivileged($both)->postJson('/api/v1/purchase-orders', $payload(['buyer_user_id' => $this->buyer->id]))->assertCreated();
    }

    public function test_the_buyer_of_an_issued_order_cannot_be_reassigned(): void
    {
        $this->orders();
        DB::table('purchase_orders')->where('id', $this->po['mine']->id)->update(['status' => 'ISSUED', 'issued_at' => now(), 'number' => 'PO-ASSIGNED-1']);

        $this->expectException(\Throwable::class);
        PurchaseOrder::query()->findOrFail($this->po['mine']->id)->update(['buyer_user_id' => $this->other->id]);
    }

    public function test_money_follows_an_assigned_commercial_grant_and_queries_are_refused_when_it_is_narrower(): void
    {
        $this->orders();
        foreach (['mine', 'drafted'] as $key) {
            $order = $this->po[$key];
            $order->lines()->create(['line_amount' => '60.0000', 'base_line_amount' => '60.0000', 'unit_price' => '60.0000', 'base_unit_price' => '60.0000', 'ordered_qty' => '1.0000', 'ordered_stock_qty' => '1.0000'] + $this->lineAttributes($order, Item::query()->first(), Uom::query()->first()));
        }
        $reader = PurchasingActor::grant($this->buyer, [self::VIEW, 'purchasing.commercial-cost.view']);
        $this->limit($reader, 'purchasing.commercial-cost.view', 'ASSIGNED');
        $as = $this->actingAsUnprivileged($reader);

        $this->assertSame('60.0000', $as->getJson("/api/v1/purchase-orders/{$this->po['mine']->id}?include=lines")->assertOk()->json('data.total_amount'));
        $this->assertTrue($as->getJson("/api/v1/purchase-orders/{$this->po['mine']->id}")->json('data.commercial_visible'));
        $this->assertFalse($as->getJson("/api/v1/purchase-orders/{$this->po['drafted']->id}")->json('data.commercial_visible'));
        $this->assertArrayNotHasKey('total_amount', $as->getJson("/api/v1/purchase-orders/{$this->po['drafted']->id}?include=lines")->json('data'));
        $as->getJson('/api/v1/purchase-orders?sort=-total_amount')->assertForbidden();
        $this->assertFalse($as->getJson('/api/v1/purchase-orders')->json('commercial_query'));

        // Read and commercial reach both assigned: the list is covered, so the query is answered.
        $narrow = PurchasingActor::make([self::VIEW, 'purchasing.commercial-cost.view']);
        $this->limit($narrow, self::VIEW, 'ASSIGNED');
        $this->limit($narrow, 'purchasing.commercial-cost.view', 'ASSIGNED');
        $this->actingAsUnprivileged($narrow)->getJson('/api/v1/purchase-orders?sort=-total_amount')->assertOk();
    }

    public function test_unsupported_combinations_fail_closed(): void
    {
        $user = PurchasingActor::make([SalesVocabulary::QUOTATION_VIEW, self::VIEW]);
        $this->limit($user, SalesVocabulary::QUOTATION_VIEW, 'ASSIGNED');
        $resolver = app(RecordScope::class);

        // Sales' quotation adapter does not declare ASSIGNED, and the legacy resolver (used by Inventory's warehouse scope) knows only units.
        $this->assertTrue($resolver->resolveForResource($user, SalesVocabulary::QUOTATION_VIEW, 'quotation_family')->isNone());
        $this->assertTrue($resolver->resolve($user, SalesVocabulary::QUOTATION_VIEW)->isNone());
        $legacy = PurchasingActor::make(['inventory.stock.view']);
        $this->limit($legacy, 'inventory.stock.view', 'ASSIGNED');
        $this->assertTrue($resolver->resolve($legacy, 'inventory.stock.view')->isNone());
        // An assigned limit on a role that also carries a duty the purchase-order adapter does not declare reaches nothing.
        $mixed = Role::create(['team_id' => 1, 'guard_name' => 'web', 'name' => 'mixed']);
        $mixed->givePermissionTo([Permission::findOrCreate(self::VIEW, 'web'), Permission::findOrCreate('purchasing.goods-receipt.post', 'web')]);
        $holder = User::factory()->create();
        $holder->assignRole($mixed);
        AccessScopeLimit::create(['user_id' => $holder->id, 'role_id' => $mixed->id, 'scope' => 'ASSIGNED']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($resolver->resolveForResource($holder, self::VIEW, 'purchase_order')->isNone());
        // The adapter declares ASSIGNED for purchase-order duties only; OWN_OR_DELEGATED is still not read as anything.
        $adapter = new PurchaseOrderScopeAdapter;
        $this->assertTrue($adapter->supports(self::VIEW, 'ASSIGNED'));
        $this->assertFalse($adapter->supports('purchasing.goods-receipt.post', 'ASSIGNED'));
        $this->assertFalse($adapter->supports(self::VIEW, 'OWN_OR_DELEGATED'));
    }

    public function test_administration_validates_assigned_and_offers_it_only_where_every_duty_supports_it(): void
    {
        $admin = PermissionedActor::grant(User::factory()->create(), AdministrationVocabulary::all());
        PermissionedActor::grant($admin, [self::VIEW, 'purchasing.goods-receipt.post', SalesVocabulary::QUOTATION_VIEW]);
        $member = User::factory()->create();
        $buyers = Role::create(['team_id' => 1, 'guard_name' => 'web', 'name' => 'Buyers']);
        $buyers->givePermissionTo(Permission::findOrCreate(self::VIEW, 'web'));
        $mixed = Role::create(['team_id' => 1, 'guard_name' => 'web', 'name' => 'Mixed']);
        $mixed->givePermissionTo([Permission::findOrCreate(self::VIEW, 'web'), Permission::findOrCreate('purchasing.goods-receipt.post', 'web')]);
        $sales = Role::create(['team_id' => 1, 'guard_name' => 'web', 'name' => 'Sales']);
        $sales->givePermissionTo(Permission::findOrCreate(SalesVocabulary::QUOTATION_VIEW, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $as = $this->actingAs($admin);

        $as->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$buyers->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $buyers->id, 'scope' => 'ASSIGNED']]])
            ->assertOk()->assertJsonPath('data.scope_limits.0.scope', 'ASSIGNED');
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'role_id' => $buyers->id, 'scope' => 'ASSIGNED']);
        $as->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$mixed->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $mixed->id, 'scope' => 'ASSIGNED']]])->assertUnprocessable()->assertJsonValidationErrors('scope_limits');
        $as->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$sales->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $sales->id, 'scope' => 'ASSIGNED']]])->assertUnprocessable();
        $as->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$buyers->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $buyers->id, 'scope' => 'EVERYTHING']]])->assertUnprocessable();

        // A role limited to ASSIGNED somewhere cannot later acquire an unsupported duty.
        $as->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$buyers->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $buyers->id, 'scope' => 'ASSIGNED']]])->assertOk();
        $as->putJson("/api/v1/roles/{$buyers->id}", ['name' => 'Buyers', 'permissions' => [self::VIEW, 'purchasing.goods-receipt.post'], 'reason' => 'x'])->assertUnprocessable();

        // The role list says which limits each role supports.
        $modes = collect($as->getJson('/api/v1/roles')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['MY_UNITS', 'OWN', 'ASSIGNED'], $modes['Buyers']['limit_modes']);
        $this->assertSame(['MY_UNITS'], $modes['Mixed']['limit_modes']);
    }

    public function test_the_migration_refuses_to_roll_back_over_assigned_limits_and_restores_cleanly_without_them(): void
    {
        $migration = require database_path('migrations/2026_12_17_100000_extend_access_scope_limits_for_assigned.php');
        $user = PurchasingActor::make([self::VIEW]);
        $this->limit($user, self::VIEW, 'ASSIGNED');

        try {
            $migration->down();
            $this->fail('A rollback must not drop a restriction that is still in force.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ASSIGNED', $e->getMessage());
        }
        $this->assertSame(1, DB::table('access_scope_limits')->where('scope', 'ASSIGNED')->count(), 'the limit is untouched');

        DB::table('access_scope_limits')->where('scope', 'ASSIGNED')->delete();
        $migration->down();
        try {
            DB::transaction(fn () => DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $user->id, 'permission' => self::VIEW, 'scope' => 'ASSIGNED', 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('After the rollback ASSIGNED is not a valid scope.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $migration->up();
        $this->limit($user, self::VIEW, 'ASSIGNED');
        $this->assertSame('ASSIGNED', DB::table('access_scope_limits')->value('scope'));
    }

    public function test_the_buyer_lookup_returns_only_id_and_name_of_active_members_of_this_company_to_a_drafter(): void
    {
        $drafter = PurchasingActor::make([self::CREATE]);
        $active = User::factory()->create(['name' => 'Qxa Active']);
        $suspended = User::factory()->create(['name' => 'Qxb Suspended', 'status' => MemberStatus::Suspended]);
        $foreign = null;
        $this->actingAsCompany2(function () use (&$foreign): void {
            $foreign = User::factory()->create(['name' => 'Qxc Foreign']);
        });

        $names = fn (string $q) => collect($this->actingAsUnprivileged($drafter)->getJson('/api/v1/purchase-order-buyers?search='.urlencode($q))->assertOk()->json('data'));
        $found = $names('Qx');
        $this->assertSame(['Qxa Active'], $found->pluck('name')->all(), 'active only, this company only');
        $this->assertSame(['id', 'name'], array_keys($found->first()), 'nothing but id and name leaves');
        $this->assertStringNotContainsString('@', (string) json_encode($found->all()));
        $this->assertContains($drafter->name, $names('')->pluck('name')->all());
        $this->assertSame([], $names('no-such-person')->all());
        $this->assertSame([], $names('%')->all(), 'a wildcard is searched for literally');

        $reader = PurchasingActor::make([self::VIEW]);
        $this->actingAsUnprivileged($reader)->getJson('/api/v1/purchase-order-buyers')->assertForbidden();
        unset($active, $suspended, $foreign);
    }

    private function actingAsCompany2(\Closure $work): void
    {
        $other = Company::create(['name' => 'Other Co']);
        $this->app->instance(CompanyContext::class, new class($other->id) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $work();
        $this->app->instance(CompanyContext::class, new class(1) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }
}
