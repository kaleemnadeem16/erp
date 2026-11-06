<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeApprovalSubject;
use Tests\TestCase;

/**
 * A record the caller may not see answers exactly like an id that does not exist (issue #89 F2): same status, same body
 * shape, same headers, on every record-addressed approvals route. 403 is kept only for a missing capability, judged
 * before the record is looked up.
 */
class ApprovalNonDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = 9999999;

    private int $requestId;

    private User $submitter;

    private User $bystander;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['test.document.approve', 'approvals.policy.manage', 'approvals.authority.manage', 'approvals.delegation.manage', 'approvals.history.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $subject = new FakeApprovalSubject;
        $subject->documents[1] = ['amount' => '80000.0000', 'warehouse' => 'main'];
        app(ApprovalSubjectRegistry::class)->register($subject);
        ApprovalSubjectSetting::query()->create(['subject_type' => 'TEST_DOCUMENT', 'mode' => ApprovalMode::Policy]);
        $admin = $this->user('approvals.policy.manage');
        $this->actingAs($admin);
        $policy = $this->postJson('/api/v1/approval-policies', [
            'subject_type' => 'TEST_DOCUMENT', 'code' => 'all', 'name' => 'All', 'priority' => 10, 'reason' => 'x', 'conditions' => [], 'steps' => [['name' => 'Manager', 'required_approvals' => 1]],
        ])->json('data.id');
        $this->postJson("/api/v1/approval-policies/{$policy}/activate")->assertOk();
        $this->submitter = $this->user();
        $this->bystander = $this->user();
        $this->requestId = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $this->submitter)->id;
        // Production bodies carry no stack trace; the debug trace would differ line by line between two code paths.
        config(['app.debug' => false]);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} the status, the body with the id removed, and the headers that matter */
    private function shape(TestResponse $response, int $id): array
    {
        return [
            $response->getStatusCode(),
            str_replace((string) $id, '{id}', $response->getContent()),
            ['content-type' => (string) $response->headers->get('Content-Type'), 'cache-control' => (string) $response->headers->get('Cache-Control')],
        ];
    }

    /** @param  callable(int): TestResponse  $call */
    private function assertIndistinguishable(callable $call, int $hiddenId, int $expectedStatus = 404): void
    {
        $hidden = $this->shape($call($hiddenId), $hiddenId);
        $missing = $this->shape($call(self::MISSING), self::MISSING);
        $this->assertSame($expectedStatus, $hidden[0]);
        $this->assertSame($missing, $hidden, 'A hidden record must answer exactly like an unknown id.');
    }

    public function test_detail_decide_and_withdraw_of_a_hidden_request_match_an_unknown_id(): void
    {
        $this->actingAs($this->bystander);

        $this->assertIndistinguishable(fn (int $id) => $this->getJson("/api/v1/approval-requests/{$id}"), $this->requestId);
        $this->assertIndistinguishable(fn (int $id) => $this->postJson("/api/v1/approval-requests/{$id}/decide", ['decision' => 'APPROVED', 'reason' => 'ok']), $this->requestId);
        $this->assertIndistinguishable(fn (int $id) => $this->postJson("/api/v1/approval-requests/{$id}/withdraw", ['reason' => 'no']), $this->requestId);
        // An invalid body must not tell them apart either: visibility is judged before validation.
        $this->assertIndistinguishable(fn (int $id) => $this->postJson("/api/v1/approval-requests/{$id}/decide", []), $this->requestId);
        $this->assertIndistinguishable(fn (int $id) => $this->postJson("/api/v1/approval-requests/{$id}/withdraw", []), $this->requestId);
        $this->assertSame('PENDING', ApprovalRequest::query()->findOrFail($this->requestId)->status->value);
    }

    public function test_people_who_can_see_the_request_still_reach_it(): void
    {
        $this->actingAs($this->submitter)->getJson("/api/v1/approval-requests/{$this->requestId}")->assertOk();
        $this->actingAs($this->user('approvals.history.view'))->getJson("/api/v1/approval-requests/{$this->requestId}")->assertOk();
        $this->actingAs($this->user('approvals.policy.manage'))->postJson("/api/v1/approval-requests/{$this->requestId}/withdraw", ['reason' => 'manager'])->assertOk();
    }

    public function test_a_delegation_nobody_in_it_may_see_answers_like_an_unknown_id(): void
    {
        [$ali, $ahmed] = [$this->user(), $this->user()];
        $delegation = ApprovalDelegation::query()->create(['delegator_id' => $ali->id, 'delegate_id' => $ahmed->id, 'valid_from' => today(), 'valid_to' => today()->addDay(), 'reason' => 'x', 'created_by' => $ali->id]);

        $this->actingAs($this->bystander);
        $this->assertIndistinguishable(fn (int $id) => $this->deleteJson("/api/v1/approval-delegations/{$id}"), $delegation->id);
        // The delegate can see it, so a refusal to revoke is an honest 403, not a 404.
        $this->actingAs($ahmed)->deleteJson("/api/v1/approval-delegations/{$delegation->id}")->assertForbidden();
    }

    public function test_a_missing_capability_is_refused_the_same_for_a_real_and_an_unknown_record(): void
    {
        $policy = ApprovalPolicy::query()->firstOrFail();
        $authority = ApprovalAuthority::query()->create(['user_id' => $this->submitter->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => true, 'reason' => 'x', 'changed_by' => $this->submitter->id]);
        $this->actingAs($this->bystander);

        $this->assertIndistinguishable(fn (int $id) => $this->deleteJson("/api/v1/approval-authorities/{$id}"), $authority->id, 403);
        $this->assertIndistinguishable(fn (int $id) => $this->deleteJson("/api/v1/approval-policies/{$id}"), $policy->id, 403);
        $this->assertIndistinguishable(fn (int $id) => $this->postJson("/api/v1/approval-policies/{$id}/activate"), $policy->id, 403);
        $this->assertIndistinguishable(fn (int $id) => $this->patchJson("/api/v1/approval-policies/{$id}", ['name' => 'x']), $policy->id, 403);
        $this->assertIndistinguishable(fn (int $id) => $this->getJson("/api/v1/approval-policies/{$id}"), $policy->id, 403);
    }
}
