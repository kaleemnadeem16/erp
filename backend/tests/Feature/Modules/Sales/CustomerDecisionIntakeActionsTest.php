<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Actions\AppendCustomerDecisionEvidence;
use App\Modules\Sales\Actions\OpenCustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * CA1 — opening an intake case and appending observations: keyed, expected-head, never merging an existing intake identity, never an acceptance, and judged again inside the operation.
 */
class CustomerDecisionIntakeActionsTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        Permission::findOrCreate(PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD, 'web');
        $this->recorder = User::factory()->create();
        $this->recorder->givePermissionTo([PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD, PermissionVocabulary::QUOTATION_VIEW]);
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD], true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate) : $d, SalesDutyManifest::shipped()->all())));
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $e) {
            return (string) $e->reasonCode;
        }

        return 'NO_REFUSAL';
    }

    /** @return array{family: int, scope: int} */
    private function scope(): array
    {
        $line = QuotationDraftLine::query()->where('revision_id', $this->preparedDraft()->id)->orderBy('id')->firstOrFail();

        return ['family' => $line->family_id, 'scope' => $line->logical_scope_id];
    }

    private function observation(array $over = []): array
    {
        return $over + ['source_kind' => 'EMAIL', 'authority_verification_basis' => 'STATED_BY_CUSTOMER', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'The customer wrote that they would like to go ahead.'];
    }

    public function test_a_case_is_opened_with_its_components_and_a_replay_returns_the_same_case(): void
    {
        $s = $this->scope();
        $components = [['intent_identity' => 'whole-quote', 'family_id' => $s['family'], 'original_scope_anchor_id' => $s['scope']]];

        $first = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-4471', $components);
        $again = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-4471', $components);

        $this->assertTrue($again->replayed);
        $this->assertSame([$first->caseId, 0], [$again->caseId, $first->generation]);
        $case = CustomerDecisionCase::query()->sole();
        $this->assertSame([$this->customer->id, 'PO-4471', $this->recorder->id], [$case->party_id, $case->intake_identity, $case->recorded_by]);
        $this->assertSame(['whole-quote'], CustomerDecisionComponent::query()->pluck('intent_identity')->all());
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-4472', $components)));
    }

    public function test_an_existing_intake_identity_is_never_merged_and_malformed_requests_save_nothing(): void
    {
        $s = $this->scope();
        app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-1');

        $this->assertSame('INTAKE_IDENTITY_EXISTS', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, 'k2', $this->customer->id, 'PO-1')));
        $this->assertSame('REQUEST_KEY_REQUIRED', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, null, $this->customer->id, 'PO-2')));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, 'k3', $this->customer->id, '  ')));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, 'k4', 999999, 'PO-3')));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, 'k5', $this->customer->id, 'PO-4', [['intent_identity' => 'x', 'family_id' => $s['family'], 'original_scope_anchor_id' => 999999]])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(OpenCustomerDecisionCase::class)($this->recorder, 'k6', $this->customer->id, 'PO-5', [['intent_identity' => 'x', 'family_id' => 999999, 'original_scope_anchor_id' => $s['scope']]])));
        $this->assertSame(1, CustomerDecisionCase::query()->count());
    }

    public function test_evidence_is_appended_at_the_expected_head_and_a_replay_adds_nothing(): void
    {
        $case = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-9');

        $first = app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation());
        $again = app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation());

        $this->assertSame([1, true, $first->evidenceId], [$first->generation, $again->replayed, $again->evidenceId]);
        $this->assertSame(1, CustomerDecisionEvidence::query()->count());
        $this->assertSame(1, CustomerDecisionCase::query()->sole()->generation);
        $evidence = CustomerDecisionEvidence::query()->sole();
        $this->assertSame(['OBSERVED', 'UNKNOWN', null, $this->recorder->id], [$evidence->observation_state, $evidence->occurrence_precision, $evidence->occurred_at, $evidence->recorded_by], 'unknown stays unknown');
        $this->assertSame('DECISION_CASE_STALE', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e2', $case->caseId, 0, $this->observation())));
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation(['factual_description' => 'Different']))));
        $this->assertSame('DECISION_CASE_NOT_FOUND', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e3', 999999, 0, $this->observation())));
    }

    public function test_a_time_with_an_offset_is_stored_as_the_same_instant_not_as_wall_clock_time_in_utc(): void
    {
        $case = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-TZ');
        app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation([
            'occurrence_precision' => 'INTERVAL', 'occurred_at' => '2026-11-10 10:00:00+05', 'occurred_until' => '2026-11-10T12:30:00+05:00', 'source_timezone' => 'Asia/Karachi',
        ]));

        $evidence = CustomerDecisionEvidence::query()->sole();
        $this->assertSame(['2026-11-10 05:00:00', '2026-11-10 07:30:00'], [$evidence->occurred_at->utc()->format('Y-m-d H:i:s'), $evidence->occurred_until->utc()->format('Y-m-d H:i:s')]);
    }

    public function test_a_later_version_names_one_earlier_observation_and_a_chain_never_forks(): void
    {
        $case = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-10');
        $first = app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation());
        $second = app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e2', $case->caseId, 1, $this->observation(['factual_description' => 'Corrected: only the second item.']), $first->evidenceId);

        $this->assertSame($first->evidenceId, CustomerDecisionEvidence::query()->findOrFail($second->evidenceId)->predecessor_id);
        $this->assertSame('EVIDENCE_PREDECESSOR_INVALID', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e3', $case->caseId, 2, $this->observation(), $first->evidenceId)), 'a fork');
        $this->assertSame('EVIDENCE_PREDECESSOR_INVALID', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e4', $case->caseId, 2, $this->observation(), 999999)));
        $other = app(OpenCustomerDecisionCase::class)($this->recorder, 'k2', $this->customer->id, 'PO-11');
        $this->assertSame('EVIDENCE_PREDECESSOR_INVALID', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e5', $other->caseId, 0, $this->observation(), $first->evidenceId)), 'another case');
        $this->assertSame(2, CustomerDecisionEvidence::query()->count());
    }

    public function test_a_dishonest_or_malformed_observation_is_refused_and_leaves_the_case_head_alone(): void
    {
        $case = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-12');
        $bad = [
            'unknown source' => ['source_kind' => 'TELEPATHY'],
            'unknown basis' => ['authority_verification_basis' => 'TRUST_ME'],
            'a date with no zone' => ['occurrence_precision' => 'DATE', 'occurred_at' => '2026-10-01 00:00:00'],
            'unknown precision with a date' => ['occurred_at' => '2026-10-01 00:00:00'],
            'an interval ending first' => ['occurrence_precision' => 'INTERVAL', 'occurred_at' => '2026-10-02 00:00:00+00', 'occurred_until' => '2026-10-01 00:00:00+00'],
            'empty description' => ['factual_description' => '   '],
        ];
        foreach ($bad as $name => $over) {
            $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'b-'.md5($name), $case->caseId, 0, $this->observation($over))), $name);
        }
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(AppendCustomerDecisionEvidence::class)($this->recorder, 'b-extra', $case->caseId, 0, $this->observation(['accepted' => true]))), 'there is no acceptance field');
        $this->assertSame([0, 0], [CustomerDecisionEvidence::query()->count(), CustomerDecisionCase::query()->sole()->generation]);
    }

    public function test_only_an_active_member_holding_the_duty_may_record_and_it_is_judged_again_inside_the_operation(): void
    {
        $case = app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-13');
        foreach (['a preparer' => $this->maker, 'the checker' => $this->checker] as $name => $by) {
            try {
                app(OpenCustomerDecisionCase::class)($by, 'x-'.$by->id, $this->customer->id, 'PO-X');
                $this->fail("{$name} must be refused.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation());
        $armed = true;
        Event::listen(TransactionBeginning::class, function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                DB::table('users')->where('id', $this->recorder->id)->update(['status' => MemberStatus::Suspended->value]);
            }
        });
        $this->expectException(AuthorizationException::class);
        try {
            app(AppendCustomerDecisionEvidence::class)($this->recorder, 'e1', $case->caseId, 0, $this->observation());
        } finally {
            $this->assertFalse($armed, 'the suspension landed after the outer check, so the replay was refused');
        }
    }

    public function test_the_shipped_manifest_refuses_everyone(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        app(OpenCustomerDecisionCase::class)($this->recorder, 'k1', $this->customer->id, 'PO-14');
    }
}
