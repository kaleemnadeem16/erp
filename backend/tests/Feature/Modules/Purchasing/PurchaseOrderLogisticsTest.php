<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\PurchasingActor;

/**
 * Phase 2 purchasing completeness (ADR 0191): a purchase order's ship-to (warehouse and optional location), buyer/requester, per-order payment terms with the supplier's default as the
 * fallback, and shipping method — optional, company-isolated, frozen at issue, and part of what an approval was given for. The creator is set once by the server.
 */
class PurchaseOrderLogisticsTest extends PurchaseOrderFoundationTest
{
    private User $buyer;

    private User $issuer;

    private Warehouse $main;

    private Location $dock;

    /** @var array{0: PurchaseOrder, 1: int, 2: int}|null */
    private ?array $fixed = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = PurchasingActor::make(['purchasing.purchase-order.view', 'purchasing.purchase-order.create', 'purchasing.purchase-order.submit', 'purchasing.commercial-cost.view']);
        $this->issuer = PurchasingActor::make(['purchasing.purchase-order.view', 'purchasing.purchase-order.issue', 'purchasing.commercial-cost.view']);
        $this->main = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main store', 'location_code' => 'dock', 'location_name' => 'Goods-in dock'], PurchasingActor::make(['inventory.warehouse.manage']));
        $this->dock = $this->main->locations()->sole();
    }

    /** @return array<string, mixed> */
    private function payload(PurchaseOrder $like, int $itemId, int $uomId, array $more = []): array
    {
        return $more + ['supplier_account_id' => $like->supplier_account_id, 'supplier_site_id' => $like->supplier_site_id, 'currency_id' => $like->currency_id, 'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-02',
            'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02',
            'lines' => [['line_number' => 1, 'item_id' => $itemId, 'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uomId, 'unit_price' => '10.0000']]];
    }

    /** @return array{0: PurchaseOrder, 1: int, 2: int} a fixture order, and the item and unit to order */
    private function fixture(): array
    {
        if ($this->fixed === null) {
            [$order, $item, $uom] = $this->makeOrder();
            $this->fixed = [$order, $item->id, $uom->id];
        }

        return $this->fixed;
    }

    private function create(array $more = [], ?User $by = null): TestResponse
    {
        [$like, $item, $uom] = $this->fixture();

        return $this->actingAsUnprivileged($by ?? $this->buyer)->postJson('/api/v1/purchase-orders', $this->payload($like, $item, $uom, $more));
    }

    private function mode(ApprovalMode $mode): void
    {
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'PURCHASE_ORDER'], ['mode' => $mode]);
    }

    private function approvalPolicy(): void
    {
        $policy = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => 'all', 'version_no' => 1, 'name' => 'all', 'priority' => 0, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $this->buyer->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Step 1', 'required_approvals' => 1, 'permission' => 'purchasing.purchase-order.approve']);
    }

    public function test_an_existing_order_stays_valid_and_readable_with_none_of_the_new_facts(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));

        $data = $this->actingAsUnprivileged($this->buyer)->getJson("/api/v1/purchase-orders/{$order->id}?include=lines")->assertOk()->json('data');

        $this->assertSame([null, null, null, null, null, null, null], [$data['ship_to_warehouse_id'], $data['ship_to_location_id'], $data['buyer_user_id'], $data['created_by'], $data['shipping_method'], $data['payment_terms_days'], $data['payment_terms_source']]);
        $this->assertSame(30, $data['payment_terms_effective_days'], "no terms of its own: the supplier's default stands");
        $this->assertSame(['warehouse' => null, 'location' => null], $data['ship_to']);
    }

    public function test_all_four_facts_are_optional_and_an_order_without_them_is_created_as_before(): void
    {
        $data = $this->create()->assertCreated()->json('data');

        $this->assertSame([null, null, null, null, $this->buyer->id], [$data['ship_to_warehouse_id'], $data['buyer_user_id'], $data['shipping_method'], $data['payment_terms_days'], $data['created_by']]);
        $this->assertSame(30, $data['payment_terms_effective_days']);
    }

    public function test_the_four_facts_are_stored_and_the_creator_is_the_server_recorded_actor(): void
    {
        $colleague = PurchasingActor::make(['purchasing.purchase-order.view']);

        $data = $this->create(['ship_to_warehouse_id' => $this->main->id, 'ship_to_location_id' => $this->dock->id, 'buyer_user_id' => $colleague->id, 'payment_terms_days' => 45,
            'shipping_method' => ' Sea freight, FOB ', 'created_by' => $colleague->id])->assertCreated()->json('data');

        $this->assertSame([$this->main->id, $this->dock->id, $colleague->id, 45, 'Sea freight, FOB'], [$data['ship_to_warehouse_id'], $data['ship_to_location_id'], $data['buyer_user_id'], $data['payment_terms_days'], $data['shipping_method']]);
        $this->assertSame($this->buyer->id, $data['created_by'], 'a creator in the request is ignored: the server records who drafted it');
        $this->assertSame(['Main store', 'Goods-in dock'], [$data['ship_to']['warehouse']['name'], $data['ship_to']['location']['name']]);
        $this->assertSame(['id' => $colleague->id, 'name' => $colleague->name], $data['buyer']);
        $this->assertSame(45, $data['payment_terms_effective_days']);
        $this->assertSame('PURCHASE_ORDER', PurchaseOrder::query()->findOrFail($data['id'])->payment_terms_source ?? 'PURCHASE_ORDER', 'a draft carries no frozen source yet');
    }

    public function test_every_invalid_ship_to_buyer_terms_or_method_is_a_validation_error(): void
    {
        $other = app(CreateWarehouse::class)(['code' => 'other', 'name' => 'Other', 'location_code' => 'x', 'location_name' => 'X'], PurchasingActor::make(['inventory.warehouse.manage']));
        $retired = app(CreateWarehouse::class)(['code' => 'old', 'name' => 'Old', 'location_code' => 'o', 'location_name' => 'O'], PurchasingActor::make(['inventory.warehouse.manage']));
        $retired->update(['active' => false]);
        $suspended = User::factory()->create(['status' => MemberStatus::Suspended]);
        $bad = [
            'no such warehouse' => ['ship_to_warehouse_id' => 999999],
            'an inactive warehouse' => ['ship_to_warehouse_id' => $retired->id],
            'a location of another warehouse' => ['ship_to_warehouse_id' => $this->main->id, 'ship_to_location_id' => $other->locations()->sole()->id],
            'a location without a warehouse' => ['ship_to_location_id' => $this->dock->id],
            'no such buyer' => ['buyer_user_id' => 999999],
            'a suspended buyer' => ['buyer_user_id' => $suspended->id],
            'negative terms' => ['payment_terms_days' => -1],
            'terms too large' => ['payment_terms_days' => 40000],
            'fractional terms' => ['payment_terms_days' => '1.5'],
            'an over-long method' => ['shipping_method' => str_repeat('x', 101)],
        ];
        foreach ($bad as $label => $fields) {
            $this->create($fields)->assertUnprocessable();
            $this->assertSame(0, PurchaseOrder::query()->where('shipping_method', 'like', 'x%')->count(), $label);
        }
    }

    public function test_a_blank_shipping_method_is_no_method(): void
    {
        $this->assertNull($this->create(['shipping_method' => '   '])->assertCreated()->json('data.shipping_method'));
    }

    public function test_another_companys_warehouse_or_user_cannot_be_named_even_past_validation(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $foreignWarehouse = DB::table('warehouses')->insertGetId(['company_id' => $company, 'code' => 'ow', 'name' => 'Foreign', 'is_default' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreignUser = User::factory()->create();
        DB::table('users')->where('id', $foreignUser->id)->update(['company_id' => $company]);
        [$order] = $this->fixture();

        $this->create(['ship_to_warehouse_id' => $foreignWarehouse])->assertUnprocessable();
        $this->create(['buyer_user_id' => $foreignUser->id])->assertUnprocessable();
        foreach (['ship_to_warehouse_id' => $foreignWarehouse, 'buyer_user_id' => $foreignUser->id, 'created_by' => $foreignUser->id] as $column => $value) {
            try {
                DB::transaction(fn () => DB::table('purchase_orders')->where('id', $order->id)->update([$column => $value]));
                $this->fail("the database accepted {$column} from another company");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_database_ties_a_location_to_its_warehouse(): void
    {
        $other = app(CreateWarehouse::class)(['code' => 'other', 'name' => 'Other', 'location_code' => 'x', 'location_name' => 'X'], PurchasingActor::make(['inventory.warehouse.manage']));
        [$order] = $this->fixture();

        foreach (['a location of another warehouse' => ['ship_to_warehouse_id' => $this->main->id, 'ship_to_location_id' => $other->locations()->sole()->id], 'a location with no warehouse' => ['ship_to_warehouse_id' => null, 'ship_to_location_id' => $this->dock->id]] as $label => $set) {
            try {
                DB::transaction(fn () => DB::table('purchase_orders')->where('id', $order->id)->update($set));
                $this->fail("the database accepted {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_draft_can_change_and_clear_its_facts_and_a_warehouse_change_drops_the_old_location(): void
    {
        $other = app(CreateWarehouse::class)(['code' => 'other', 'name' => 'Other', 'location_code' => 'x', 'location_name' => 'X'], PurchasingActor::make(['inventory.warehouse.manage']));
        $id = $this->create(['ship_to_warehouse_id' => $this->main->id, 'ship_to_location_id' => $this->dock->id, 'payment_terms_days' => 45, 'shipping_method' => 'Courier'])->json('data.id');

        $moved = $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$id}", ['ship_to_warehouse_id' => $other->id])->assertOk()->json('data');
        $this->assertSame([$other->id, null], [$moved['ship_to_warehouse_id'], $moved['ship_to_location_id']], "the old warehouse's location does not follow the order");

        $this->patchJson("/api/v1/purchase-orders/{$id}", ['ship_to_location_id' => $other->locations()->sole()->id])->assertOk();
        $this->patchJson("/api/v1/purchase-orders/{$id}", ['ship_to_location_id' => $this->dock->id])->assertUnprocessable();

        $cleared = $this->patchJson("/api/v1/purchase-orders/{$id}", ['ship_to_warehouse_id' => null, 'payment_terms_days' => null, 'shipping_method' => null])->assertOk()->json('data');
        $this->assertSame([null, null, null, null, 30], [$cleared['ship_to_warehouse_id'], $cleared['ship_to_location_id'], $cleared['payment_terms_days'], $cleared['shipping_method'], $cleared['payment_terms_effective_days']], 'clearing the override falls back to the supplier default');
    }

    public function test_issuing_freezes_the_effective_terms_and_a_later_supplier_edit_cannot_move_them(): void
    {
        $own = $this->create(['payment_terms_days' => 45, 'ship_to_warehouse_id' => $this->main->id])->json('data.id');
        $fallback = $this->create()->json('data.id');

        $a = $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$own}/issue")->assertOk()->json('data');
        $b = $this->postJson("/api/v1/purchase-orders/{$fallback}/issue")->assertOk()->json('data');

        $this->assertSame([45, 'PURCHASE_ORDER'], [$a['payment_terms_days'], $a['payment_terms_source']]);
        $this->assertSame([30, 'SUPPLIER_DEFAULT'], [$b['payment_terms_days'], $b['payment_terms_source']], "the supplier's default as it stood at issue");

        DB::table('supplier_accounts')->update(['payment_terms_days' => 90]);
        $this->assertSame([45, 30], [$this->getJson("/api/v1/purchase-orders/{$own}")->json('data.payment_terms_effective_days'), $this->getJson("/api/v1/purchase-orders/{$fallback}")->json('data.payment_terms_effective_days')]);
    }

    public function test_an_issued_orders_new_facts_cannot_be_changed_by_the_api_the_model_or_raw_sql(): void
    {
        $id = $this->create(['ship_to_warehouse_id' => $this->main->id, 'ship_to_location_id' => $this->dock->id, 'buyer_user_id' => $this->buyer->id, 'payment_terms_days' => 45, 'shipping_method' => 'Courier'])->json('data.id');
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$id}/issue")->assertOk();

        $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$id}", ['shipping_method' => 'Air'])->assertUnprocessable();
        $order = PurchaseOrder::query()->findOrFail($id);
        foreach (['ship_to_warehouse_id' => null, 'ship_to_location_id' => null, 'buyer_user_id' => null, 'payment_terms_days' => 10, 'payment_terms_source' => 'SUPPLIER_DEFAULT', 'shipping_method' => 'Air'] as $column => $value) {
            try {
                $order->refresh()->update([$column => $value]);
                $this->fail("the model changed {$column} on an issued order");
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
            try {
                DB::transaction(fn () => DB::table('purchase_orders')->where('id', $id)->update([$column => $value]));
                $this->fail("raw SQL changed {$column} on an issued order");
            } catch (QueryException $refused) {
                $this->assertStringContainsString('immutable', $refused->getMessage(), $column);
            }
        }
    }

    public function test_the_creator_never_changes_even_on_a_draft(): void
    {
        $id = $this->create()->json('data.id');
        $someone = PurchasingActor::make(['purchasing.purchase-order.view']);

        $this->expectException(DomainInvariantException::class);
        try {
            PurchaseOrder::query()->findOrFail($id)->update(['created_by' => $someone->id]);
        } finally {
            try {
                DB::transaction(fn () => DB::table('purchase_orders')->where('id', $id)->update(['created_by' => $someone->id]));
                $this->fail('raw SQL changed the creator');
            } catch (QueryException $refused) {
                $this->assertStringContainsString('creator', $refused->getMessage());
            }
        }
    }

    public function test_a_ship_to_that_is_no_longer_active_blocks_issue(): void
    {
        $id = $this->create(['ship_to_warehouse_id' => $this->main->id, 'ship_to_location_id' => $this->dock->id])->json('data.id');
        $this->dock->update(['active' => false]);
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$id}/issue")->assertUnprocessable()->assertJsonPath('errors.ship_to_location_id.0', fn ($m) => str_contains($m, 'no longer active'));

        $this->dock->update(['active' => true]);
        $this->main->update(['active' => false]);
        $this->postJson("/api/v1/purchase-orders/{$id}/issue")->assertUnprocessable();
        $this->assertSame('DRAFT', PurchaseOrder::query()->findOrFail($id)->status->value);
    }

    public function test_changing_any_fact_an_approval_covered_voids_it(): void
    {
        $this->mode(ApprovalMode::Required);
        $this->approvalPolicy();
        $approver = PurchasingActor::make(['purchasing.purchase-order.approve']);
        $cases = [
            'the ship-to warehouse' => fn (int $id) => ['ship_to_warehouse_id' => $this->main->id],
            'the payment terms' => fn (int $id) => ['payment_terms_days' => 60],
            'the shipping method' => fn (int $id) => ['shipping_method' => 'Air'],
            'the buyer' => fn (int $id) => ['buyer_user_id' => $approver->id],
        ];
        foreach ($cases as $label => $change) {
            $id = $this->create(['shipping_method' => 'Courier'])->json('data.id');
            $request = $this->actingAsUnprivileged($this->buyer)->postJson("/api/v1/purchase-orders/{$id}/submit")->json('data.approval.request_id');
            $this->actingAsUnprivileged($approver)->postJson("/api/v1/approval-requests/{$request}/decide", ['decision' => 'APPROVED'])->assertOk();

            $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$id}", $change($id))->assertOk()->assertJsonPath('data.approval.status', 'INVALIDATED');
            $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$id}/issue")->assertUnprocessable();
            $this->assertSame('DRAFT', PurchaseOrder::query()->findOrFail($id)->status->value, $label);
        }
    }

    public function test_an_approval_does_not_survive_a_change_made_behind_the_actions_back(): void
    {
        $this->mode(ApprovalMode::Required);
        $this->approvalPolicy();
        $approver = PurchasingActor::make(['purchasing.purchase-order.approve']);
        $id = $this->create()->json('data.id');
        $request = $this->actingAsUnprivileged($this->buyer)->postJson("/api/v1/purchase-orders/{$id}/submit")->json('data.approval.request_id');
        $this->actingAsUnprivileged($approver)->postJson("/api/v1/approval-requests/{$request}/decide", ['decision' => 'APPROVED'])->assertOk();

        PurchaseOrder::query()->findOrFail($id)->update(['shipping_method' => 'Air']);

        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$id}/issue")->assertUnprocessable();
    }

    public function test_the_routing_fingerprint_is_unchanged_for_an_order_that_states_none_of_the_new_facts(): void
    {
        $id = $this->create()->json('data.id');
        $routing = (new PurchaseOrderApprovalSubject)->routing($id);
        $this->assertSame(['currency_id', 'supplier_account_id'], collect($routing->facts)->keys()->sort()->values()->all(), 'an order approved before these facts existed keeps the fingerprint it was approved on');

        $stated = $this->create(['shipping_method' => 'Courier', 'payment_terms_days' => 45, 'ship_to_warehouse_id' => $this->main->id])->json('data.id');
        $facts = (new PurchaseOrderApprovalSubject)->routing($stated)->facts;
        $this->assertSame(['Courier', 45, $this->main->id], [$facts['shipping_method'], $facts['payment_terms_days'], $facts['ship_to_warehouse_id']]);
    }

    public function test_payment_terms_are_commercial_and_hidden_from_a_reader_without_cost_access(): void
    {
        $id = $this->create(['payment_terms_days' => 45, 'shipping_method' => 'Courier'])->json('data.id');
        $reader = PurchasingActor::make(['purchasing.purchase-order.view']);

        $data = $this->actingAsUnprivileged($reader)->getJson("/api/v1/purchase-orders/{$id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('payment_terms_days', $data);
        foreach (['payment_terms_effective_days', 'payment_terms_source', 'payment_terms_state'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $data, 'terms are commercial: nothing about them, not even a fallback, reaches this reader');
        }
        $this->assertSame('Courier', $data['shipping_method'], 'where and how it travels is operational, not commercial');
    }

    public function test_an_old_issued_order_with_no_recorded_terms_says_so_and_never_borrows_todays_supplier_default(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));
        // An order issued before terms were recorded: issued, with no terms and no source.
        DB::table('purchase_orders')->where('id', $order->id)->update(['status' => 'ISSUED', 'number' => 'PO-OLD-1', 'issued_at' => now()]);

        $data = $this->actingAsUnprivileged($this->issuer)->getJson("/api/v1/purchase-orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame(['NOT_RECORDED', null, null], [$data['payment_terms_state'], $data['payment_terms_effective_days'], $data['payment_terms_days']]);

        DB::table('supplier_accounts')->update(['payment_terms_days' => 90]);
        $again = $this->getJson("/api/v1/purchase-orders/{$order->id}")->json('data');
        $this->assertSame(['NOT_RECORDED', null], [$again['payment_terms_state'], $again['payment_terms_effective_days']], "the supplier's later default is not a historical agreement");
    }

    public function test_each_stage_of_an_orders_terms_reports_its_state(): void
    {
        $draftFollowing = $this->create()->json('data');
        $draftOwn = $this->create(['payment_terms_days' => 45])->json('data');
        $this->assertSame([['DRAFT_SUPPLIER_DEFAULT', 30], ['DRAFT_ORDER', 45]], [[$draftFollowing['payment_terms_state'], $draftFollowing['payment_terms_effective_days']], [$draftOwn['payment_terms_state'], $draftOwn['payment_terms_effective_days']]], 'a draft may show the live supplier default');

        $a = $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$draftOwn['id']}/issue")->assertOk()->json('data');
        $b = $this->postJson("/api/v1/purchase-orders/{$draftFollowing['id']}/issue")->assertOk()->json('data');
        $this->assertSame([['ISSUED_ORDER', 45], ['ISSUED_SUPPLIER_DEFAULT', 30]], [[$a['payment_terms_state'], $a['payment_terms_effective_days']], [$b['payment_terms_state'], $b['payment_terms_effective_days']]]);
    }
}
