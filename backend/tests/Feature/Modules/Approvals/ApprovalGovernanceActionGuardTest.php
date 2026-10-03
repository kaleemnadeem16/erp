<?php

namespace Tests\Feature\Modules\Approvals;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\CreateApprovalAuthority;
use App\Modules\Approvals\Actions\CreateApprovalDelegation;
use App\Modules\Approvals\Actions\CreateApprovalPolicy;
use App\Modules\Approvals\Actions\DeleteApprovalPolicy;
use App\Modules\Approvals\Actions\RevokeApprovalAuthority;
use App\Modules\Approvals\Actions\RevokeApprovalDelegation;
use App\Modules\Approvals\Actions\SaveApprovalSubjectSetting;
use App\Modules\Approvals\Actions\UpdateApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeApprovalSubject;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * The approval-governance Actions refuse a caller without the capability even when nothing HTTP stands in front of them
 * (docs/architecture/access-security-audit.md, Action-level guards): a console command, job or integration gets the same answer a
 * request does. Each Action is tried as a user with no capability, then with the right one.
 */
class ApprovalGovernanceActionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $subject = new FakeApprovalSubject;
        $subject->documents[1] = ['amount' => '1000.0000', 'warehouse' => 'main'];
        app(ApprovalSubjectRegistry::class)->register($subject);
    }

    private function user(string ...$permissions): User
    {
        return PermissionedActor::grant(User::factory()->create(), $permissions);
    }

    private function refused(callable $call): void
    {
        try {
            $call();
            $this->fail('The Action ran for a caller without the capability.');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('not permitted', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function policyInput(string $code = 'p1'): array
    {
        return ['subject_type' => 'TEST_DOCUMENT', 'code' => $code, 'name' => 'Policy', 'priority' => 10, 'reason' => 'Test', 'conditions' => [], 'steps' => [['name' => 'Manager', 'required_approvals' => 1]]];
    }

    public function test_policy_actions_need_the_policy_capability(): void
    {
        $nobody = $this->user();
        $manager = $this->user('approvals.policy.manage');

        $this->refused(fn () => app(CreateApprovalPolicy::class)($this->policyInput(), $nobody));
        $this->assertSame(0, ApprovalPolicy::query()->count());

        $policy = app(CreateApprovalPolicy::class)($this->policyInput(), $manager);
        $this->refused(fn () => app(UpdateApprovalPolicy::class)($policy, ['name' => 'Renamed', 'reason' => 'x'] + $this->policyInput(), $nobody));
        $this->refused(fn () => app(ActivateApprovalPolicy::class)($policy, $nobody));
        $this->refused(fn () => app(DeleteApprovalPolicy::class)($policy, $nobody));
        $this->assertSame('Policy', $policy->fresh()->name);
        $this->assertSame('DRAFT', $policy->fresh()->status->value);

        app(ActivateApprovalPolicy::class)($policy, $manager);
        $this->assertSame('ACTIVE', $policy->fresh()->status->value);
    }

    public function test_the_approval_mode_needs_the_settings_capability(): void
    {
        $this->refused(fn () => app(SaveApprovalSubjectSetting::class)('TEST_DOCUMENT', ApprovalMode::Required, $this->user('approvals.policy.manage')));
        app(SaveApprovalSubjectSetting::class)('TEST_DOCUMENT', ApprovalMode::Required, $this->user('approvals.settings.manage'));
    }

    public function test_authority_actions_need_the_authority_capability(): void
    {
        $holder = $this->user();
        $input = ['user_id' => $holder->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => true, 'ceiling_base_amount' => null, 'reason' => 'Test', 'valid_from' => null, 'valid_to' => null];

        $this->refused(fn () => app(CreateApprovalAuthority::class)($input, $this->user('approvals.policy.manage')));
        $this->assertSame(0, ApprovalAuthority::query()->count());

        $manager = $this->user('approvals.authority.manage');
        $authority = app(CreateApprovalAuthority::class)($input, $manager);
        $this->refused(fn () => app(RevokeApprovalAuthority::class)($authority, $this->user()));
        $this->assertNull($authority->fresh()->revoked_at);
        app(RevokeApprovalAuthority::class)($authority, $manager);
        $this->assertNotNull($authority->fresh()->revoked_at);
    }

    public function test_anyone_may_delegate_or_withdraw_their_own_authority_but_not_someone_elses(): void
    {
        [$ali, $ahmed, $stranger, $admin] = [$this->user(), $this->user(), $this->user(), $this->user('approvals.delegation.manage')];
        $input = ['delegator_id' => $ali->id, 'delegate_id' => $ahmed->id, 'valid_from' => today()->toDateString(), 'valid_to' => today()->addDays(3)->toDateString(), 'reason' => 'Leave'];

        $this->refused(fn () => app(CreateApprovalDelegation::class)($input, $stranger));
        $delegation = app(CreateApprovalDelegation::class)($input, $ali);
        $this->refused(fn () => app(RevokeApprovalDelegation::class)($delegation, $stranger));
        $this->assertNull($delegation->fresh()->revoked_at);

        app(RevokeApprovalDelegation::class)($delegation, $ali);
        $this->assertNotNull($delegation->fresh()->revoked_at);
        // A delegation manager can act for others.
        $again = app(CreateApprovalDelegation::class)($input, $admin);
        app(RevokeApprovalDelegation::class)($again, $admin);
    }
}
