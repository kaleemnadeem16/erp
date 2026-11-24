<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Models\OrganisationUnitResource;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\ShortClosePurchaseOrderLine;
use App\Modules\Purchasing\Actions\SubmitPurchaseOrder;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderAccess;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderScopeAdapter;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

/**
 * Q26 / ADR 0192: purchase-order record scope. OWN = the immutable creator; MY_UNITS = the ship-to warehouse belongs to one of the person's units;
 * an unrestricted grant keeps the whole company. Old orders with no creator or ship-to are never reached by a limited person and never given an identity.
 */
class PurchaseOrderRecordScopeTest extends PurchaseOrderFoundationTest
{
    private const VIEW = 'purchasing.purchase-order.view';

    private Warehouse $whA;

    private Warehouse $whB;

    private Warehouse $whC;

    private OrganisationUnit $unitA;

    private OrganisationUnit $unitB;

    private User $alice;

    private User $bob;

    /** @var array<string, PurchaseOrder> */
    private array $po = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = PurchasingActor::make(['inventory.warehouse.manage']);
        foreach (['a', 'b', 'c'] as $code) {
            $this->{'wh'.strtoupper($code)} = app(CreateWarehouse::class)(['code' => $code, 'name' => strtoupper($code), 'location_code' => 'dock', 'location_name' => 'Dock'], $admin);
        }
        $this->unitA = OrganisationUnit::create(['code' => 'ua', 'name' => 'UA', 'status' => 'ACTIVE']);
        $this->unitB = OrganisationUnit::create(['code' => 'ub', 'name' => 'UB', 'status' => 'ACTIVE']);
        OrganisationUnitResource::create(['unit_id' => $this->unitA->id, 'resource_type' => 'warehouse', 'resource_id' => $this->whA->id]);
        OrganisationUnitResource::create(['unit_id' => $this->unitB->id, 'resource_type' => 'warehouse', 'resource_id' => $this->whB->id]);
        $this->alice = PurchasingActor::make([]);
        $this->bob = PurchasingActor::make([]);
    }

    /** Built on first use, so the inherited foundation tests (which make their own supplier) are not disturbed. */
    private function orders(): void
    {
        if ($this->po !== []) {
            return;
        }
        [$base] = $this->makeOrder();
        $this->po['base'] = $base;
        $this->newOrder('aliceA', $this->alice, $this->whA);
        $this->newOrder('bobB', $this->bob, $this->whB);
        $this->newOrder('bobC', $this->bob, $this->whC);
        $this->newOrder('aliceB', $this->alice, $this->whB);
        $this->newOrder('legacy', null, null);
    }

    private function newOrder(string $key, ?User $by, ?Warehouse $wh): PurchaseOrder
    {
        $base = $this->po['base'];

        return $this->po[$key] = PurchaseOrder::create([
            'supplier_account_id' => $base->supplier_account_id, 'supplier_site_id' => $base->supplier_site_id, 'currency_id' => $base->currency_id,
            'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02',
            'created_by' => $by?->id, 'ship_to_warehouse_id' => $wh?->id,
        ])->refresh();
    }

    private function limited(array $permissions, string $scope, ?string $limitOn = null, ?User $user = null): User
    {
        $user = $user ?? PurchasingActor::make($permissions);
        if ($user->getAllPermissions()->isEmpty() || $user->wasRecentlyCreated === false) {
            $user = PurchasingActor::grant($user, $permissions);
        }
        AccessScopeLimit::create(['user_id' => $user->id, 'permission' => $limitOn ?? $permissions[0], 'scope' => $scope]);

        return $user;
    }

    private function member(User $user, OrganisationUnit ...$units): User
    {
        foreach ($units as $unit) {
            DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    /** @return list<string> */
    private function listed(User $by, string $query = ''): array
    {
        $ids = collect($this->actingAsUnprivileged($by)->getJson('/api/v1/purchase-orders?per_page=100'.$query)->assertOk()->json('data'))->pluck('id')->all();

        return collect($this->po)->filter(fn (PurchaseOrder $o) => in_array($o->id, $ids, true))->keys()->sort()->values()->all();
    }

    public function test_an_unrestricted_grant_still_reaches_every_order_including_legacy(): void
    {
        $this->orders();
        $boss = PurchasingActor::make([self::VIEW]);

        $this->assertSame(['aliceA', 'aliceB', 'base', 'bobB', 'bobC', 'legacy'], $this->listed($boss));
    }

    public function test_own_reaches_only_the_immutable_creator_and_never_legacy_orders(): void
    {
        $this->orders();
        $alice = $this->limited([self::VIEW], 'OWN', null, $this->alice);

        $this->assertSame(['aliceA', 'aliceB'], $this->listed($alice));
        $this->assertSame(2, $this->actingAsUnprivileged($alice)->getJson('/api/v1/purchase-orders')->json('meta.total'));
    }

    public function test_my_units_reaches_ship_to_warehouses_of_active_memberships_only(): void
    {
        $this->orders();
        $clerk = $this->member($this->limited([self::VIEW], 'MY_UNITS'), $this->unitA);

        $this->assertSame(['aliceA'], $this->listed($clerk));
        $this->member($clerk, $this->unitB);
        $this->assertSame(['aliceA', 'aliceB', 'bobB'], $this->listed($clerk), 'several units add up; warehouse C, owned by no unit, and legacy orders stay out');
    }

    public function test_a_limited_person_with_no_unit_or_a_suspended_unit_reaches_nothing(): void
    {
        $this->orders();
        $lost = $this->limited([self::VIEW], 'MY_UNITS');
        $this->assertSame([], $this->listed($lost));
        $this->member($lost, $this->unitA);
        $this->unitA->update(['status' => 'ARCHIVED']);
        $this->assertSame([], $this->listed($lost));
    }

    public function test_counts_filters_search_and_sort_stay_inside_the_scope(): void
    {
        $this->orders();
        $alice = $this->limited([self::VIEW], 'OWN', null, $this->alice);
        $number = $this->po['bobB']->number ?? 'zzz';

        $this->assertSame(['aliceA', 'aliceB'], $this->listed($alice, '&sort=-created_at&filter[status]=DRAFT'));
        $this->assertSame([], $this->listed($alice, '&filter[search]='.urlencode((string) $number).'&filter[status]=ISSUED'));
        $this->assertSame(2, $this->actingAsUnprivileged($alice)->getJson('/api/v1/purchase-orders?filter[status]=DRAFT&sort=-order_date')->json('meta.total'));
    }

    public function test_detail_of_an_unreachable_order_is_a_404_identical_to_an_absent_one(): void
    {
        $this->orders();
        $alice = $this->limited([self::VIEW], 'OWN', null, $this->alice);

        $this->actingAsUnprivileged($alice)->getJson("/api/v1/purchase-orders/{$this->po['aliceA']->id}")->assertOk();
        $hidden = $this->actingAsUnprivileged($alice)->getJson("/api/v1/purchase-orders/{$this->po['bobB']->id}")->assertNotFound()->json();
        $absent = $this->actingAsUnprivileged($alice)->getJson('/api/v1/purchase-orders/999999')->assertNotFound()->json();
        $this->assertSame(array_keys($absent), array_keys($hidden));
        $this->actingAsUnprivileged($alice)->getJson("/api/v1/purchase-orders/{$this->po['legacy']->id}")->assertNotFound();
    }

    public function test_scope_changes_and_revocation_take_effect_on_the_next_request(): void
    {
        $this->orders();
        $clerk = $this->member($this->limited([self::VIEW], 'MY_UNITS'), $this->unitA);
        $this->assertSame(['aliceA'], $this->listed($clerk));

        OrganisationUnitResource::query()->where('unit_id', $this->unitA->id)->update(['resource_id' => $this->whC->id]);
        $this->assertSame(['bobC'], $this->listed($clerk), 'moving the warehouse to another unit moves the orders with it');

        AccessScopeLimit::query()->where('user_id', $clerk->id)->delete();
        $this->assertCount(6, $this->listed($clerk), 'removing the limit restores company-wide access');

        $clerk->revokePermissionTo(self::VIEW);
        $this->actingAsUnprivileged($clerk->fresh())->getJson('/api/v1/purchase-orders')->assertForbidden();
    }

    public function test_own_and_unit_limits_on_different_duties_widen_reads_only_to_their_union(): void
    {
        $this->orders();
        $user = PurchasingActor::make([self::VIEW, 'purchasing.purchase-order.create']);
        AccessScopeLimit::create(['user_id' => $user->id, 'permission' => self::VIEW, 'scope' => 'MY_UNITS']);
        AccessScopeLimit::create(['user_id' => $user->id, 'permission' => 'purchasing.purchase-order.create', 'scope' => 'OWN']);
        $this->member($user, $this->unitA);
        $this->newOrder('mine', $user, $this->whC);

        $this->assertSame(['aliceA', 'mine'], $this->listed($user));
    }

    public function test_an_unrelated_unrestricted_receiving_duty_does_not_widen_a_limited_order_grant(): void
    {
        $this->orders();
        $alice = $this->limited([self::VIEW, 'purchasing.goods-receipt.create'], 'OWN', self::VIEW, $this->alice);
        // The role-less direct grants: limit only the PO duty, receiving stays unrestricted.
        $this->assertSame(['aliceA', 'aliceB'], $this->listed($alice));

        $receiver = PurchasingActor::make(['purchasing.goods-receipt.create']);
        $this->assertCount(6, $this->listed($receiver), 'receiving alone keeps reading every order, as before');
    }

    public function test_a_limited_assignment_that_carries_an_unsupported_duty_fails_closed(): void
    {
        $this->orders();
        $role = Role::create(['team_id' => 1, 'name' => 'mixed buyer']);
        $role->givePermissionTo([Permission::findOrCreate(self::VIEW), Permission::findOrCreate('purchasing.supplier-return.post')]);
        $mixed = User::factory()->create();
        $mixed->assignRole($role);
        AccessScopeLimit::create(['user_id' => $mixed->id, 'role_id' => $role->id, 'scope' => 'OWN']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame([], $this->listed($mixed), 'a limit on a role that also carries an unsupported duty never widens');
        $unsupported = PurchasingActor::make([self::VIEW]);
        AccessScopeLimit::create(['user_id' => $unsupported->id, 'permission' => self::VIEW, 'scope' => 'OWN_OR_DELEGATED']);
        $this->assertSame([], $this->listed($unsupported));
    }

    public function test_every_write_is_a_404_on_an_unreachable_order_at_http_and_in_the_action(): void
    {
        $this->orders();
        $caps = ['purchasing.purchase-order.view', 'purchasing.purchase-order.create', 'purchasing.purchase-order.submit', 'purchasing.purchase-order.issue', 'purchasing.purchase-order.cancel', 'purchasing.purchase-order.short-close'];
        $alice = PurchasingActor::grant($this->alice, $caps);
        foreach ($caps as $duty) {
            AccessScopeLimit::create(['user_id' => $alice->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        $order = $this->po['bobB'];
        $line = $order->lines()->create($this->lineAttributes($order, ...array_slice($this->makeItemAndUom(), 0, 2)));
        $as = $this->actingAsUnprivileged($alice);

        $as->patchJson("/api/v1/purchase-orders/{$order->id}", ['notes' => 'x'])->assertNotFound();
        $as->postJson("/api/v1/purchase-orders/{$order->id}/submit")->assertNotFound();
        $as->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertNotFound();
        $as->postJson("/api/v1/purchase-orders/{$order->id}/cancel", ['reason' => 'no'])->assertNotFound();
        $as->postJson("/api/v1/purchase-order-lines/{$line->id}/short-close")->assertNotFound();

        foreach ([
            fn () => app(UpdatePurchaseOrder::class)($order, ['notes' => 'x'], $alice),
            fn () => app(SubmitPurchaseOrder::class)($order, $alice),
            fn () => app(IssuePurchaseOrder::class)($order, $alice),
            fn () => app(CancelPurchaseOrder::class)($order, 'no', $alice),
            fn () => app(ShortClosePurchaseOrderLine::class)($line, $alice),
        ] as $call) {
            try {
                DB::transaction($call);
                $this->fail('An out-of-scope write must not succeed.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('DRAFT', $order->fresh()->status->value);
        // Her own order still works.
        $as->patchJson("/api/v1/purchase-orders/{$this->po['aliceA']->id}", ['notes' => 'mine'])->assertOk();
    }

    public function test_a_limited_creator_cannot_file_into_an_unreachable_warehouse_or_name_an_unreachable_replaced_order(): void
    {
        $this->orders();
        $clerk = $this->member(PurchasingActor::make(['purchasing.purchase-order.create']), $this->unitA);
        AccessScopeLimit::create(['user_id' => $clerk->id, 'permission' => 'purchasing.purchase-order.create', 'scope' => 'MY_UNITS']);
        [$like, $item, $uom] = [$this->po['base'], ...array_slice($this->makeItemAndUom(), 0, 2)];
        $payload = fn (array $more) => $more + ['supplier_account_id' => $like->supplier_account_id, 'currency_id' => $like->currency_id, 'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-02',
            'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02',
            'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uom->id, 'unit_price' => '10.0000']]];
        $as = $this->actingAsUnprivileged($clerk);

        $as->postJson('/api/v1/purchase-orders', $payload(['ship_to_warehouse_id' => $this->whB->id]))->assertStatus(422);
        $as->postJson('/api/v1/purchase-orders', $payload([]))->assertStatus(422);
        $as->postJson('/api/v1/purchase-orders', $payload(['ship_to_warehouse_id' => $this->whA->id, 'replaces_purchase_order_id' => $this->po['bobB']->id]))->assertNotFound();
        $as->postJson('/api/v1/purchase-orders', $payload(['ship_to_warehouse_id' => $this->whA->id]))->assertCreated();
    }

    public function test_the_buyer_is_not_a_scope_path_until_the_owner_decides_it(): void
    {
        $this->orders();
        PurchaseOrder::query()->whereKey($this->po['bobB']->id)->update(['buyer_user_id' => $this->alice->id]);
        $alice = $this->limited([self::VIEW], 'OWN', null, $this->alice);

        $this->assertSame(['aliceA', 'aliceB'], $this->listed($alice), 'being named buyer confers nothing today');
    }

    public function test_the_adapter_declares_exactly_the_limitable_duties_and_modes(): void
    {
        $this->orders();
        $adapter = new PurchaseOrderScopeAdapter;
        foreach (PurchaseOrderScopeAdapter::LIMITABLE as $duty) {
            $this->assertTrue($adapter->supports($duty, 'OWN') && $adapter->supports($duty, 'MY_UNITS') && $adapter->supports($duty, 'ALL'));
            $this->assertFalse($adapter->supports($duty, 'OWN_OR_DELEGATED'));
        }
        foreach (['purchasing.purchase-order.approve', 'purchasing.goods-receipt.post', 'purchasing.cutover.commit'] as $duty) {
            $this->assertFalse($adapter->supports($duty, 'OWN'), $duty.' must not be limitable here');
        }
        $this->assertTrue(PurchaseOrderAccess::make()->isUnrestricted(PurchasingActor::make([self::VIEW]), self::VIEW));
    }

    /** The body of a 404 with the requested id blanked out, so only what could tell a hidden order from an absent one is left to compare. */
    private function notFound(TestResponse $response, int $id): array
    {
        $response->assertNotFound();

        return ['status' => $response->getStatusCode(), 'body' => str_replace((string) $id, '{id}', (string) $response->getContent()), 'type' => $response->headers->get('Content-Type')];
    }

    public function test_a_hidden_order_and_an_absent_one_give_identical_404_responses_on_every_entry(): void
    {
        $this->orders();
        config(['app.debug' => false]); // compare the bodies a user would really get, without debug traces
        $caps = ['purchasing.purchase-order.view', 'purchasing.purchase-order.create', 'purchasing.purchase-order.submit', 'purchasing.purchase-order.issue', 'purchasing.purchase-order.cancel', 'purchasing.purchase-order.short-close'];
        $alice = PurchasingActor::grant($this->alice, $caps);
        foreach ($caps as $duty) {
            AccessScopeLimit::create(['user_id' => $alice->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        $hidden = $this->po['bobB']->id;
        $absent = 987654;
        $order = $this->po['bobB'];
        $line = $order->lines()->create($this->lineAttributes($order, ...array_slice($this->makeItemAndUom(), 0, 2)));
        $as = $this->actingAsUnprivileged($alice);
        $absentLine = 987655;

        foreach ([
            ['getJson', '/api/v1/purchase-orders/%d', []],
            ['patchJson', '/api/v1/purchase-orders/%d', ['notes' => 'x']],
            ['postJson', '/api/v1/purchase-orders/%d/submit', []],
            ['postJson', '/api/v1/purchase-orders/%d/issue', []],
            ['postJson', '/api/v1/purchase-orders/%d/cancel', ['reason' => 'no']],
        ] as [$verb, $uri, $body]) {
            $this->assertSame(
                $this->notFound($as->{$verb}(sprintf($uri, $absent), $body), $absent),
                $this->notFound($as->{$verb}(sprintf($uri, $hidden), $body), $hidden),
                "{$verb} {$uri}: a hidden order must answer exactly like an absent one",
            );
        }
        $this->assertSame(
            $this->notFound($as->postJson("/api/v1/purchase-order-lines/{$absentLine}/short-close"), $absentLine),
            $this->notFound($as->postJson("/api/v1/purchase-order-lines/{$line->id}/short-close"), $line->id),
        );
    }

    public function test_another_companys_user_reaches_none_of_these_orders(): void
    {
        $this->orders();
        $other = Company::create(['name' => 'Other Co']);
        $this->actingAsCompany($other->id);
        $strangerUser = User::factory()->create();
        $stranger = PurchasingActor::grant($strangerUser, [self::VIEW, 'purchasing.purchase-order.create', 'purchasing.commercial-cost.view'], $other->id);

        // In their own company they see an empty list and cannot find this company's order by id.
        $this->assertSame([], collect($this->actingAsUnprivileged($stranger)->getJson('/api/v1/purchase-orders?per_page=100')->assertOk()->json('data'))->pluck('id')->all());
        $this->actingAsUnprivileged($stranger)->getJson("/api/v1/purchase-orders/{$this->po['aliceA']->id}")->assertNotFound();
        $this->actingAsUnprivileged($stranger)->postJson("/api/v1/purchase-orders/{$this->po['aliceA']->id}/cancel", ['reason' => 'x'])->assertNotFound();
        // Where this company is the active one, the resolver refuses a user who belongs to another.
        $this->actingAsCompany(1);
        $this->assertTrue(app(RecordScope::class)->resolveForResource($stranger, self::VIEW, 'purchase_order')->isNone());
        $this->assertSame(0, PurchaseOrderAccess::make()->scopeForRead(PurchaseOrder::query(), $stranger)->count());
    }

    private function actingAsCompany(int $companyId): void
    {
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }

    /** A company-wide operational reader whose commercial grant is limited to their own orders. */
    private function operationalReaderWithOwnCommercial(): User
    {
        $reader = PurchasingActor::make([self::VIEW, 'purchasing.commercial-cost.view']);
        AccessScopeLimit::create(['user_id' => $reader->id, 'permission' => 'purchasing.commercial-cost.view', 'scope' => 'OWN']);
        foreach (['aliceA', 'bobB'] as $key) {
            $this->priced($this->po[$key], '50.0000');
        }

        return $reader;
    }

    private function priced(PurchaseOrder $order, string $amount): void
    {
        $order->lines()->create(['line_amount' => $amount, 'base_line_amount' => $amount, 'unit_price' => $amount, 'base_unit_price' => $amount, 'ordered_qty' => '1.0000', 'ordered_stock_qty' => '1.0000'] + $this->lineAttributes($order, ...array_slice($this->makeItemAndUom(), 0, 2)));
    }

    public function test_a_limited_commercial_grant_shows_money_only_on_the_orders_it_reaches(): void
    {
        $this->orders();
        $reader = $this->operationalReaderWithOwnCommercial();

        // The reader created nothing: every order is visible operationally and none commercially.
        $detail = $this->actingAsUnprivileged($reader)->getJson("/api/v1/purchase-orders/{$this->po['aliceA']->id}?include=lines")->assertOk()->json('data');
        foreach (['fx_rate', 'payment_terms_state', 'total_amount', 'base_total_amount'] as $field) {
            $this->assertArrayNotHasKey($field, $detail);
        }
        $this->assertArrayNotHasKey('unit_price', $detail['lines'][0]);
        $this->assertArrayNotHasKey('line_amount', $detail['lines'][0]);
        $list = $this->actingAsUnprivileged($reader)->getJson('/api/v1/purchase-orders?include=lines&per_page=100')->assertOk()->json('data');
        $this->assertNotEmpty($list);
        foreach ($list as $row) {
            $this->assertArrayNotHasKey('fx_rate', $row);
            foreach ($row['lines'] ?? [] as $line) {
                $this->assertArrayNotHasKey('unit_price', $line);
            }
        }

        // The same grant shows money once the order is the reader's own.
        $mine = $this->newOrder('readers', $reader, $this->whA);
        $this->priced($mine, '70.0000');
        $own = $this->actingAsUnprivileged($reader)->getJson("/api/v1/purchase-orders/{$mine->id}?include=lines")->assertOk()->json('data');
        $this->assertSame('70.0000', $own['total_amount']);
        $this->assertSame('70.0000', $own['lines'][0]['line_amount']);
    }

    public function test_money_filters_and_sorts_are_refused_when_commercial_reach_is_narrower_than_the_list(): void
    {
        $this->orders();
        $as = $this->actingAsUnprivileged($this->operationalReaderWithOwnCommercial());

        $as->getJson('/api/v1/purchase-orders?filter[total_min]=1')->assertForbidden();
        $as->getJson('/api/v1/purchase-orders?filter[total_max]=1000')->assertForbidden();
        $as->getJson('/api/v1/purchase-orders?sort=-total_amount')->assertForbidden();
        $as->getJson('/api/v1/purchase-orders?sort=-order_date')->assertOk();

        // Reach that covers the list may infer money; an unrestricted commercial grant always did.
        $own = PurchasingActor::make([self::VIEW, 'purchasing.commercial-cost.view']);
        AccessScopeLimit::create(['user_id' => $own->id, 'permission' => self::VIEW, 'scope' => 'OWN']);
        AccessScopeLimit::create(['user_id' => $own->id, 'permission' => 'purchasing.commercial-cost.view', 'scope' => 'OWN']);
        $this->actingAsUnprivileged($own)->getJson('/api/v1/purchase-orders?sort=-total_amount')->assertOk();
        $boss = PurchasingActor::make([self::VIEW, 'purchasing.commercial-cost.view']);
        $this->actingAsUnprivileged($boss)->getJson('/api/v1/purchase-orders?filter[total_min]=1&sort=total_amount')->assertOk();
    }

    public function test_approval_evidence_follows_the_order_not_just_the_capability(): void
    {
        $this->orders();
        $reader = $this->operationalReaderWithOwnCommercial();
        $mine = $this->newOrder('readers', $reader, $this->whA);
        $subject = new PurchaseOrderApprovalSubject;
        $request = fn (PurchaseOrder $o) => new ApprovalRequest(['subject_type' => 'PURCHASE_ORDER', 'subject_id' => $o->id]);

        $this->assertTrue($subject->canViewEvidence($reader), 'the capability exists');
        $this->assertFalse($subject->evidenceInReach($reader, $request($this->po['aliceA'])), "someone else's order is outside a limited commercial grant");
        $this->assertTrue($subject->evidenceInReach($reader, $request($mine)));
        $boss = PurchasingActor::make(['purchasing.commercial-cost.view']);
        $this->assertTrue($subject->evidenceInReach($boss, $request($this->po['aliceA'])));
    }

    public function test_receipt_money_needs_an_unrestricted_commercial_grant(): void
    {
        $this->orders();
        $reader = $this->operationalReaderWithOwnCommercial();

        $this->assertFalse(PurchasingCommercialAccess::companyWide($reader));
        $this->assertTrue(PurchasingCommercialAccess::companyWide(PurchasingActor::make(['purchasing.commercial-cost.view'])));
    }

    public function test_an_edit_cannot_move_somebody_elses_order_out_of_the_editors_units_even_with_an_own_path(): void
    {
        $this->orders();
        $editor = PurchasingActor::make(['purchasing.purchase-order.view', 'purchasing.purchase-order.create']);
        AccessScopeLimit::create(['user_id' => $editor->id, 'permission' => 'purchasing.purchase-order.view', 'scope' => 'MY_UNITS']);
        // create is limited twice — to own orders (through a role) and to units (directly): two alternatives for the same duty.
        $editor->revokePermissionTo('purchasing.purchase-order.create');
        $role = Role::create(['team_id' => 1, 'name' => 'own drafter']);
        $role->givePermissionTo(Permission::findOrCreate('purchasing.purchase-order.create'));
        $editor->assignRole($role);
        $editor->givePermissionTo('purchasing.purchase-order.create');
        AccessScopeLimit::create(['user_id' => $editor->id, 'role_id' => $role->id, 'scope' => 'OWN']);
        AccessScopeLimit::create(['user_id' => $editor->id, 'permission' => 'purchasing.purchase-order.create', 'scope' => 'MY_UNITS']);
        $this->member($editor, $this->unitA);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $as = $this->actingAsUnprivileged($editor);
        $others = $this->po['aliceA']; // created by Alice, ships to A (the editor's unit)

        $as->patchJson("/api/v1/purchase-orders/{$others->id}", ['ship_to_warehouse_id' => $this->whB->id])->assertStatus(422);
        $this->assertSame($this->whA->id, $others->fresh()->ship_to_warehouse_id);
        $as->patchJson("/api/v1/purchase-orders/{$others->id}", ['ship_to_warehouse_id' => null])->assertStatus(422);
        $as->patchJson("/api/v1/purchase-orders/{$others->id}", ['notes' => 'still reachable'])->assertOk();

        // An order the editor created may go anywhere: it stays theirs.
        $mine = $this->newOrder('editors', $editor, $this->whA);
        $as->patchJson("/api/v1/purchase-orders/{$mine->id}", ['ship_to_warehouse_id' => $this->whC->id])->assertOk();
    }

    public function test_cutover_authority_is_only_the_unrestricted_workflow_and_only_for_a_cutover_order(): void
    {
        $this->orders();
        $issue = 'purchasing.purchase-order.issue';
        DB::statement('SET session_replication_role = replica');
        DB::table('purchase_orders')->where('id', $this->po['bobB']->id)->update(['inventory_cutover_id' => 999001, 'import_batch_id' => 999002, 'source_system' => 'legacy', 'source_record_id' => 'PO-1']);
        DB::statement('SET session_replication_role = DEFAULT');
        $cutoverOrder = $this->po['bobB'];
        $plainOrder = $this->po['bobC'];
        $lock = function (User $actor, PurchaseOrder $order): PurchaseOrder {
            $method = new ReflectionMethod(IssuePurchaseOrder::class, 'lockForIssue');

            return DB::transaction(fn () => $method->invoke(app(IssuePurchaseOrder::class), $actor, $order->id));
        };

        $workflowOnly = PurchasingActor::make(['purchasing.cutover.commit']);
        $this->assertSame($cutoverOrder->id, $lock($workflowOnly, $cutoverOrder)->id, 'the real cutover workflow may issue a cutover order');
        $this->expectRefusal(fn () => $lock($workflowOnly, $plainOrder), 'but not an ordinary order');

        $limitedWorkflow = PurchasingActor::make(['purchasing.cutover.commit']);
        AccessScopeLimit::create(['user_id' => $limitedWorkflow->id, 'permission' => 'purchasing.cutover.commit', 'scope' => 'MY_UNITS']);
        $this->expectRefusal(fn () => $lock($limitedWorkflow, $cutoverOrder), 'a limited cutover grant is not the workflow authority');

        $limitedIssuer = PurchasingActor::make([$issue]);
        AccessScopeLimit::create(['user_id' => $limitedIssuer->id, 'permission' => $issue, 'scope' => 'OWN']);
        $this->expectNotFound(fn () => $lock($limitedIssuer, $cutoverOrder), 'an issuer limited to their own orders cannot reach it by duty');
        PurchasingActor::grant($limitedIssuer, ['purchasing.cutover.commit']);
        $this->assertSame($cutoverOrder->id, $lock($limitedIssuer, $cutoverOrder)->id, 'with the unrestricted workflow authority as well, a cutover order is reachable');
        $this->expectNotFound(fn () => $lock($limitedIssuer, $plainOrder), 'but the exception never covers an ordinary order');
    }

    private function expectRefusal(Closure $call, string $why): void
    {
        try {
            $call();
            $this->fail($why);
        } catch (DomainInvariantException) {
            $this->assertTrue(true, $why);
        }
    }

    private function expectNotFound(Closure $call, string $why): void
    {
        try {
            $call();
            $this->fail($why);
        } catch (ModelNotFoundException) {
            $this->assertTrue(true, $why);
        }
    }

    /** @return array{0: Item, 1: Uom} */
    private function makeItemAndUom(): array
    {
        $uom = Uom::query()->first();
        $item = Item::query()->first();

        return [$item, $uom];
    }
}
