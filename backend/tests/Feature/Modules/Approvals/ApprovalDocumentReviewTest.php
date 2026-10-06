<?php

namespace Tests\Feature\Modules\Approvals;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Approvals\Actions\CreateApprovalPolicy;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\RequireApproval;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\Review\ApprovalReviewAccess;
use App\Modules\Approvals\Support\Review\ApprovalReviewRegistry;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class ApprovalDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private PurchaseOrder $order;

    private ApprovalRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = PermissionedActor::grant(User::factory()->create(), ['purchasing.purchase-order.submit']);
        [$this->order, $item, $unit] = $this->makeOrder();
        $this->order->lines()->create(['description' => 'Specification: exterior coating', 'ordered_qty' => '7.0000', 'ordered_stock_qty' => '7.0000', 'unit_price' => '123.4500', 'base_unit_price' => '123.4500', 'line_amount' => '864.1500', 'base_line_amount' => '864.1500'] + $this->lineAttributes($this->order, $item, $unit));
        $this->order->update(['notes' => 'Confidential commercial note 123.4500']);
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'PURCHASE_ORDER'], ['mode' => 'REQUIRED']);
        $this->policy('COMMERCIAL');
        $this->request = app(SubmitForApproval::class)('PURCHASE_ORDER', $this->order->id, $this->buyer);
    }

    private function policy(string $profile): ApprovalPolicy
    {
        ApprovalPolicy::query()->where('subject_type', 'PURCHASE_ORDER')->update(['status' => 'SUPERSEDED']);
        $policy = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => 'review-'.strtolower($profile), 'version_no' => 1 + (int) ApprovalPolicy::query()->where('code', 'review-'.strtolower($profile))->max('version_no'), 'name' => 'Document review', 'priority' => 1, 'status' => 'ACTIVE', 'reason' => 'Review policy', 'changed_by' => $this->buyer->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Review', 'required_approvals' => 1, 'review_profile' => $profile]);

        return $policy;
    }

    private function approver(): User
    {
        return PermissionedActor::grant(User::factory()->create(), ['purchasing.purchase-order.approve']);
    }

    private function review(User $actor, ?ApprovalRequest $request = null)
    {
        return $this->actingAs($actor)->getJson('/api/v1/approval-requests/'.($request ?? $this->request)->id);
    }

    public function test_assigned_approver_gets_full_saved_document_without_company_wide_po_or_financial_access(): void
    {
        $actor = $this->approver();
        $response = $this->review($actor)->assertOk()->assertJsonPath('data.document_review.available', true)
            ->assertJsonPath('data.document_review.profile', 'COMMERCIAL')->assertJsonPath('data.can_decide', true);
        foreach (['Specification: exterior coating', '7.0000', '123.4500', '864.1500', 'Supplier', 'Payment terms (days)', 'Tax', 'Discount'] as $evidence) {
            $this->assertStringContainsString($evidence, $response->getContent());
        }
        foreach (['credit_limit', 'payable_account_id', 'inventory_account_id', 'password', 'offer_snapshot'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $response->getContent());
        }
        $this->getJson('/api/v1/purchase-orders/'.$this->order->id)->assertForbidden();
        $this->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->assertFalse($actor->can('purchasing.commercial-cost.view'));
        $this->getJson('/api/v1/approval-requests/inbox')->assertOk()->assertJsonPath('data.0.document_review.profile', 'COMMERCIAL');
    }

    public function test_history_reader_and_unrelated_user_cannot_receive_review_fields(): void
    {
        $history = PermissionedActor::grant(User::factory()->create(), ['approvals.history.view']);
        $response = $this->review($history)->assertOk()->assertJsonPath('data.document_review.available', false)
            ->assertJsonPath('data.document_review.sections', []);
        $this->assertStringNotContainsString('123.4500', $response->getContent());
        $this->assertStringNotContainsString('Specification: exterior coating', $response->getContent());
        $response = $this->actingAs($history)->getJson('/api/v1/approval-requests')->assertOk();
        $this->assertStringNotContainsString('123.4500', $response->getContent());
        $this->review(User::factory()->create())->assertForbidden();
        $financeOnly = PermissionedActor::grant(User::factory()->create(), ['purchasing.commercial-cost.view']);
        $this->assertNull(app(ApprovalReviewAccess::class)->profile($financeOnly, $this->request));
        $this->review($financeOnly)->assertForbidden();
    }

    public function test_technical_step_hides_structured_money_and_retains_its_historical_profile(): void
    {
        $this->request->update(['status' => 'WITHDRAWN', 'closed_at' => now()]);
        $this->policy('OPERATIONAL');
        $request = app(SubmitForApproval::class)('PURCHASE_ORDER', $this->order->id, $this->buyer);
        $actor = PermissionedActor::grant(User::factory()->create(), ['approvals.document.technical-review']);
        $response = $this->review($actor, $request)->assertOk()->assertJsonPath('data.document_review.profile', 'OPERATIONAL')
            ->assertJsonPath('data.amount_base', null)->assertJsonPath('data.evidence', []);
        $this->assertStringContainsString('Specification: exterior coating', $response->getContent());
        $this->assertStringNotContainsString('123.4500', $response->getContent());
        $this->assertStringNotContainsString('Unit price', $response->getContent());
        $approved = app(DecideApproval::class)($request, $actor, ApprovalDecisionKind::Approved);
        $this->assertSame('OPERATIONAL', $approved->steps->first()->decisions->first()->authority_snapshot['review_profile']);
        $this->policy('COMMERCIAL');
        $this->order->lines()->update(['unit_price' => '999.0000', 'description' => 'Changed later']);
        $response = $this->review($actor, $approved)->assertOk()->assertJsonPath('data.document_review.profile', 'OPERATIONAL');
        $this->assertStringContainsString('Specification: exterior coating', $response->getContent());
        $this->assertStringNotContainsString('Changed later', $response->getContent());
        $this->assertStringNotContainsString('999.0000', $response->getContent());
    }

    public function test_delegates_receive_only_the_principals_request_profile_and_inactive_principals_are_refused(): void
    {
        $principal = $this->approver();
        $delegate = User::factory()->create();
        ApprovalDelegation::create(['delegator_id' => $principal->id, 'delegate_id' => $delegate->id, 'subject_type' => 'PURCHASE_ORDER', 'valid_from' => today(), 'valid_to' => today()->addDay(), 'reason' => 'Cover', 'created_by' => $principal->id]);
        $this->review($delegate)->assertOk()->assertJsonPath('data.document_review.profile', 'COMMERCIAL');
        $principal->update(['status' => 'SUSPENDED']);
        $this->review($delegate)->assertForbidden();
    }

    public function test_changed_document_with_the_same_total_invalidates_decision_and_issuance(): void
    {
        $actor = $this->approver();
        $this->order->lines()->update(['ordered_qty' => '8.0000']);
        $this->actingAs($actor)->postJson('/api/v1/approval-requests/'.$this->request->id.'/decide', ['decision' => 'APPROVED'])->assertUnprocessable();
        $this->assertSame('INVALIDATED', $this->request->fresh()->status->value);
        $this->request->update(['status' => 'APPROVED']);
        try {
            app(RequireApproval::class)('PURCHASE_ORDER', $this->order->id);
            $this->fail('A changed document reused its saved approval.');
        } catch (DomainInvariantException $exception) {
            $this->assertSame('INVALIDATED', $this->request->fresh()->status->value);
        }
    }

    public function test_missing_evidence_prevents_decisions_and_cross_company_snapshots_do_not_project(): void
    {
        $facts = $this->request->facts;
        unset($facts['document_review']);
        $this->request->update(['facts' => $facts]);
        $actor = $this->approver();
        $this->actingAs($actor)->postJson('/api/v1/approval-requests/'.$this->request->id.'/decide', ['decision' => 'APPROVED'])->assertUnprocessable();
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->review($this->buyer)->assertOk()->assertJsonPath('data.document_review_ready', false)->assertJsonPath('data.can_decide', false);
        $snapshot = app(ApprovalReviewRegistry::class)->capture('PURCHASE_ORDER', $this->order->id);
        $snapshot['company_id'] = 2;
        $this->request->update(['facts' => $facts + ['document_review' => $snapshot]]);
        $this->assertNull(app(ApprovalReviewAccess::class)->profile($actor, $this->request->fresh()));
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $this->review($actor)->assertNotFound();
    }

    public function test_policy_creation_refuses_operational_spend_authority_in_http_and_action_paths(): void
    {
        $manager = PermissionedActor::grant(User::factory()->create(), ['approvals.policy.manage']);
        $attributes = ['subject_type' => 'PURCHASE_ORDER', 'code' => 'technical-spend', 'name' => 'Bad spend', 'reason' => 'Wrong review purpose', 'steps' => [['name' => 'Spend', 'required_approvals' => 1, 'review_profile' => 'OPERATIONAL', 'use_authority_limit' => true]]];
        $this->actingAs($manager)->postJson('/api/v1/approval-policies', $attributes)->assertUnprocessable();
        $this->expectException(DomainInvariantException::class);
        app(CreateApprovalPolicy::class)($attributes, $manager);
    }

    public function test_foreign_actor_is_refused_even_if_given_an_invalid_company_permission_pivot(): void
    {
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other company']);
        $context = app(CompanyContext::class);
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $foreign = User::factory()->create();
        app()->instance(CompanyContext::class, $context);
        PermissionedActor::grant($foreign, ['purchasing.purchase-order.approve']);
        $this->actingAs($foreign)->postJson('/api/v1/approval-requests/'.$this->request->id.'/decide', ['decision' => 'APPROVED'])->assertUnprocessable();
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->assertSame('PENDING', $this->request->fresh()->status->value);
    }

    public function test_rollout_prepares_only_current_pending_requests_and_never_reconstructs_completed_history(): void
    {
        $facts = $this->request->facts;
        unset($facts['document_review']);
        $this->request->update(['facts' => $facts]);
        $completed = ApprovalRequest::query()->create($this->request->only(['subject_type', 'subject_id', 'policy_id', 'submitted_by', 'submitted_at', 'facts', 'facts_hash', 'summary']) + ['status' => 'APPROVED']);
        $migration = require database_path('migrations/2026_10_06_150000_add_document_review_profiles_to_approvals.php');
        $before = app(CompanyContext::class);
        $migration->preparePendingRequests();
        $this->assertSame($before, app(CompanyContext::class));
        $this->assertNotNull($this->request->fresh()->facts['document_review']);
        $this->assertArrayNotHasKey('document_review', $completed->fresh()->facts);
        $saved = $this->request->fresh()->facts;
        $migration->preparePendingRequests();
        $this->assertSame($saved, $this->request->fresh()->facts);
        $this->request->refresh()->update(['facts' => $facts]);
        $this->order->lines()->update(['description' => 'Routing figures changed', 'line_amount' => '1.0000', 'base_line_amount' => '1.0000']);
        $migration->preparePendingRequests();
        $this->assertArrayNotHasKey('document_review', $this->request->fresh()->facts);
    }

    /** @return array{PurchaseOrder, Item, Uom} */
    protected function makeOrder(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        [$supplier, $site] = $this->makeSupplier('SUP');
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();

        return [$order, $item, $uom];
    }

    /** @return array{SupplierAccount, SupplierSite} */
    protected function makeSupplier(string $code): array
    {
        $party = Party::create(['code' => $code, 'name' => $code]);
        $account = ChartOfAccount::create(['code' => 'pay-'.$code, 'name' => 'Payable '.$code, 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => $code, 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);

        return [$supplier, $site];
    }

    /** @return array<string, mixed> */
    protected function lineAttributes(PurchaseOrder $order, Item $item, Uom $uom, int $lineNumber = 1): array
    {
        return [
            'purchase_order_id' => $order->id, 'line_number' => $lineNumber, 'item_id' => $item->id,
            'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uom->id,
            'ordered_stock_qty' => '2.0000', 'stock_uom_id' => $uom->id, 'uom_factor' => '1.000000000',
            'unit_price' => '10.0000', 'line_amount' => '20.0000',
            'base_unit_price' => '10.0000', 'base_line_amount' => '20.0000',
        ];
    }
}
