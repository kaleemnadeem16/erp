<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\ResolveApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RequestQuotationApproval;
use App\Modules\Sales\Actions\RevokeDiscountAuthority;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Modules\Sales\Support\DiscountAuthority\QuotationAuthorityApproval;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\TestCase;

class DiscountAuthorityApprovalTest extends TestCase
{
    use BuildsDiscountAuthority;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscountAuthority();
        Permission::findOrCreate(Duty::AUTHORITY_EVIDENCE_VIEW, 'web');
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::AUTHORITY_EVIDENCE_VIEW]));
        $this->checker->givePermissionTo([Duty::AUTHORITY_EVIDENCE_VIEW, Duty::QUOTATION_VIEW]);
        ApprovalSubjectSetting::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'mode' => ApprovalMode::Required]);
    }

    private function routingPolicy(string $code = 'review', int $priority = 0, ?string $from = null, ?string $to = null): ApprovalPolicy
    {
        $policy = ApprovalPolicy::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'code' => $code, 'version_no' => 1, 'name' => 'Independent review', 'priority' => $priority,
            'status' => ApprovalPolicyStatus::Active, 'effective_from' => $from, 'effective_to' => $to, 'reason' => 'Test review policy', 'changed_by' => $this->checker->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Independent approval', 'required_approvals' => 1]);

        return $policy;
    }

    public function test_routes_the_evaluated_concession_and_refuses_equal_priority_instead_of_using_ids(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $first = $this->routingPolicy();
        $routing = app(QuotationApprovalSubject::class)->routing($revision->id);
        $this->assertSame('16.0000', $routing->amountBase);
        $this->assertSame(3, $routing->facts['authority_rank']);
        $this->assertSame($first->id, app(ResolveApprovalPolicy::class)(QuotationApprovalSubject::CODE, $routing)->id);
        $this->routingPolicy('duplicate');
        try {
            app(ResolveApprovalPolicy::class)(QuotationApprovalSubject::CODE, $routing);
            $this->fail('A tie must refuse.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('APPROVAL_POLICY_AMBIGUOUS', $e->reasonCode);
        }
    }

    public function test_strict_selection_ignores_future_and_expired_policies(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $current = $this->routingPolicy();
        $this->routingPolicy('future', 100, now()->addDay()->format('Y-m-d'));
        $this->routingPolicy('expired', 100, null, $this->today());
        $this->assertSame($current->id, app(ResolveApprovalPolicy::class)(QuotationApprovalSubject::CODE, app(QuotationApprovalSubject::class)->routing($revision->id))->id);
    }

    public function test_generic_decision_rechecks_live_assignment_and_records_no_decision_after_revocation(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $assignment = $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        app(RevokeDiscountAuthority::class)($this->maker, $assignment->id, $this->issueKey(), $this->authorityGeneration(), 'Test revocation');
        $this->expectException(DomainInvariantException::class);
        try {
            app(DecideApproval::class)($request, $this->checker, ApprovalDecisionKind::Approved);
        } finally {
            $this->assertSame(0, $request->decisions()->count());
        }
    }

    public function test_approved_request_expires_at_the_exact_deadline_and_cannot_authorise_issue(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        app(DecideApproval::class)($request, $this->checker, ApprovalDecisionKind::Approved);
        $this->assertSame(QuotationApprovals::APPROVED, app(QuotationApprovals::class)->standing($revision)['state']);
        $this->travelTo(Carbon::parse($request->facts['evidence']['authority_expires_at']));
        $this->assertFalse(app(QuotationApprovals::class)->isCurrent($revision, $request));
        $this->assertSame(QuotationApprovals::NOT_REQUESTED, app(QuotationApprovals::class)->standing($revision)['state']);
    }

    public function test_routine_clearance_uses_the_entrants_assignment_and_issue_pins_the_evaluation(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $this->authorityMode('ENFORCE');
        $readiness = app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id);
        $this->assertTrue($readiness['issue_ready']);
        app(IssueQuotation::class)($this->checker, $revision->id, $revision->input_generation, $this->issueKey());
        $issue = DB::table('quotation_issues')->where('revision_id', $revision->id)->first();
        $this->assertNotNull($issue->authority_evaluation_id);
        $evaluation = DB::table('sales_discount_evaluations')->where('id', $issue->authority_evaluation_id)->first();
        $this->assertSame('ROUTINE', $evaluation->result);
        $this->assertSame('4.0000', $evaluation->reduction_base_amount);
    }

    public function test_a_higher_employee_band_cannot_substitute_without_the_policy_allowing_it(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayDecide($this->checker, $request, false));
    }

    public function test_the_utc_deadline_is_capped_by_the_company_local_valid_through_day(): void
    {
        Company::findOrFail(1)->update(['timezone' => 'Asia/Karachi']);
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['valid_through_local_date' => $this->today()]);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->refresh()->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertSame(Carbon::parse($this->expiryOf($this->today()))->utc()->toIso8601String(), $request->facts['evidence']['authority_expires_at']);
    }

    public function test_suspending_the_approver_after_approval_removes_issue_clearance(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        app(DecideApproval::class)($request, $this->checker, ApprovalDecisionKind::Approved);
        $request->refresh();
        $this->assertTrue(app(QuotationApprovals::class)->isCurrent($revision, $request));
        DB::table('users')->where('id', $this->checker->id)->update(['status' => 'SUSPENDED']);
        $this->assertFalse(app(QuotationApprovals::class)->isCurrent($revision, $request));
        DB::table('users')->where('id', $this->checker->id)->update(['status' => 'ACTIVE']);
        $this->assertFalse(app(QuotationApprovals::class)->isCurrent($revision, $request), 'Restoring access cannot revive an older decision epoch.');
    }

    public function test_an_expired_pending_request_can_be_submitted_again_with_a_fresh_deadline(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $old = app(QuotationApprovals::class)->latest($revision->id);
        $this->travelTo(Carbon::parse($old->facts['evidence']['authority_expires_at']));
        Sanctum::actingAs($this->maker);
        $this->getJson('/api/v1/quotations/'.$revision->id.'/approval')->assertOk()->assertJsonPath('data.request.expired', true)->assertJsonPath('data.can.request', true);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $fresh = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertSame('EXPIRED', $old->refresh()->status->value);
        $this->assertTrue(Carbon::parse($fresh->facts['evidence']['authority_expires_at'])->greaterThan(now()));
    }

    public function test_enforce_cannot_clear_an_unbound_legacy_draft_even_when_no_discount_was_declared(): void
    {
        $revision = $this->preparedDraft();
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $assessment = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame('UNEVALUABLE', $assessment['result']);
        $this->assertContains('REFERENCE_NOT_BOUND', $assessment['reasons']);
        $readiness = app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id);
        $this->assertFalse($readiness['issue_ready']);
    }

    public function test_policy_configuration_cannot_extend_approval_beyond_fourteen_calendar_days(): void
    {
        $this->travelTo(now()->startOfMinute());
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy(['expiry_calendar_days' => 30]);
        $this->authorityMode('ENFORCE');
        $deadline = app(QuotationAuthorityApproval::class)->deadline($revision);
        $this->assertSame(now(Company::findOrFail(1)->timezone)->addDays(14)->utc()->toIso8601String(), $deadline);
    }

    public function test_shared_request_labels_and_figures_remain_blind_without_the_independent_evidence_duty(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $route = $this->routingPolicy();
        // A company-written label can contain a ceiling; it must not become a field-policy bypass.
        $route->update(['name' => 'DEMO ONLY commercial threshold']);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        Sanctum::actingAs($this->maker);
        $this->getJson('/api/v1/approval-requests/'.$request->id)->assertOk()->assertJsonPath('data.evidence_visible', false)
            ->assertJsonPath('data.evidence', [])->assertJsonPath('data.amount_base', null)->assertJsonPath('data.policy.name', 'Quotation review')->assertJsonPath('data.steps.0.name', 'Review step');
        $this->maker->givePermissionTo(Duty::AUTHORITY_EVIDENCE_VIEW);
        $this->authorityMode('OFF');
        $this->getJson('/api/v1/approval-requests/'.$request->id)->assertOk()->assertJsonPath('data.evidence_visible', true)->assertJsonPath('data.amount_base', '16.0000');
        DB::table('users')->where('id', $this->maker->id)->update(['must_change_password' => true]);
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayReadRequestEvidence($this->maker, $request));
    }

    public function test_the_sales_request_evidence_is_immutable_but_decision_status_can_advance(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        foreach (['facts' => json_encode(['version' => 'different'], JSON_THROW_ON_ERROR), 'facts_hash' => str_repeat('0', 64), 'summary' => json_encode(['title' => 'Changed evidence'], JSON_THROW_ON_ERROR)] as $column => $value) {
            try {
                DB::transaction(fn () => DB::table('approval_requests')->where('id', $request->id)->update([$column => $value]));
                $this->fail('Frozen Sales approval evidence must not change.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
        foreach ([
            fn () => DB::table('approval_requests')->where('id', $request->id)->delete(),
            fn () => DB::statement('TRUNCATE approval_requests CASCADE'),
        ] as $remove) {
            try {
                DB::transaction($remove);
                $this->fail('Sales approval evidence must not be removed.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
        $approved = app(DecideApproval::class)($request, $this->checker, ApprovalDecisionKind::Approved);
        $this->assertSame('APPROVED', $approved->status->value);
    }

    public function test_a_configuration_change_can_be_resubmitted_without_leaving_a_stale_pending_request(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $old = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertTrue(app(QuotationApprovalSubject::class)->mayDecide($this->checker, $old, false));
        $this->authorityAssignment($policy, 1);
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayDecide($this->checker, $old, false));
        Sanctum::actingAs($this->maker);
        $this->getJson('/api/v1/quotations/'.$revision->id.'/approval')->assertOk()->assertJsonPath('data.can.withdraw', true)->assertJsonPath('data.can.request', true);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $fresh = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertSame('INVALIDATED', $old->refresh()->status->value);
        $this->assertTrue(app(QuotationApprovalSubject::class)->mayDecide($this->checker, $fresh, false));
    }

    public function test_a_commercial_editor_cannot_review_their_own_undeclared_price_reduction(): void
    {
        $revision = $this->authorityDraft();
        $this->checker->givePermissionTo(Duty::QUOTATION_PREPARE);
        $line = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->checker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [['id' => $line->id, 'unit_price' => '99.0000']]]);
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->refresh()->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertTrue(app(QuotationApprovalSubject::class)->tookPart($this->checker->id, $revision->id));
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayDecide($this->checker, $request, false));
    }
}
