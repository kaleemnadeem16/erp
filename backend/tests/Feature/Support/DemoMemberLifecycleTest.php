<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Administration\Actions\IssueTemporaryMemberPassword;
use App\Modules\Administration\Support\MemberStatus;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class DemoMemberLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function persona(): User
    {
        return (new class
        {
            use EnsuresDemoEnvironment;

            public function member(): User
            {
                return $this->demoMember('fixture-approver@example.com', 'Fixture approver');
            }
        })->member();
    }

    public function test_new_personas_are_ready_and_reruns_preserve_a_password_changed_through_self_service(): void
    {
        $member = $this->persona();
        $this->assertSame(MemberStatus::Active, $member->status);
        $this->assertFalse($member->must_change_password);
        $this->assertTrue(Hash::check(config('demo.user.password'), $member->password));
        $this->assertSame([], $member->getAllPermissions()->all());
        $this->actingAs($member)->patchJson('/api/v1/account/password', [
            'current_password' => config('demo.user.password'),
            'password' => 'Changed-fixture-password-194!', 'password_confirmation' => 'Changed-fixture-password-194!',
        ])->assertNoContent();
        $again = $this->persona();
        $this->assertSame($member->id, $again->id);
        $this->assertTrue(Hash::check('Changed-fixture-password-194!', $again->password));
        $this->assertSame(1, $again->credential_version);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_demo_setup_does_not_bypass_temporary_credentials_issued_through_administration(): void
    {
        $member = $this->persona();
        $administrator = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage', 'administration.access.assign']);
        app(IssueTemporaryMemberPassword::class)($member, $administrator);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('normal activation/password flow');
        $this->persona();
    }

    public function test_demo_setup_does_not_reactivate_a_suspended_persona(): void
    {
        $member = $this->persona();
        $member->update(['status' => MemberStatus::Suspended]);
        try {
            $this->persona();
            $this->fail('Demo setup reactivated a suspended user.');
        } catch (RuntimeException $exception) {
            $this->assertSame(MemberStatus::Suspended, $member->fresh()->status);
            $this->assertStringContainsString('normal activation/password flow', $exception->getMessage());
        }
    }
}
