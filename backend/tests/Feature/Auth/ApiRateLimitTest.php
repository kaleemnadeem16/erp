<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The authenticated API limit is per user and read from `config/api.php`, so production keeps 120/min while a scripted
 * end-to-end run on the dev stack can raise it (docs/architecture/api-conventions.md).
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_limit_is_the_documented_120_per_minute(): void
    {
        // The environment may raise it (the dev stack does), so the default itself is pinned where it is declared.
        $this->assertStringContainsString("env('API_RATE_LIMIT_PER_MINUTE', 120)", file_get_contents(config_path('api.php')));
    }

    public function test_requests_beyond_the_configured_limit_are_refused_per_user(): void
    {
        config(['api.rate_limit_per_minute' => 3]);
        RateLimiter::clear('api');
        $busy = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 3) as $_) {
            $this->actingAs($busy)->getJson('/api/v1/user')->assertOk();
        }
        $this->actingAs($busy)->getJson('/api/v1/user')->assertStatus(429);
        // Another person is not held back by the first one's requests.
        $this->actingAs($other)->getJson('/api/v1/user')->assertOk();
    }
}
