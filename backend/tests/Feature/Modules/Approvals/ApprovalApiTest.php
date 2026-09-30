<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeApprovalSubject;
use Tests\TestCase;

/** The approvals API: who may configure, who sees and decides what, and that the engine's rules hold through HTTP. */
class ApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeApprovalSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['test.document.approve', 'approvals.policy.manage', 'approvals.authority.manage', 'approvals.delegation.manage', 'approvals.settings.manage', 'approvals.history.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->subject = new FakeApprovalSubject;
        $this->subject->documents[1] = ['amount' => '80000.0000', 'warehouse' => 'main'];
        app(ApprovalSubjectRegistry::class)->register($this->subject);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** @return array<string, mixed> */
    private function policyPayload(array $overrides = []): array
    {
        return $overrides + [
            'subject_type' => 'TEST_DOCUMENT', 'code' => 'big-orders', 'name' => 'Big orders', 'priority' => 10, 'reason' => 'Company policy',
            'conditions' => [['fact' => 'amount_base', 'operator' => 'GTE', 'value' => '100000']],
            'steps' => [['name' => 'Manager', 'required_approvals' => 1], ['name' => 'Finance', 'required_approvals' => 2, 'use_authority_limit' => true]],
        ];
    }

    public function test_policies_are_managed_as_versions_and_only_by_those_who_may(): void
    {
        $this->actingAs($this->user())->postJson('/api/v1/approval-policies', $this->policyPayload())->assertForbidden();

        $admin = $this->user('approvals.policy.manage');
        $v1 = $this->actingAs($admin)->postJson('/api/v1/approval-policies', $this->policyPayload())->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.version_no', 1)->assertJsonCount(2, 'data.steps');
        $id = $v1->json('data.id');
        $this->postJson("/api/v1/approval-policies/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        // Active versions are history: edit by creating the next version, then activate it.
        $this->patchJson("/api/v1/approval-policies/{$id}", ['name' => 'Renamed'])->assertUnprocessable();
        $this->deleteJson("/api/v1/approval-policies/{$id}")->assertUnprocessable();
        $v2 = $this->postJson('/api/v1/approval-policies', $this->policyPayload(['name' => 'Big orders v2']))->assertCreated()->assertJsonPath('data.version_no', 2);
        $this->patchJson('/api/v1/approval-policies/'.$v2->json('data.id'), ['steps' => [['name' => 'Director', 'required_approvals' => 3]]])->assertOk()->assertJsonPath('data.steps.0.required_approvals', 3);
        $this->postJson('/api/v1/approval-policies/'.$v2->json('data.id').'/activate')->assertOk();
        $this->assertSame(['SUPERSEDED', 'ACTIVE'], ApprovalPolicy::query()->orderBy('version_no')->get()->map(fn ($p) => $p->status->value)->all());
        $this->deleteJson('/api/v1/approval-policies/'.$v2->json('data.id'))->assertUnprocessable();
    }

    public function test_a_policy_can_only_use_facts_and_comparisons_the_subject_declares(): void
    {
        $admin = $this->user('approvals.policy.manage');
        $bad = fn (array $condition) => $this->actingAs($admin)->postJson('/api/v1/approval-policies', $this->policyPayload(['conditions' => [$condition]]));

        $bad(['fact' => 'colour', 'operator' => 'EQ', 'value' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('conditions.0.fact');
        $bad(['fact' => 'warehouse', 'operator' => 'GTE', 'value' => 'main'])->assertUnprocessable()->assertJsonValidationErrors('conditions.0.operator');
        $bad(['fact' => 'amount_base', 'operator' => 'GTE', 'value' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $bad(['fact' => 'warehouse', 'operator' => 'IN', 'value' => 'main'])->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $bad(['fact' => 'warehouse', 'operator' => 'IN', 'value' => ['main', 'khi']])->assertCreated();

        $this->postJson('/api/v1/approval-policies', $this->policyPayload(['code' => 'x', 'steps' => [['name' => 'Escalates', 'required_approvals' => 1, 'escalation_permission' => 'test.document.approve']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('steps.0.escalation_permission');
        $this->postJson('/api/v1/approval-policies', $this->policyPayload(['code' => 'y', 'steps' => [['name' => 'None', 'required_approvals' => 0]]]))->assertUnprocessable();
    }

    public function test_approval_mode_is_set_per_subject_type_by_those_who_may(): void
    {
        $this->actingAs($this->user('approvals.policy.manage'))->putJson('/api/v1/approval-subject-types/TEST_DOCUMENT/mode', ['mode' => 'REQUIRED'])->assertForbidden();

        $this->actingAs($this->user('approvals.settings.manage'))->putJson('/api/v1/approval-subject-types/TEST_DOCUMENT/mode', ['mode' => 'REQUIRED'])
            ->assertOk()->assertJsonPath('data.mode', 'REQUIRED');
        $this->putJson('/api/v1/approval-subject-types/TEST_DOCUMENT/mode', ['mode' => 'SOMETIMES'])->assertUnprocessable();
        $this->putJson('/api/v1/approval-subject-types/NOPE/mode', ['mode' => 'POLICY'])->assertUnprocessable();

        $types = $this->actingAs($this->user('approvals.history.view'))->getJson('/api/v1/approval-subject-types')->assertOk()->json('data.0');
        $this->assertSame('TEST_DOCUMENT', $types['code']);
        $this->assertSame('REQUIRED', $types['mode']);
        $this->assertEqualsCanonicalizing(['GTE', 'GT', 'LTE', 'LT', 'EQ', 'NEQ', 'IN'], array_column($types['facts'][0]['operators'], 'value'));
        $this->assertEqualsCanonicalizing(['EQ', 'NEQ', 'IN'], array_column($types['facts'][1]['operators'], 'value'));
        $this->actingAs($this->user())->getJson('/api/v1/approval-subject-types')->assertForbidden();
    }

    public function test_authorities_are_ceilings_held_as_data_and_revocable(): void
    {
        $approver = $this->user('test.document.approve');
        $manager = $this->user('approvals.authority.manage');
        $payload = ['user_id' => $approver->id, 'subject_type' => 'TEST_DOCUMENT', 'ceiling_base_amount' => '100000', 'reason' => 'Purchasing manager'];

        $this->actingAs($this->user())->postJson('/api/v1/approval-authorities', $payload)->assertForbidden();
        $id = $this->actingAs($manager)->postJson('/api/v1/approval-authorities', $payload)->assertCreated()->assertJsonPath('data.in_force', true)->json('data.id');
        $this->postJson('/api/v1/approval-authorities', $payload + ['unlimited' => true])->assertUnprocessable();
        $this->postJson('/api/v1/approval-authorities', ['unlimited' => true] + $payload)->assertUnprocessable();

        $this->actingAs($approver)->getJson('/api/v1/approval-authorities/mine')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user())->getJson('/api/v1/approval-authorities/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->deleteJson("/api/v1/approval-authorities/{$id}")->assertOk()->assertJsonPath('data.in_force', false);
    }

    public function test_anyone_can_delegate_their_own_authority_but_not_someone_elses(): void
    {
        [$ali, $ahmed, $stranger] = [$this->user('test.document.approve'), $this->user(), $this->user()];
        $payload = ['delegate_id' => $ahmed->id, 'valid_from' => today()->toDateString(), 'valid_to' => today()->addDays(5)->toDateString(), 'reason' => 'Leave'];

        $id = $this->actingAs($ali)->postJson('/api/v1/approval-delegations', $payload)->assertCreated()->assertJsonPath('data.delegator_id', $ali->id)->json('data.id');
        $this->actingAs($ali)->postJson('/api/v1/approval-delegations', $payload + ['delegator_id' => $stranger->id])->assertForbidden();
        $this->actingAs($ali)->postJson('/api/v1/approval-delegations', ['delegate_id' => $ali->id] + $payload)->assertUnprocessable();

        $this->actingAs($ahmed)->getJson('/api/v1/approval-delegations')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($stranger)->getJson('/api/v1/approval-delegations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->deleteJson("/api/v1/approval-delegations/{$id}")->assertForbidden();
        $this->actingAs($ali)->deleteJson("/api/v1/approval-delegations/{$id}")->assertOk()->assertJsonPath('data.in_force', false);
    }

    public function test_the_inbox_the_decision_and_who_may_see_a_request_through_http(): void
    {
        ApprovalSubjectSetting::query()->create(['subject_type' => 'TEST_DOCUMENT', 'mode' => ApprovalMode::Policy]);
        $admin = $this->user('approvals.policy.manage');
        $policy = $this->actingAs($admin)->postJson('/api/v1/approval-policies', $this->policyPayload(['conditions' => [], 'steps' => [['name' => 'Manager', 'required_approvals' => 1]]]))->json('data.id');
        $this->postJson("/api/v1/approval-policies/{$policy}/activate")->assertOk();
        $submitter = $this->user();
        $request = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $submitter);
        [$approver, $bystander] = [$this->user('test.document.approve'), $this->user()];

        $this->actingAs($bystander)->getJson('/api/v1/approval-requests/inbox')->assertOk()->assertJsonCount(0, 'data');
        $inbox = $this->actingAs($approver)->getJson('/api/v1/approval-requests/inbox')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertTrue($inbox['can_decide']);
        $this->assertSame('T-1', $inbox['summary']['number']);
        $this->assertSame('80000.0000', $inbox['amount_base']);
        $this->assertSame('manual', $inbox['evidence']['fx_rate_source']);

        $this->actingAs($bystander)->getJson("/api/v1/approval-requests/{$request->id}")->assertForbidden();
        $this->actingAs($submitter)->getJson("/api/v1/approval-requests/{$request->id}")->assertOk();
        $this->actingAs($bystander)->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED'])->assertUnprocessable();
        $this->actingAs($approver)->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'REJECTED'])->assertUnprocessable();
        $this->postJson("/api/v1/approval-requests/{$request->id}/decide", ['decision' => 'APPROVED', 'reason' => 'ok'])->assertOk()->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.steps.0.decisions.0.decided_by.id', $approver->id);
        $this->getJson('/api/v1/approval-requests/inbox')->assertJsonCount(0, 'data');
        // A decider keeps sight of what they decided.
        $this->getJson("/api/v1/approval-requests/{$request->id}")->assertOk();

        $this->actingAs($this->user())->getJson('/api/v1/approval-requests')->assertForbidden();
        $this->actingAs($this->user('approvals.history.view'))->getJson('/api/v1/approval-requests?filter[status]=APPROVED')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_pending_request_can_be_withdrawn_by_its_submitter_through_http(): void
    {
        ApprovalSubjectSetting::query()->create(['subject_type' => 'TEST_DOCUMENT', 'mode' => ApprovalMode::Policy]);
        $this->actingAs($admin = $this->user('approvals.policy.manage'));
        $policy = $this->postJson('/api/v1/approval-policies', $this->policyPayload(['conditions' => [], 'steps' => [['name' => 'Manager', 'required_approvals' => 1]]]))->json('data.id');
        $this->postJson("/api/v1/approval-policies/{$policy}/activate");
        $submitter = $this->user();
        $request = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $submitter);

        $this->actingAs($this->user())->postJson("/api/v1/approval-requests/{$request->id}/withdraw", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($submitter)->postJson("/api/v1/approval-requests/{$request->id}/withdraw", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/approval-requests/{$request->id}/withdraw", ['reason' => 'Needs editing'])->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->assertSame('WITHDRAWN', ApprovalRequest::query()->findOrFail($request->id)->status->value);
        $this->assertNotNull($admin);
    }
}
