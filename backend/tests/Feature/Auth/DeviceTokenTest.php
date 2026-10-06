<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Auth\SignInChallenge;
use App\Support\Auth\SignInRequirement;
use App\Support\Auth\SignInRequirements;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR 0088 (Q36): a bearer token expires (30 days, a hard ceiling that also covers any token issued without its own expiry), the person can
 * see and sign out their own devices and only theirs, and sign-in has a seam where two-factor or step-up checks plug in later without
 * changing it. The seam is proved with a stand-in requirement, since none exists yet.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The login throttle is a route-level control tested elsewhere (LoginTest); sign-in is exercised many times here.
        $this->withoutMiddleware([ThrottleRequests::class, ValidateCsrfToken::class]);
    }

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make('correct-password')]);
    }

    /** @return array{0: string, 1: PersonalAccessToken} the plain token and its row */
    private function signInOnADevice(User $user, string $device = 'Pixel 8'): array
    {
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password', 'device_name' => $device])->assertOk();
        $plain = $response->json('meta.token');
        // A real device has no session cookie. In one test process the web guard would stay logged in from this sign-in and authenticate
        // later requests by itself, so end it: from here on only the bearer token can authenticate.
        Auth::guard('web')->logout();
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();

        return [$plain, PersonalAccessToken::findToken($plain)];
    }

    private function bearer(string $plain): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    public function test_a_device_token_expires_in_thirty_days_and_records_how_it_signed_in(): void
    {
        [, $token] = $this->signInOnADevice($this->user());

        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $token->expires_at->timestamp, 5);
        $this->assertSame(['password'], $token->meta['auth_methods']);
        $this->assertNotNull($token->meta['authenticated_at']);
        $this->assertArrayHasKey('user_agent', $token->meta);
    }

    public function test_the_token_works_until_it_expires_and_then_is_refused(): void
    {
        [$plain] = $this->signInOnADevice($this->user());

        $this->travel(29)->days();
        $this->bearer($plain)->getJson('/api/v1/user')->assertOk();

        $this->travel(2)->days();
        $this->bearer($plain)->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_a_token_issued_without_its_own_expiry_is_still_cut_off_by_the_ceiling(): void
    {
        $user = $this->user();
        $plain = $user->createToken('Legacy token')->plainTextToken;
        $this->assertNull(PersonalAccessToken::findToken($plain)->expires_at);

        $this->travel(29)->days();
        $this->bearer($plain)->getJson('/api/v1/user')->assertOk();
        $this->travel(2)->days();
        $this->bearer($plain)->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_a_configured_lifetime_can_be_shorter_but_never_longer_than_the_ceiling(): void
    {
        config(['signin.device_token_lifetime_minutes' => 60]);
        [, $short] = $this->signInOnADevice($this->user());
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $short->expires_at->timestamp, 5);

        config(['signin.device_token_lifetime_minutes' => 10 * 43200]);
        [, $long] = $this->signInOnADevice($this->user());
        $this->assertEqualsWithDelta(now()->addMinutes(43200)->timestamp, $long->expires_at->timestamp, 5, 'clamped to the ceiling');
    }

    public function test_a_person_sees_only_their_own_live_devices_without_any_secret(): void
    {
        $me = $this->user();
        $someoneElse = $this->user();
        [$mine] = $this->signInOnADevice($me, 'Pixel 8');
        $this->signInOnADevice($me, 'Old tablet');
        $this->signInOnADevice($someoneElse, 'Their phone');
        $me->tokens()->where('name', 'Old tablet')->update(['expires_at' => now()->subDay()]);

        $response = $this->bearer($mine)->getJson('/api/v1/account/devices')->assertOk();

        $this->assertSame(['Pixel 8'], $response->json('data.*.name'), 'only mine, and the expired one is gone');
        $this->assertTrue($response->json('data.0.is_current'));
        $this->assertSame(['password'], $response->json('data.0.auth_methods'));
        foreach (['token', $mine, hash('sha256', explode('|', $mine, 2)[1]), 'Their phone'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson('/api/v1/account/devices')->assertUnauthorized();
    }

    public function test_a_person_can_sign_out_one_of_their_devices_and_not_someone_elses(): void
    {
        $me = $this->user();
        $other = $this->user();
        [$phone, $phoneToken] = $this->signInOnADevice($me, 'Phone');
        [$tablet, $tabletToken] = $this->signInOnADevice($me, 'Tablet');
        [$theirs, $theirToken] = $this->signInOnADevice($other, 'Their phone');

        // Signing the tablet out from the phone ends the tablet, not the phone.
        $this->bearer($phone)->deleteJson("/api/v1/account/devices/{$tabletToken->id}")->assertNoContent();
        $this->bearer($tablet)->getJson('/api/v1/user')->assertUnauthorized();
        $this->bearer($phone)->getJson('/api/v1/user')->assertOk();

        // Someone else's device is a 404 (it is not even confirmed to exist), and keeps working.
        $this->bearer($phone)->deleteJson("/api/v1/account/devices/{$theirToken->id}")->assertNotFound();
        $this->bearer($theirs)->getJson('/api/v1/user')->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $theirToken->id]);
    }

    public function test_signing_in_with_a_device_can_be_switched_off_without_touching_the_browser_sign_in(): void
    {
        config(['signin.bearer_sign_in_enabled' => false]);
        $user = $this->user();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password', 'device_name' => 'Pixel 8'])
            ->assertUnprocessable()->assertJsonValidationErrors('device_name');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeader('Referer', 'http://localhost:5176')->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password'])->assertOk();
    }

    public function test_sign_in_asks_every_registered_requirement_before_it_issues_anything(): void
    {
        $user = $this->user();
        $lastLogin = $user->fresh()->last_login_at;
        app(SignInRequirements::class)->register(new class implements SignInRequirement
        {
            public function challenge(User $user, Request $request): ?SignInChallenge
            {
                return new SignInChallenge('totp', 'Enter the code from your authenticator app.', ['digits' => 6]);
            }
        });

        foreach ([['device_name' => 'Pixel 8'], []] as $extra) {
            $response = $this->withHeader('Referer', 'http://localhost:5176')
                ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password'] + $extra);
            $response->assertUnauthorized()->assertJsonPath('challenge.type', 'totp')->assertJsonPath('challenge.data.digits', 6);
            $this->assertStringNotContainsString('"token"', $response->getContent());
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse(Auth::guard('web')->check(), 'no session was left behind');
        $this->assertEquals($lastLogin, $user->fresh()->last_login_at, 'a challenged attempt is not recorded as a login');
    }

    public function test_a_satisfied_requirement_lets_sign_in_through_and_is_told_nothing_it_should_not(): void
    {
        $user = $this->user();
        app(SignInRequirements::class)->register(new class implements SignInRequirement
        {
            public function challenge(User $user, Request $request): ?SignInChallenge
            {
                return null;
            }
        });

        [, $token] = $this->signInOnADevice($user);
        $this->assertNotNull($token);
    }

    public function test_expired_tokens_are_pruned_on_a_schedule_as_housekeeping(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

        $this->assertStringContainsString('sanctum:prune-expired', $commands);
    }
}
