<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_out_ends_the_session(): void
    {
        $user = User::factory()->create();

        // Sanctum only treats a request as stateful (session-based) when it looks like
        // it came from a SANCTUM_STATEFUL_DOMAINS origin — without this header it skips
        // starting a session at all, and Auth::guard('web')->logout() has nothing to end.
        // CSRF is skipped for the same reason as LoginTest — real Sanctum behaviour, not
        // this app's code, and simulating the full /sanctum/csrf-cookie handshake here
        // wouldn't test anything of ours.
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($user)
            ->withHeader('Referer', 'http://localhost:5176')
            ->postJson('/api/v1/logout');

        $response->assertNoContent();
        // Explicitly 'web': the auth:sanctum middleware makes 'sanctum' the default
        // guard for the rest of *this* request, and Sanctum's guard caches the user it
        // already resolved before our code ran — checking the unqualified default here
        // would assert against that same-request cache, not the session our code
        // actually ends. A genuinely later request re-resolves from the (now-cleared)
        // session correctly; see LogoutTest's other tests for the real-world effect
        // (Sanctum tokens) of this same distinction.
        $this->assertGuest('web');
    }

    public function test_logging_out_with_a_bearer_token_revokes_only_that_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device-a');
        $otherToken = $user->createToken('device-b');

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->postJson('/api/v1/logout');

        $response->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/logout');

        $response->assertUnauthorized();
    }
}
