<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\AssessAcceptanceReadiness;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\PreviewCustomerAcceptance;
use App\Modules\Sales\Actions\ReadCustomerAcceptance;
use App\Modules\Sales\Actions\RecordCustomerAcceptance;
use App\Modules\Sales\Actions\WithdrawQuotationOffer;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceIntent;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceReviewToken;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\QuotationAcceptanceState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class CustomerAcceptanceActionsTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    /** @var list<int> */
    private array $args;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped()->withActivated([P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_ACCEPT]));
        foreach ([P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_ACCEPT] as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        $revision = $this->preparedDraft();
        app(IssueQuotation::class)($this->checker, $revision->id, $revision->input_generation, 'issue');
        $line = QuotationIssuedLine::query()->where('revision_id', $revision->id)->orderBy('id')->firstOrFail();
        $case = CustomerDecisionCase::create(['party_id' => $this->customer->id, 'intake_identity' => 'Synthetic consent', 'recorded_by' => $this->checker->id]);
        $component = CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => 'scope', 'family_id' => $line->family_id, 'original_scope_anchor_id' => $line->logical_scope_id, 'recorded_by' => $this->checker->id]);
        $evidence = CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'CONFIRMED_BY_SELLER', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'The buyer agrees to the full issued scope unchanged.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->checker->id]);
        $this->args = [$revision->id, $component->id, $evidence->id, $line->id, 0, 0];
    }

    private function preview(?User $actor = null): array
    {
        return app(PreviewCustomerAcceptance::class)($actor ?? $this->checker, ...$this->args);
    }

    private function record(string $key, string $token, ?User $actor = null): array
    {
        return app(RecordCustomerAcceptance::class)($actor ?? $this->checker, $key, ...[...$this->args, null, $token, true]);
    }

    public function test_preview_is_read_only_and_full_terms_are_frozen_then_exact_retries_replay_even_with_an_expired_token(): void
    {
        $p = $this->preview();
        $this->assertSame('2.0000', $p['terms']['quantity']);
        $this->assertSame('200.0000', $p['terms']['net']);
        $this->assertDatabaseCount('customer_scope_claim_heads', 0);
        $this->assertDatabaseCount('customer_evidence_policy_versions', 0);
        $a = $this->record('record', $p['preview_token']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertFalse($a['replayed']);
        $this->assertSame($a['acceptance_id'], $this->record('record', 'expired')['acceptance_id']);
        $other = User::factory()->create();
        $other->givePermissionTo([P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::QUOTATION_VIEW]);
        $this->assertSame($a['acceptance_id'], $this->record('fresh-key', 'expired', $other)['acceptance_id']);
        $this->assertDatabaseCount('customer_acceptances', 1);
        $this->assertDatabaseCount('customer_claim_events', 1);
        $this->assertDatabaseCount('sales_customer_decision_receipts', 2);
        $this->assertDatabaseHas('customer_decision_cases', ['generation' => 1]);
    }

    public function test_acceptance_rebind_blocks_withdrawal_and_readiness_stays_pending(): void
    {
        $a = $this->record('record', $this->preview()['preview_token']);
        $this->assertTrue(app(QuotationAcceptanceState::class)->stands(QuotationRevision::findOrFail($this->args[0])));
        $read = app(ReadCustomerAcceptance::class)($this->checker, $a['acceptance_id']);
        $this->assertSame('200.0000', $read['scope']['net']);
        try {
            app(AssessAcceptanceReadiness::class)($this->checker, $a['acceptance_id']);
            $this->fail('Manual scope has no service adapter.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('HANDOVER_PATH_UNSUPPORTED', $e->reasonCode);
        }
        Permission::findOrCreate(P::QUOTATION_WITHDRAW, 'web');
        $this->checker->givePermissionTo(P::QUOTATION_WITHDRAW);
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([P::QUOTATION_WITHDRAW]));
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('A customer decision already stands');
        app(WithdrawQuotationOffer::class)($this->checker, $this->args[0], 'withdraw', 'Synthetic withdrawal');
    }

    public function test_staged_duty_refuses_even_a_hand_granted_actor(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        $this->preview();
    }

    public function test_actor_bound_token_is_refused_and_no_receipt_or_baseline_survives(): void
    {
        $token = $this->preview()['preview_token'];
        $other = User::factory()->create();
        $other->givePermissionTo([P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::QUOTATION_VIEW]);
        try {
            $this->record('foreign-review', $token, $other);
            $this->fail('An actor cannot reuse another person\'s review.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('PREVIEW_TOKEN_STALE', $e->reasonCode);
        }
        $this->assertDatabaseCount('customer_acceptances', 0);
        $this->assertDatabaseCount('customer_scope_claim_heads', 0);
        $this->assertDatabaseCount('sales_customer_decision_receipts', 0);
    }

    public function test_suspended_actor_cannot_replay_a_receipt(): void
    {
        $this->record('record', $this->preview()['preview_token']);
        DB::table('users')->where('id', $this->checker->id)->update(['status' => 'SUSPENDED']);
        $this->expectException(AuthorizationException::class);
        $this->record('record', 'old');
    }

    public function test_moved_case_is_refused_and_a_key_with_changed_intent_is_refused(): void
    {
        $token = $this->preview()['preview_token'];
        $this->record('record', $token);
        $this->args[4] = 1;
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('That request key was already used for a different request.');
        $this->record('record', $token);
    }

    public function test_case_changes_after_preview_refuse_and_leave_no_claim_or_receipt(): void
    {
        $token = $this->preview()['preview_token'];
        CustomerDecisionCase::query()->increment('generation');
        try {
            $this->record('stale', $token);
            $this->fail('Moved consent must be reviewed again.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('DECISION_CASE_STALE', $e->reasonCode);
        }
        $this->assertDatabaseCount('customer_acceptances', 0);
        $this->assertDatabaseCount('customer_scope_claim_heads', 0);
        $this->assertDatabaseCount('sales_customer_decision_receipts', 0);
    }

    public function test_expiry_uses_the_company_day_at_decision_and_not_the_preview_time(): void
    {
        $token = $this->preview()['preview_token'];
        $revision = QuotationRevision::findOrFail($this->args[0]);
        $this->travelTo($revision->expires_at);
        try {
            // Fresh review-token failure is separate from the final eligibility check.
            $intent = AcceptanceIntent::of(...[...$this->args, null]);
            $token = app(AcceptanceReviewToken::class)->issue($this->checker, AcceptanceIntent::digest($intent));
            $this->record('expired-offer', $token);
            $this->fail('An expired offer cannot be accepted.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('QUOTATION_NOT_AVAILABLE', $e->reasonCode);
        }
        $this->assertDatabaseCount('customer_acceptances', 0);
        $this->assertDatabaseCount('sales_customer_decision_receipts', 0);
    }

    public function test_an_expired_review_refuses_without_evidence(): void
    {
        $token = $this->preview()['preview_token'];
        $this->travel(11)->minutes();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('Review this acceptance again');
        $this->record('expired', $token);
    }

    public function test_a_limited_acceptance_duty_cannot_be_rescued_by_unlimited_view(): void
    {
        AccessScopeLimit::create(['user_id' => $this->checker->id, 'permission' => P::CUSTOMER_DECISION_ACCEPT, 'scope' => 'MY_UNITS']);
        $this->expectException(AuthorizationException::class);
        $this->preview();
    }

    public function test_independent_read_is_required(): void
    {
        $this->checker->revokePermissionTo(P::CUSTOMER_DECISION_VIEW);
        $this->expectException(AuthorizationException::class);
        $this->preview();
    }
}
