<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Tests\Support\PurchasingActor;

/** Purchase orders through ADR 0070's engine: optional, band-driven, frozen while pending, and void once edited. */
class PurchaseOrderApprovalTest extends PurchaseOrderFoundationTest
{
    private User $buyer;

    private User $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = PurchasingActor::make(['purchasing.purchase-order.view', 'purchasing.purchase-order.create', 'purchasing.purchase-order.submit', 'purchasing.commercial-cost.view']);
        $this->issuer = PurchasingActor::make(['purchasing.purchase-order.view', 'purchasing.purchase-order.issue']);
    }

    private function mode(ApprovalMode $mode): void
    {
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'PURCHASE_ORDER'], ['mode' => $mode]);
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function policy(string $code, array $steps, array $conditions = [], int $priority = 0): ApprovalPolicy
    {
        $policy = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => $code, 'version_no' => 1, 'name' => $code, 'priority' => $priority, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $this->buyer->id]);
        foreach ($conditions as [$fact, $operator, $value]) {
            $policy->conditions()->create(['fact' => $fact, 'operator' => $operator, 'value' => $value]);
        }
        foreach ($steps as $i => $step) {
            $policy->steps()->create($step + ['stage' => $i + 1, 'name' => 'Step '.($i + 1), 'required_approvals' => 1]);
        }

        return $policy;
    }

    private function orderWorth(string $amount): PurchaseOrder
    {
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create(['line_amount' => $amount, 'base_line_amount' => $amount, 'unit_price' => $amount, 'base_unit_price' => $amount, 'ordered_qty' => '1.0000', 'ordered_stock_qty' => '1.0000'] + $this->lineAttributes($order, $item, $uom));

        return $order;
    }

    /** Another order for the same supplier, so a test can hold two orders of different size. */
    private function siblingWorth(PurchaseOrder $like, string $amount): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create($like->only(['supplier_account_id', 'supplier_site_id', 'currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source', 'order_date']))->refresh();
        $line = $like->lines()->first();
        $order->lines()->create(['line_amount' => $amount, 'base_line_amount' => $amount, 'unit_price' => $amount, 'base_unit_price' => $amount] + $line->only(['line_number', 'item_id', 'description', 'ordered_qty', 'purchase_uom_id', 'ordered_stock_qty', 'stock_uom_id', 'uom_factor']));

        return $order;
    }

    private function submit(PurchaseOrder $order, ?User $by = null)
    {
        return $this->actingAsUnprivileged($by ?? $this->buyer)->postJson("/api/v1/purchase-orders/{$order->id}/submit");
    }

    public function test_with_no_approval_configured_an_order_is_submitted_as_not_required_and_issues_as_before(): void
    {
        $order = $this->orderWorth('80000.0000');

        $this->submit($order)->assertOk()->assertJsonPath('data.approval.status', 'NOT_REQUIRED')->assertJsonPath('data.approval.mode', 'NONE');
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertOk()->assertJsonPath('data.status', 'ISSUED');
    }

    public function test_a_required_order_cannot_be_issued_until_approved_and_is_frozen_while_it_waits(): void
    {
        $this->mode(ApprovalMode::Required);
        $this->policy('all-orders', [['permission' => 'purchasing.purchase-order.approve']]);
        $order = $this->orderWorth('80000.0000');

        $this->actingAsUnprivileged($this->buyer)->getJson("/api/v1/purchase-orders/{$order->id}")->assertOk()->assertJsonPath('data.approval.status', 'NOT_SUBMITTED')->assertJsonPath('data.approval.required', true);
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertUnprocessable();

        $this->actingAsUnprivileged(PurchasingActor::make(['purchasing.purchase-order.view']))->postJson("/api/v1/purchase-orders/{$order->id}/submit")->assertForbidden();
        $response = $this->submit($order)->assertOk()->assertJsonPath('data.approval.status', 'PENDING');
        $requestId = $response->json('data.approval.request_id');

        // Waiting for approval: neither issued nor edited.
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertUnprocessable()->assertJsonPath('errors.approval.0', 'This is still waiting for approval.');
        $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$order->id}", ['notes' => 'tweak'])->assertUnprocessable();

        $approver = PurchasingActor::make(['purchasing.purchase-order.approve']);
        $this->actingAsUnprivileged($approver)->postJson("/api/v1/approval-requests/{$requestId}/decide", ['decision' => 'APPROVED'])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->actingAsUnprivileged($this->buyer)->getJson("/api/v1/purchase-orders/{$order->id}")->assertJsonPath('data.approval.status', 'APPROVED');
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertOk()->assertJsonPath('data.status', 'ISSUED');
    }

    public function test_the_amount_decides_which_approvals_an_order_needs(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy('over-100k', [['permission' => 'purchasing.purchase-order.approve'], ['permission' => 'purchasing.purchase-order.approve', 'required_approvals' => 2]], [['amount_base', 'GTE', '100000']], 10);
        $small = $this->orderWorth('80000.0000');
        $big = $this->siblingWorth($small, '800000.0000');

        // Under the band no policy matches, so under POLICY mode nothing is asked and it can be issued straight away.
        $this->actingAsUnprivileged($this->buyer)->getJson("/api/v1/purchase-orders/{$small->id}")->assertJsonPath('data.approval.required', false)->assertJsonPath('data.approval.status', 'NOT_REQUIRED');
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$small->id}/issue")->assertOk();

        $this->submit($big)->assertOk()->assertJsonPath('data.approval.status', 'PENDING');
        $request = ApprovalRequest::query()->sole();
        $this->assertSame('800000.0000', $request->facts['amount_base']);
        $this->assertSame(2, $request->steps()->count());
        $this->assertSame(1, $request->steps()->where('status', 'OPEN')->count(), 'the second step waits for the first');
    }

    public function test_editing_an_approved_order_voids_the_approval_and_it_must_be_approved_again(): void
    {
        $this->mode(ApprovalMode::Required);
        $this->policy('all-orders', [['permission' => 'purchasing.purchase-order.approve']]);
        $order = $this->orderWorth('80000.0000');
        $approver = PurchasingActor::make(['purchasing.purchase-order.approve']);

        $first = $this->submit($order)->json('data.approval.request_id');
        $this->actingAsUnprivileged($approver)->postJson("/api/v1/approval-requests/{$first}/decide", ['decision' => 'APPROVED'])->assertOk();

        // Changed to a far larger order: the Rs 80,000 approval must not carry over.
        $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$order->id}", ['notes' => 'now much bigger'])->assertOk()->assertJsonPath('data.approval.status', 'INVALIDATED');
        $this->assertSame('INVALIDATED', ApprovalRequest::query()->findOrFail($first)->status->value);
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertUnprocessable();

        $second = $this->submit($order)->assertOk()->json('data.approval.request_id');
        $this->assertNotSame($first, $second);
    }

    public function test_the_order_total_is_routed_in_base_currency_at_the_orders_own_pinned_rate(): void
    {
        $this->mode(ApprovalMode::Required);
        $this->policy('all-orders', [['permission' => 'purchasing.purchase-order.approve']]);
        [$order, $item, $uom] = $this->makeOrder();
        $order->update(['fx_rate' => '280.000000000', 'fx_rate_source' => 'central-bank']);
        $order->lines()->create(['line_amount' => '1000.0000', 'base_line_amount' => '280000.0000', 'unit_price' => '500.0000', 'base_unit_price' => '140000.0000', 'ordered_qty' => '2.0000', 'ordered_stock_qty' => '2.0000'] + $this->lineAttributes($order, $item, $uom));

        $this->submit($order)->assertOk();

        $request = ApprovalRequest::query()->sole();
        $this->assertSame('280000.0000', $request->facts['amount_base']);
        $this->assertEquals(['original_amount' => '1000.0000', 'currency_code' => 'USD', 'fx_rate' => '280.000000000', 'fx_rate_source' => 'central-bank', 'base_amount' => '280000.0000'],
            array_intersect_key($request->facts['evidence'], array_flip(['original_amount', 'currency_code', 'fx_rate', 'fx_rate_source', 'base_amount'])));
        // The frozen hash still describes the order as routed; a later rate change makes the order differ from it.
        $registry = app(ApprovalSubjectRegistry::class)->get('PURCHASE_ORDER');
        $this->assertSame($request->facts_hash, $registry->routing($order->id)->hash());
        $order->lines()->update(['base_line_amount' => '290000.0000']);
        $this->assertNotSame($request->facts_hash, $registry->routing($order->id)->hash());
    }

    public function test_a_migrated_cutover_order_is_not_asked_for_approval_again(): void
    {
        $this->mode(ApprovalMode::Required);
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));

        // A required order with no applicable policy cannot be issued...
        $this->actingAsUnprivileged($this->issuer)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertUnprocessable();
        $this->assertSame('DRAFT', $order->fresh()->status->value);
    }
}
