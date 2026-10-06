<?php

namespace Tests\Feature\Modules\Approvals;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\DeleteApprovalPolicy;
use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Approvals\Actions\OpenApprovalStage;
use App\Modules\Approvals\Actions\RevokeApprovalAuthority;
use App\Modules\Approvals\Actions\RevokeApprovalDelegation;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Actions\UpdateApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeApprovalSubject;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class ApprovalInformationFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeApprovalSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new FakeApprovalSubject;
        $this->subject->documents[1] = ['amount' => '80000.0000', 'warehouse' => 'main'];
        app(ApprovalSubjectRegistry::class)->register($this->subject);
    }

    private function policy(User $actor): ApprovalPolicy
    {
        ApprovalSubjectSetting::create(['subject_type' => 'TEST_DOCUMENT', 'mode' => 'POLICY']);
        $policy = ApprovalPolicy::create(['subject_type' => 'TEST_DOCUMENT', 'code' => 'audit', 'version_no' => 1, 'name' => 'Audit policy', 'status' => 'ACTIVE', 'reason' => 'test', 'changed_by' => $actor->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Review', 'required_approvals' => 1, 'expires_after_hours' => 1, 'on_expiry' => 'EXPIRE_REQUEST']);

        return $policy;
    }

    public function test_ineligible_decisions_do_not_inspect_or_cancel_changed_or_expired_requests(): void
    {
        $submitter = User::factory()->create();
        $this->policy($submitter);
        $request = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $submitter);
        $bystander = User::factory()->create();
        $this->subject->documents[1]['amount'] = '95000.0000';
        $this->travel(2)->hours();
        $response = $this->actingAs($bystander)->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED']);
        $response->assertUnprocessable()->assertJsonPath('message', 'You are not eligible to decide any open step of this approval.');
        $this->assertStringNotContainsString('changed after', $response->getContent());
        $this->assertSame('PENDING', $request->fresh()->status->value);
        $this->assertNull($request->fresh()->closing_reason);
        $this->assertDatabaseCount('approval_decisions', 0);
        // An eligible actor still cancels stale evidence before refusing to decide.
        $approver = PermissionedActor::grant(User::factory()->create(), ['test.document.approve']);
        $this->actingAs($approver)->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED'])->assertUnprocessable();
        $this->assertSame('INVALIDATED', $request->fresh()->status->value);
    }

    public function test_delegation_lists_expose_only_involved_records_unless_the_caller_manages_delegations(): void
    {
        [$delegator, $delegate, $unrelated] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $manager = PermissionedActor::grant(User::factory()->create(), ['approvals.delegation.manage']);
        $delegation = ApprovalDelegation::create([
            'delegator_id' => $delegator->id, 'delegate_id' => $delegate->id,
            'valid_from' => today(), 'valid_to' => today()->addDays(1),
            'reason' => 'Private cover for colleagues', 'created_by' => $delegator->id,
        ]);
        foreach ([$delegator, $delegate, $manager] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/v1/approval-delegations')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $delegation->id)
                ->assertJsonPath('data.0.delegator_name', $delegator->name)
                ->assertJsonPath('data.0.delegate_name', $delegate->name)
                ->assertJsonPath('data.0.reason', 'Private cover for colleagues');
        }
        $response = $this->actingAs($unrelated)->getJson('/api/v1/approval-delegations')->assertOk()->assertExactJson(['data' => []]);
        foreach (['Private cover for colleagues', $delegator->name, $delegate->name] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_a_delegate_cannot_use_authority_of_a_suspended_invited_or_temporary_password_principal(): void
    {
        $submitter = User::factory()->create();
        $this->policy($submitter);
        $request = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $submitter);
        $principal = PermissionedActor::grant(User::factory()->create(), ['test.document.approve']);
        $delegate = User::factory()->create();
        ApprovalDelegation::create([
            'delegator_id' => $principal->id, 'delegate_id' => $delegate->id,
            'valid_from' => today(), 'valid_to' => today()->addDays(1),
            'reason' => 'Leave cover', 'created_by' => $principal->id,
        ]);
        $this->actingAs($delegate)->getJson('/api/v1/approval-requests/inbox')->assertOk()->assertJsonCount(1, 'data');
        foreach ([['SUSPENDED', false], ['INVITED', false], ['ACTIVE', true]] as [$status, $mustChangePassword]) {
            $principal->forceFill(['status' => $status, 'must_change_password' => $mustChangePassword])->save();
            $this->getJson('/api/v1/approval-requests/inbox')->assertOk()->assertExactJson(['data' => []]);
            $this->getJson("/api/v1/approval-requests/{$request->id}")->assertForbidden()->assertJsonMissingPath('data.amount_base');
            $this->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED'])
                ->assertUnprocessable()->assertJsonPath('message', 'You are not eligible to decide any open step of this approval.');
            $this->assertSame('PENDING', $request->fresh()->status->value);
            $this->assertDatabaseCount('approval_decisions', 0);
        }
        $principal->forceFill(['status' => 'ACTIVE', 'must_change_password' => false])->save();
        foreach ([['SUSPENDED', false], ['INVITED', false], ['ACTIVE', true]] as [$status, $mustChangePassword]) {
            $delegate->forceFill(['status' => $status, 'must_change_password' => $mustChangePassword])->save();
            try {
                app(DecideApproval::class)($request, $delegate->fresh(), ApprovalDecisionKind::Approved);
                $this->fail('An inactive direct Action caller was allowed to use delegated authority.');
            } catch (DomainInvariantException $exception) {
                $this->assertSame('You are not eligible to decide any open step of this approval.', $exception->getMessage());
                $this->assertDatabaseCount('approval_decisions', 0);
            }
        }
        $delegate->forceFill(['status' => 'ACTIVE', 'must_change_password' => false])->save();
        $this->actingAs($delegate)->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.steps.0.decisions.0.acting_for', $principal->id);
    }

    public function test_foreign_approval_responses_and_governance_actions_are_unreachable(): void
    {
        $admin = PermissionedActor::grant(User::factory()->create(), ['approvals.policy.manage', 'approvals.authority.manage', 'approvals.delegation.manage', 'approvals.history.view']);
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        $original = app(CompanyContext::class);
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $foreign = User::factory()->create();
        $policy = $this->policy($foreign);
        $request = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $foreign);
        $draft = ApprovalPolicy::create(['subject_type' => 'TEST_DOCUMENT', 'code' => 'foreign-draft', 'version_no' => 1, 'name' => 'Foreign draft', 'status' => 'DRAFT', 'reason' => 'test', 'changed_by' => $foreign->id]);
        $authority = ApprovalAuthority::create(['user_id' => $foreign->id, 'subject_type' => 'TEST_DOCUMENT', 'ceiling_base_amount' => '987654.0000', 'reason' => 'foreign-ceiling', 'changed_by' => $foreign->id]);
        $delegate = User::factory()->create();
        $delegation = ApprovalDelegation::create(['delegator_id' => $foreign->id, 'delegate_id' => $delegate->id, 'valid_from' => today(), 'valid_to' => today()->addDays(1), 'reason' => 'foreign-delegation', 'created_by' => $foreign->id]);
        app()->instance(CompanyContext::class, $original);
        foreach (['approval-policies', 'approval-requests', 'approval-authorities', 'approval-delegations', 'approval-authorities/mine', 'approval-requests/inbox'] as $path) {
            $response = $this->actingAs($admin)->getJson("/api/v1/{$path}")->assertOk()->assertJsonCount(0, 'data');
            foreach (['foreign-ceiling', 'foreign-delegation', '987654.0000'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->getJson("/api/v1/approval-policies/{$policy->id}")->assertNotFound();
        $this->getJson("/api/v1/approval-requests/{$request->id}")->assertNotFound();
        $this->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED'])->assertNotFound();
        $this->postJson("/api/v1/approval-requests/{$request->id}/withdraw", ['reason' => 'test'])->assertNotFound();
        $operations = [
            fn () => app(InvalidateApprovals::class)($request, 'foreign'),
            fn () => app(OpenApprovalStage::class)($request, 1),
            fn () => app(UpdateApprovalPolicy::class)($draft, ['name' => 'Changed'], $admin),
            fn () => app(DeleteApprovalPolicy::class)($draft, $admin),
            fn () => app(ActivateApprovalPolicy::class)($draft, $admin),
            fn () => app(RevokeApprovalAuthority::class)($authority, $admin),
            fn () => app(RevokeApprovalDelegation::class)($delegation, $admin),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A hydrated foreign approval target was accepted.');
            } catch (ModelNotFoundException) {
                $this->assertSame('Foreign draft', DB::table('approval_policies')->where('id', $draft->id)->value('name'));
                $this->assertNull(DB::table('approval_authorities')->where('id', $authority->id)->value('revoked_at'));
                $this->assertNull(DB::table('approval_delegations')->where('id', $delegation->id)->value('revoked_at'));
            }
        }
    }
}
