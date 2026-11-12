<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_same_value_reentry_preserves_the_concession_author_and_independence_in_off_mode(): void
    {
        $revision = $this->preparedDraft();
        $this->checker->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_DISCOUNT]);
        $line = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        $input = ['update_lines' => [['id' => $line->id, 'discount' => ['kind' => 'PERCENT', 'value' => '8']]]];
        app(UpdateQuotationDraft::class)($this->checker, $revision->id, $revision->input_generation, $this->issueKey(), $input);
        $originalTime = $line->fresh()->discount_entered_at;
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->fresh()->input_generation, $this->issueKey(), $input);
        $this->assertSame($this->checker->id, $line->fresh()->discount_entered_by);
        $this->assertEquals($originalTime, $line->fresh()->discount_entered_at);
        $this->routingPolicy();
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->fresh()->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayDecide($this->checker, $request, false));
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->fresh()->input_generation, $this->issueKey(), ['update_lines' => [['id' => $line->id, 'discount' => null]]]);
        $this->assertTrue(app(QuotationApprovalSubject::class)->tookPart($this->checker->id, $revision->id));
    }

    public function test_document_and_override_same_value_reentry_preserve_authorship_and_participation(): void
    {
        $this->checker->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE]);
        foreach (['document', 'override'] as $kind) {
            $revision = $this->preparedDraft();
            $line = $revision->lines()->where('membership', 'BASE')->firstOrFail();
            $input = $kind === 'document' ? ['document_discount' => ['kind' => 'PERCENT', 'value' => '8']]
                : ['update_lines' => [['id' => $line->id, 'net_override' => ['amount' => '180', 'reason' => 'Commercial concession']]]];
            app(UpdateQuotationDraft::class)($this->checker, $revision->id, $revision->input_generation, $this->issueKey(), $input);
            app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->fresh()->input_generation, $this->issueKey(), $input);
            $author = $kind === 'document' ? DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->value('entered_by') : $line->fresh()->override_entered_by;
            $this->assertSame($this->checker->id, $author);
            $clear = $kind === 'document' ? ['document_discount' => null] : ['update_lines' => [['id' => $line->id, 'net_override' => null]]];
            app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->fresh()->input_generation, $this->issueKey(), $clear);
            $this->assertTrue(app(QuotationApprovalSubject::class)->tookPart($this->checker->id, $revision->id));
        }
    }

    public function test_participation_evidence_is_append_only_and_company_scoped(): void
    {
        $revision = $this->preparedDraft();
        $foreignCompany = Company::create(['name' => 'Other company']);
        $foreignActor = User::withoutEvents(fn () => User::factory()->create(['company_id' => $foreignCompany->id]));
        foreach ([
            fn () => DB::table('quotation_concession_participants')->where('revision_id', $revision->id)->update(['actor_id' => $this->checker->id]),
            fn () => DB::table('quotation_concession_participants')->where('revision_id', $revision->id)->delete(),
            fn () => DB::statement('TRUNCATE quotation_concession_participants'),
            fn () => DB::table('quotation_concession_participants')->insert(['company_id' => 1, 'revision_id' => $revision->id, 'actor_id' => $foreignActor->id]),
            fn () => DB::table('quotation_concession_participants')->insert(['company_id' => $foreignCompany->id, 'revision_id' => $revision->id, 'actor_id' => $foreignActor->id]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Participation evidence must retain its original company and actor.');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23514', '23503']);
            }
        }
    }

    public function test_participation_migration_rolls_back_and_reapplies_when_empty(): void
    {
        $migration = require database_path('migrations/2026_12_03_050000_preserve_quotation_concession_participants.php');
        $migration->down();
        $migration->up();
        $this->assertSame(0, DB::table('quotation_concession_participants')->count());
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

    /** @return array<string, array{int, int, string, int}> configured days, quotation validity days from today, bound that wins, days of that bound */
    public static function expiryCases(): array
    {
        return [
            'configured 5' => [5, 90, 'policy', 5],
            'configured 14 (the initial default value)' => [14, 90, 'policy', 14],
            'configured 30 is applied, not capped at 14' => [30, 90, 'policy', 30],
            'configured 365 is applied' => [365, 400, 'policy', 365],
            'value larger than remaining validity is bounded by validity' => [60, 20, 'validity', 21],
            'validity shorter than 14' => [14, 3, 'validity', 4],
        ];
    }

    /** ADR 0185/0188: the effective expiry is the configured days bounded only by the quotation validity (end of the valid-through company day). */
    #[DataProvider('expiryCases')]
    public function test_effective_expiry_is_the_configured_days_bounded_only_by_quotation_validity(int $configured, int $validityDays, string $bound, int $days): void
    {
        $zone = Company::findOrFail(1)->timezone;
        $this->travelTo(now($zone)->startOfDay()->addHours(9));
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy(['expiry_calendar_days' => $configured]);
        $this->authorityMode('ENFORCE');
        $revision->setAttribute('valid_through_local_date', now($zone)->addDays($validityDays)->startOfDay());
        $expected = $bound === 'policy' ? now($zone)->addDays($days) : now($zone)->startOfDay()->addDays($days);
        $this->assertSame($expected->utc()->toIso8601String(), app(QuotationAuthorityApproval::class)->deadline($revision));
    }

    public function test_an_unset_validity_still_applies_the_configured_days_and_the_default_policy_value_is_fourteen(): void
    {
        $zone = Company::findOrFail(1)->timezone;
        $this->travelTo(now($zone)->startOfDay()->addHours(9));
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy();
        $this->authorityMode('ENFORCE');
        $revision->setAttribute('valid_through_local_date', null);
        $this->assertSame(now($zone)->addDays(QuotationAuthorityApproval::DEFAULT_EXPIRY_DAYS)->utc()->toIso8601String(), app(QuotationAuthorityApproval::class)->deadline($revision));
        $this->assertSame(14, QuotationAuthorityApproval::DEFAULT_EXPIRY_DAYS);
    }

    public function test_a_later_policy_change_does_not_move_an_already_frozen_deadline(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityPolicy(['expiry_calendar_days' => 30]);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['valid_through_local_date' => now(Company::findOrFail(1)->timezone)->addDays(100)->format('Y-m-d')]);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->refresh()->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $frozen = $request->facts['evidence']['authority_expires_at'];
        $this->assertSame(now(Company::findOrFail(1)->timezone)->addDays(30)->utc()->toIso8601String(), $frozen);
        $this->authorityPolicy(['code' => 'later-policy', 'priority' => 10, 'expiry_calendar_days' => 5]);
        $this->travelTo(now()->addDays(2));
        $this->assertSame($frozen, $request->refresh()->facts['evidence']['authority_expires_at']);
        $this->assertSame($frozen, app(QuotationApprovals::class)->latest($revision->id)->facts['evidence']['authority_expires_at']);
    }

    public function test_a_routing_step_allowing_the_submitter_still_cannot_let_the_submitter_or_a_delegate_decide(): void
    {
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3, $this->maker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        $this->routingPolicy()->steps()->update(['allow_submitter' => true]);
        $this->maker->givePermissionTo([Duty::AUTHORITY_EVIDENCE_VIEW, Duty::QUOTATION_VIEW]);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertSame($this->maker->id, $request->submitted_by);
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayDecide($this->maker, $request, false));
        $this->assertFalse(app(QuotationApprovalSubject::class)->mayDecide($this->maker, $request, true));
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
