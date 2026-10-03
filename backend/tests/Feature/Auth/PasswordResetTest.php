<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Administration\Notifications\MemberPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See LoginTest::setUp() — these routes share a throttle bucket with /login.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_forgot_password_sends_a_reset_notification_for_a_known_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/forgot-password', ['email' => $user->email]);

        $response->assertOk();
        Notification::assertSentTo($user, MemberPasswordReset::class);
    }

    public function test_forgot_password_answers_identically_for_an_unknown_email_so_accounts_cannot_be_discovered(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com']);

        $unknown->assertOk();
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentToTimes($user, MemberPasswordReset::class, 1);
        Notification::assertCount(1);
    }

    public function test_a_repeated_request_that_the_broker_throttles_does_not_reveal_that_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $first = $this->postJson('/api/v1/forgot-password', ['email' => $user->email]);
        $second = $this->postJson('/api/v1/forgot-password', ['email' => $user->email]); // the broker throttles this one
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($first->json(), $second->json());
        $this->assertSame($first->json(), $unknown->json());
    }

    public function test_reset_password_with_a_valid_token_changes_the_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
    }

    public function test_reset_link_replaces_a_temporary_password_and_revokes_old_tokens(): void
    {
        $user = User::factory()->create(['password' => 'temporary-password']);
        $user->forceFill(['must_change_password' => true, 'credential_version' => 3])->save();
        $oldToken = $user->createToken('old device');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'new-private-password', 'password_confirmation' => 'new-private-password',
        ])->assertOk();

        $this->assertFalse($user->refresh()->must_change_password);
        $this->assertSame(4, $user->credential_version);
        $this->assertTrue(Hash::check('new-private-password', $user->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken->accessToken->id]);
    }

    public function test_reset_password_with_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password));
    }
}
