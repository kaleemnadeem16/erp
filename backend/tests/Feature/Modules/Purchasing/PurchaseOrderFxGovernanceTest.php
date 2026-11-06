<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\RateType;
use Tests\Support\PurchasingActor;

/**
 * Issue #90 (audit F3): the buyer's FX rate on a purchase order must be reconciled with the company's governed exchange rate. A rate that
 * matches within tolerance is accepted; a contractually fixed rate needs the staged fx-override capability and recorded evidence; anything else
 * is refused. The rate decides the base amount, which decides the approval band and, through the receipt, the inventory value.
 */
class PurchaseOrderFxGovernanceTest extends PurchaseOrderFoundationTest
{
    private const GOVERNED = '280.000000000';

    private Currency $pkr;

    private Currency $usd;

    private User $buyer;

    private User $fixedRateBuyer;

    /** @var array{PurchaseOrder, \App\Modules\Inventory\Models\Item, \App\Models\Uom}|null */
    private ?array $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->governFx(self::GOVERNED);
        $this->pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        $this->usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $base = ['purchasing.purchase-order.view', 'purchasing.purchase-order.create', 'purchasing.purchase-order.submit', 'purchasing.commercial-cost.view'];
        $this->buyer = PurchasingActor::make($base);
        $this->fixedRateBuyer = PurchasingActor::make([...$base, 'purchasing.purchase-order.fx-override']);
    }

    /** USD 10,000 of goods: PKR 2.8M at the governed rate. */
    private function payload(string $rate, array $extra = []): array
    {
        $this->fixture ??= $this->makeOrder();
        [$template, $item, $uom] = $this->fixture;

        return $extra + ['supplier_account_id' => $template->supplier_account_id, 'supplier_site_id' => $template->supplier_site_id, 'currency_id' => $this->usd->id,
            'fx_rate' => $rate, 'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02',
            'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => 'Item', 'ordered_qty' => '10000.0000', 'purchase_uom_id' => $uom->id, 'unit_price' => '1.0000']]];
    }

    private function bandOverOneMillion(): void
    {
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'PURCHASE_ORDER'], ['mode' => ApprovalMode::Policy]);
        $policy = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => 'over-1m', 'version_no' => 1, 'name' => 'over-1m', 'priority' => 10, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $this->buyer->id]);
        $policy->conditions()->create(['fact' => 'amount_base', 'operator' => 'GTE', 'value' => '1000000']);
        $policy->steps()->create(['stage' => 1, 'name' => 'Step 1', 'required_approvals' => 1, 'permission' => 'purchasing.purchase-order.approve']);
    }

    public function test_an_understated_client_rate_is_refused_so_it_cannot_steer_approval_or_valuation(): void
    {
        $this->bandOverOneMillion();
        $payload = $this->payload('28.000000000');
        $before = PurchaseOrder::query()->count();

        // 28 instead of 280: the order is really PKR 2.8M but would be booked, routed and valued as PKR 280k.
        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('fx_rate');

        $this->assertSame($before, PurchaseOrder::query()->count(), 'nothing was created at the understated rate');
    }

    public function test_the_governed_rate_is_accepted_prices_the_lines_and_routes_to_the_higher_band(): void
    {
        $this->bandOverOneMillion();

        $id = $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', $this->payload(self::GOVERNED))
            ->assertCreated()->assertJsonPath('data.fx_rate', self::GOVERNED)->json('data.id');

        $this->assertSame('2800000.0000', (string) PurchaseOrder::query()->findOrFail($id)->lines()->sole()->base_line_amount);
        $this->actingAsUnprivileged($this->buyer)->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk()->assertJsonPath('data.approval.status', 'PENDING');
        $this->assertSame('2800000.0000', ApprovalRequest::query()->sole()->facts['amount_base']);
    }

    public function test_a_rate_within_the_tolerance_is_accepted_and_one_beyond_it_is_refused(): void
    {
        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', $this->payload('281.000000000'))->assertCreated(); // +0.357%
        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', $this->payload('278.000000000'))->assertUnprocessable()->assertJsonValidationErrors('fx_rate'); // -0.714%
    }

    public function test_a_rate_with_no_governed_rate_to_check_it_against_is_refused(): void
    {
        $eur = Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => 'E']);

        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', ['currency_id' => $eur->id] + $this->payload('305.000000000'))
            ->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        // The base currency itself is always 1.
        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', ['currency_id' => $this->pkr->id] + $this->payload('2.000000000'))->assertUnprocessable();
        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', ['currency_id' => $this->pkr->id] + $this->payload('1.000000000'))->assertCreated();
    }

    public function test_a_fixed_contract_rate_needs_the_capability_a_declared_policy_and_a_recorded_reason(): void
    {
        $fixed = ['fx_policy' => 'FIXED'];
        $reason = ['fx_override_reason' => 'Forward contract FX-2291 with the supplier bank, 270.00 until 31 Dec.'];

        // An unsupported client declaration: no capability.
        $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', $this->payload('270.000000000', $fixed + $reason))->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        // Capability but still FLOATING, or no evidence.
        $this->actingAsUnprivileged($this->fixedRateBuyer)->postJson('/api/v1/purchase-orders', $this->payload('270.000000000', $reason))->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        $this->actingAsUnprivileged($this->fixedRateBuyer)->postJson('/api/v1/purchase-orders', $this->payload('270.000000000', $fixed))->assertUnprocessable()->assertJsonValidationErrors('fx_override_reason');

        $id = $this->actingAsUnprivileged($this->fixedRateBuyer)->postJson('/api/v1/purchase-orders', $this->payload('270.000000000', $fixed + $reason))
            ->assertCreated()->assertJsonPath('data.fx_rate', '270.000000000')->json('data.id');

        $order = PurchaseOrder::query()->findOrFail($id);
        $this->assertSame($reason['fx_override_reason'], $order->fx_override_reason);
        $this->assertSame($this->fixedRateBuyer->id, $order->fx_override_by);
        $this->assertSame('280.000000000', $order->fx_governed_rate, 'the governed rate it departed from stays on record');
        $this->assertSame('2700000.0000', (string) $order->lines()->sole()->base_line_amount);
    }

    public function test_the_override_evidence_travels_with_the_approval_request(): void
    {
        $this->bandOverOneMillion();
        $id = $this->actingAsUnprivileged($this->fixedRateBuyer)->postJson('/api/v1/purchase-orders', $this->payload('270.000000000', ['fx_policy' => 'FIXED', 'fx_override_reason' => 'Forward contract FX-2291, fixed to 31 Dec.']))->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($this->fixedRateBuyer)->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk()->assertJsonPath('data.approval.status', 'PENDING');
        $this->assertSame('Forward contract FX-2291, fixed to 31 Dec.', ApprovalRequest::query()->sole()->facts['evidence']['fx_override_reason'] ?? null);
    }

    public function test_editing_the_rate_of_a_draft_is_checked_the_same_way_and_unrelated_edits_are_not_blocked(): void
    {
        $id = $this->actingAsUnprivileged($this->buyer)->postJson('/api/v1/purchase-orders', $this->payload(self::GOVERNED))->assertCreated()->json('data.id');

        $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$id}", ['fx_rate' => '28.000000000'])->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        $this->assertSame(self::GOVERNED, PurchaseOrder::query()->findOrFail($id)->fx_rate);
        $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'still fine'])->assertOk();
        $this->actingAsUnprivileged($this->buyer)->patchJson("/api/v1/purchase-orders/{$id}", ['fx_rate' => '281.000000000'])->assertOk()->assertJsonPath('data.lines.0.base_line_amount', '2810000.0000');
    }
}
