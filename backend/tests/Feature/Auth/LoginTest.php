<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // /login, /forgot-password and /reset-password all share one 5/min throttle
        // bucket keyed by IP — real login-attempt tests would otherwise start tripping
        // it once enough of them run in the same process. The throttle's presence is
        // still verified below, just via route introspection instead of firing requests.
        //
        // CSRF is real Sanctum/Laravel behaviour (a real SPA fetches /sanctum/csrf-cookie
        // first), not something this app's code implements — skip re-simulating that
        // handshake here so these tests stay focused on our login/session logic.
        $this->withoutMiddleware([ThrottleRequests::class, ValidateCsrfToken::class]);
    }

    /**
     * A request carrying a stateful-domain Referer, like a real SPA request — needed
     * whenever the assertion cares about the session Sanctum's stateful middleware
     * establishes (it only runs for requests it recognises as frontend-originated).
     */
    private function postAsFrontend(string $uri, array $data = []): TestResponse
    {
        return $this->withHeader('Referer', 'http://localhost:5176')->postJson($uri, $data);
    }

    public function test_a_user_can_log_in_with_valid_credentials_via_session_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postAsFrontend('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_a_422_for_wrong_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_login_with_a_device_name_issues_a_bearer_token_instead_of_a_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => "Kaleem's iPhone",
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
        $this->assertNotEmpty($response->json('meta.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        // No Referer header was sent above, so this request was never treated as
        // frontend-originated — if the controller touched $request->session() in the
        // device_name branch (it shouldn't), this test would 500 rather than pass.
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = User::factory()->create([
            'email' => 'someone@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postAsFrontend('/api/v1/login', [
            'email' => 'SOMEONE@EXAMPLE.COM',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_new_user_is_auto_scoped_to_company_one(): void
    {
        $user = User::factory()->create();

        $this->assertSame(1, $user->company_id);
    }

    public function test_login_route_is_rate_limited_to_five_per_minute(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->getName() === 'login');

        $this->assertNotNull($route);
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }
}
