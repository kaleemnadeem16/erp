<?php

namespace Tests\Feature\Modules\Approvals;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\ExpireOverdueApprovals;
use App\Modules\Approvals\Actions\RequireApproval;
use App\Modules\Approvals\Actions\ResolveApprovalPolicy;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Actions\WithdrawApproval;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeApprovalSubject;
use Tests\TestCase;

/**
 * ADR 0070's engine through a fake subject type: modes, versioned policies, counted distinct approvers, sequential
 * and parallel steps, maker/checker, authority ceilings, delegation within the delegator's authority, invalidation
 * on change, expiry that never decides, and append-only decisions.
 */
class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeApprovalSubject $subject;

    private User $submitter;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['test.document.approve', 'test.document.escalate', 'approvals.policy.manage'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->subject = new FakeApprovalSubject;
        app(ApprovalSubjectRegistry::class)->register($this->subject);
        $this->subject->documents[1] = ['amount' => '80000.0000', 'warehouse' => 'main'];
        $this->submitter = $this->person();
    }

    private function person(bool $approver = false): User
    {
        $user = User::factory()->create();
        if ($approver) {
            $user->givePermissionTo('test.document.approve');
        }

        return $user;
    }

    private function mode(ApprovalMode $mode): void
    {
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'TEST_DOCUMENT'], ['mode' => $mode]);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array{string, string, mixed}>  $conditions
     */
    private function policy(array $steps, array $conditions = [], int $priority = 0, string $code = 'default', int $version = 1, ApprovalPolicyStatus $status = ApprovalPolicyStatus::Active): ApprovalPolicy
    {
        $policy = ApprovalPolicy::query()->create([
            'subject_type' => 'TEST_DOCUMENT', 'code' => $code, 'version_no' => $version, 'name' => ucfirst($code), 'priority' => $priority,
            'status' => $status, 'reason' => 'test', 'changed_by' => $this->submitter->id,
        ]);
        foreach ($conditions as [$fact, $operator, $value]) {
            $policy->conditions()->create(['fact' => $fact, 'operator' => $operator, 'value' => $value]);
        }
        foreach ($steps as $i => $step) {
            $policy->steps()->create($step + ['stage' => $i + 1, 'name' => 'Step '.($i + 1), 'required_approvals' => 1]);
        }

        return $policy;
    }

    private function submit(int $id = 1): ?ApprovalRequest
    {
        return app(SubmitForApproval::class)('TEST_DOCUMENT', $id, $this->submitter);
    }

    private function decide(ApprovalRequest $request, User $actor, ApprovalDecisionKind $kind = ApprovalDecisionKind::Approved, ?string $reason = null): ApprovalRequest
    {
        return app(DecideApproval::class)($request, $actor, $kind, $reason);
    }

    private function refused(callable $callback, string $contains): void
    {
        try {
            $callback();
            $this->fail("Expected refusal containing: {$contains}");
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public function test_mode_none_asks_nothing_and_a_missing_policy_is_never_read_as_approval_under_required(): void
    {
        $this->assertNull($this->submit());
        app(RequireApproval::class)('TEST_DOCUMENT', 1);

        $this->mode(ApprovalMode::Policy);
        $this->assertNull($this->submit());
        app(RequireApproval::class)('TEST_DOCUMENT', 1);

        $this->mode(ApprovalMode::Required);
        $this->refused(fn () => $this->submit(), 'no approval policy applies');
        $this->refused(fn () => app(RequireApproval::class)('TEST_DOCUMENT', 1), 'no approval policy applies');
    }

    public function test_the_highest_priority_matching_active_policy_wins_and_only_active_versions_count(): void
    {
        $this->mode(ApprovalMode::Policy);
        $general = $this->policy([['use_authority_limit' => false]], [], 0, 'general');
        $big = $this->policy([['use_authority_limit' => false]], [['amount_base', 'GTE', '100000']], 10, 'big');
        $this->policy([['use_authority_limit' => false]], [], 99, 'draft-one', 1, ApprovalPolicyStatus::Draft);
        $resolve = app(ResolveApprovalPolicy::class);

        $this->assertSame($general->id, $resolve('TEST_DOCUMENT', $this->subject->routing(1))->id);
        $this->subject->documents[2] = ['amount' => '150000.0000', 'warehouse' => 'main'];
        $this->assertSame($big->id, $resolve('TEST_DOCUMENT', $this->subject->routing(2))->id);
    }

    public function test_a_request_freezes_facts_currency_evidence_and_the_policy_version(): void
    {
        $this->mode(ApprovalMode::Policy);
        $v1 = $this->policy([['use_authority_limit' => false]]);

        $request = $this->submit();

        $this->assertSame($v1->id, $request->policy_id);
        $this->assertSame('80000.0000', $request->facts['amount_base']);
        $this->assertSame('manual', $request->facts['evidence']['fx_rate_source']);
        $this->assertSame(64, strlen($request->facts_hash));

        // A later version is activated; the request stays explainable under the one it was routed by.
        $v1->update(['status' => ApprovalPolicyStatus::Superseded]);
        $v2 = $this->policy([['use_authority_limit' => false], ['use_authority_limit' => false]], [], 0, 'default', 2);
        $this->assertSame($v1->id, $request->fresh()->policy_id);
        $this->assertNotSame($v2->id, $request->fresh()->policy_id);
        $this->assertSame(1, $request->fresh()->steps()->count());
    }

    public function test_two_required_approvals_need_two_distinct_people_and_one_person_cannot_fill_two_slots(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['required_approvals' => 2]]);
        $request = $this->submit();
        [$a, $b] = [$this->person(true), $this->person(true)];

        $request = $this->decide($request, $a);
        $this->assertSame('PENDING', $request->status->value);
        $this->refused(fn () => $this->decide($request, $a), 'already filled an approval slot');
        $request = $this->decide($request, $b);

        $this->assertSame('APPROVED', $request->status->value);
        app(RequireApproval::class)('TEST_DOCUMENT', 1);
    }

    public function test_stages_run_in_order_and_parallel_steps_in_a_stage_must_all_approve(): void
    {
        $this->mode(ApprovalMode::Policy);
        $policy = $this->policy([['permission' => 'test.document.approve']]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Finance', 'required_approvals' => 1, 'permission' => 'test.document.escalate']);
        $policy->steps()->create(['stage' => 2, 'name' => 'Director', 'required_approvals' => 1]);
        $request = $this->submit();
        $manager = $this->person(true);
        $finance = $this->person();
        $finance->givePermissionTo('test.document.escalate');
        $director = $this->person(true);

        $this->assertSame(2, $request->steps()->where('status', 'OPEN')->count());
        $this->assertSame(1, $request->steps()->where('status', 'WAITING')->count());
        $request = $this->decide($request, $manager);
        $this->assertSame(1, $request->steps()->where('status', 'WAITING')->count(), 'stage 2 waits for the whole of stage 1');
        $request = $this->decide($request, $finance);
        $this->assertSame('OPEN', $request->steps()->where('name', 'Director')->first()->status->value);
        $request = $this->decide($request, $director);

        $this->assertSame('APPROVED', $request->status->value);
    }

    public function test_the_submitter_cannot_satisfy_a_step_unless_that_step_allows_it(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['allow_submitter' => false]]);
        $this->submitter->givePermissionTo('test.document.approve');
        $request = $this->submit();

        $this->refused(fn () => $this->decide($request, $this->submitter), 'cannot approve this step');

        // A small company may choose otherwise, per step, deliberately.
        ApprovalPolicy::query()->first()->steps()->update(['allow_submitter' => true]);
        $this->subject->documents[3] = ['amount' => '10.0000', 'warehouse' => 'main'];
        $request = $this->submit(3);
        $this->assertSame('APPROVED', $this->decide($request, $this->submitter)->status->value);
    }

    public function test_authority_ceilings_decide_who_may_approve_which_amount(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['use_authority_limit' => true]]);
        $this->subject->documents[2] = ['amount' => '500000.0000', 'warehouse' => 'main'];
        [$junior, $senior, $unlimited, $none] = [$this->person(true), $this->person(true), $this->person(true), $this->person(true)];
        ApprovalAuthority::query()->create(['user_id' => $junior->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => false, 'ceiling_base_amount' => '100000', 'reason' => 't', 'changed_by' => $this->submitter->id]);
        ApprovalAuthority::query()->create(['user_id' => $senior->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => false, 'ceiling_base_amount' => '250000', 'reason' => 't', 'changed_by' => $this->submitter->id]);
        ApprovalAuthority::query()->create(['user_id' => $unlimited->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => true, 'reason' => 't', 'changed_by' => $this->submitter->id]);

        $small = $this->submit(1);
        $large = $this->submit(2);
        $this->refused(fn () => $this->decide($small, $none), 'not eligible');
        $this->refused(fn () => $this->decide($large, $junior), 'not eligible');
        $this->refused(fn () => $this->decide($large, $senior), 'not eligible');
        $this->assertSame('APPROVED', $this->decide($small, $junior)->status->value);
        $this->assertSame('APPROVED', $this->decide($large, $unlimited)->status->value);
        $snapshot = $large->decisions()->sole()->authority_snapshot;
        $this->assertTrue($snapshot['unlimited']);
    }

    public function test_a_delegate_acts_within_the_delegators_authority_and_never_adds_their_own(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['use_authority_limit' => true]]);
        $this->subject->documents[2] = ['amount' => '300000.0000', 'warehouse' => 'main'];
        [$ali, $ahmed] = [$this->person(true), $this->person(true)];
        $limit = fn (User $u, string $ceiling) => ApprovalAuthority::query()->create(['user_id' => $u->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => false, 'ceiling_base_amount' => $ceiling, 'reason' => 't', 'changed_by' => $this->submitter->id]);
        $limit($ali, '100000');
        ApprovalDelegation::query()->create(['delegator_id' => $ali->id, 'delegate_id' => $ahmed->id, 'valid_from' => today()->subDay(), 'valid_to' => today()->addDay(), 'reason' => 'leave', 'created_by' => $this->submitter->id]);

        // Ahmed has the permission but no ceiling of his own, and Ali's 100k does not reach 300k.
        $this->refused(fn () => $this->decide($this->submit(2), $ahmed), 'not eligible');

        // Within Ali's 100k Ahmed decides as Ali, and the decision records whose authority it was.
        $request = $this->decide($this->submit(1), $ahmed);
        $decision = $request->decisions()->sole();
        $this->assertSame('APPROVED', $request->status->value);
        $this->assertSame($ali->id, $decision->principal_id);
        $this->assertSame($ahmed->id, $decision->decided_by);
        $this->assertNotNull($decision->delegation_id);
    }

    public function test_delegation_cannot_let_one_person_fill_two_slots_of_a_step(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['required_approvals' => 2]]);
        [$ali, $bob] = [$this->person(true), $this->person(true)];
        $ahmed = $this->person();
        ApprovalDelegation::query()->create(['delegator_id' => $ali->id, 'delegate_id' => $ahmed->id, 'valid_from' => today(), 'valid_to' => today()->addDay(), 'reason' => 'leave', 'created_by' => $this->submitter->id]);

        // Ali approves personally; Ahmed can then only act as Ali, whose slot is already taken.
        $request = $this->decide($this->submit(), $ali);
        $this->refused(fn () => $this->decide($request, $ahmed), 'already filled an approval slot');

        // A different principal is a different slot.
        $this->assertSame('APPROVED', $this->decide($request, $bob)->status->value);

        // The other way round: Ahmed fills Ali's slot, so Ali personally cannot fill a second one.
        $this->subject->documents[2] = ['amount' => '1.0000', 'warehouse' => 'main'];
        $second = $this->decide($this->submit(2), $ahmed);
        $this->assertSame($ali->id, $second->decisions()->sole()->principal_id);
        $this->refused(fn () => $this->decide($second, $ali), 'already filled an approval slot');
        $this->refused(fn () => $this->decide($second, $ahmed), 'already filled an approval slot');
    }

    public function test_a_changed_document_cancels_its_approval_and_an_old_approval_cannot_be_reused(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['use_authority_limit' => false]]);
        $approver = $this->person(true);

        // Changed while waiting: the next decision cancels it rather than approving stale figures.
        $pending = $this->submit();
        $this->subject->documents[1]['amount'] = '800000.0000';
        $this->refused(fn () => $this->decide($pending, $approver), 'changed after it was submitted');
        $this->assertSame('INVALIDATED', $pending->fresh()->status->value);

        // Approved at 80,000, then edited to 800,000: the approval must not stand.
        $this->subject->documents[1]['amount'] = '80000.0000';
        $approved = $this->decide($this->submit(), $approver);
        $this->assertSame('APPROVED', $approved->status->value);
        app(RequireApproval::class)('TEST_DOCUMENT', 1);
        $this->subject->documents[1]['amount'] = '800000.0000';
        $this->refused(fn () => app(RequireApproval::class)('TEST_DOCUMENT', 1), 'needs approval first');
        $this->assertSame('INVALIDATED', $approved->fresh()->status->value);
    }

    public function test_a_rejection_needs_a_reason_and_ends_the_request_and_a_resubmission_is_a_new_request(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['use_authority_limit' => false]]);
        $request = $this->submit();
        $approver = $this->person(true);

        $this->refused(fn () => $this->decide($request, $approver, ApprovalDecisionKind::Rejected), 'needs a reason');
        $rejected = $this->decide($request, $approver, ApprovalDecisionKind::Rejected, 'Too expensive for this quarter.');
        $this->assertSame('REJECTED', $rejected->status->value);
        $this->assertSame(['CLOSED'], array_values(array_unique($rejected->steps()->where('status', '!=', 'REJECTED')->pluck('status')->map(fn ($s) => $s->value)->all())) ?: ['CLOSED']);
        $this->refused(fn () => app(RequireApproval::class)('TEST_DOCUMENT', 1), 'REJECTED');

        $again = $this->submit();
        $this->assertNotSame($request->id, $again->id);
        $this->assertSame('PENDING', $again->status->value);
    }

    public function test_only_the_submitter_or_a_manager_can_withdraw_and_only_while_pending(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['use_authority_limit' => false]]);
        $request = $this->submit();

        $this->refused(fn () => app(WithdrawApproval::class)($request, $this->person(true), 'x'), 'Only the person who submitted');
        $this->refused(fn () => app(WithdrawApproval::class)($request, $this->submitter, ' '), 'Say why');
        $withdrawn = app(WithdrawApproval::class)($request, $this->submitter, 'Needs editing.');
        $this->assertSame('WITHDRAWN', $withdrawn->status->value);
        $this->refused(fn () => app(WithdrawApproval::class)($withdrawn, $this->submitter, 'again'), 'already WITHDRAWN');
    }

    public function test_expiry_flags_overdue_and_widens_eligibility_but_never_decides(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['expires_after_hours' => 24, 'escalation_permission' => 'test.document.escalate']]);
        $request = $this->submit();
        $escalator = $this->person();
        $escalator->givePermissionTo('test.document.escalate');

        $this->refused(fn () => $this->decide($request, $escalator), 'not eligible');
        $this->travel(2)->days();
        $this->assertSame(['flagged' => 1, 'expired' => 0], app(ExpireOverdueApprovals::class)());
        $this->assertSame('PENDING', $request->fresh()->status->value, 'expiry never approves or rejects');

        $this->assertSame('APPROVED', $this->decide($request, $escalator)->status->value);
    }

    public function test_a_step_can_instead_end_the_request_as_expired(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['expires_after_hours' => 1, 'on_expiry' => 'EXPIRE_REQUEST']]);
        $request = $this->submit();

        $this->travel(3)->hours();
        $this->assertSame(['flagged' => 0, 'expired' => 1], app(ExpireOverdueApprovals::class)());
        $this->assertSame('EXPIRED', $request->fresh()->status->value);
        $this->refused(fn () => $this->decide($request, $this->person(true)), 'already EXPIRED');
    }

    public function test_decisions_are_append_only_facts(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->policy([['use_authority_limit' => false]]);
        $request = $this->decide($this->submit(), $this->person(true));
        $decision = $request->decisions()->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('approval_decisions')->where('id', $decision->id)->update(['reason' => 'edited']);
    }
}
