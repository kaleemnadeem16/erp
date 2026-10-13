<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/** The request timing log (docs/architecture/observability.md): one structured line per request, no data leakage. */
final class RequestPerformanceLogTest extends TestCase
{
    private TestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.enabled' => true, 'performance.slow_query_ms' => 0.0, 'logging.channels.perf' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel('perf');
        /** @var TestHandler $handler */
        $handler = Log::channel('perf')->getLogger()->getHandlers()[0];
        $this->handler = $handler;
    }

    public function test_a_request_is_logged_with_route_status_duration_and_database_timing(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/user')->assertOk();

        $line = collect($this->handler->getRecords())->first(fn ($record) => $record->message === 'http_request');
        $this->assertNotNull($line, 'no http_request line was logged');
        $this->assertSame(200, $line->context['status']);
        $this->assertSame('GET', $line->context['method']);
        $this->assertStringContainsString('api/v1/user', $line->context['route']);
        $this->assertIsFloat($line->context['duration_ms']);
        $this->assertGreaterThan(0, $line->context['queries']);
        $this->assertArrayHasKey('db_ms', $line->context);
        $this->assertArrayHasKey('memory_mb', $line->context);
    }

    public function test_slow_queries_are_logged_with_their_sql_but_never_their_bindings(): void
    {
        $user = User::factory()->create(['email' => 'private-person@example.test']);
        $this->actingAs($user);
        $this->getJson('/api/v1/user')->assertOk();

        $slow = collect($this->handler->getRecords())->filter(fn ($record) => $record->message === 'slow_query');
        $this->assertNotEmpty($slow, 'the threshold of 0 ms should log every query');
        foreach ($slow as $record) {
            $this->assertArrayHasKey('sql', $record->context);
            $this->assertStringNotContainsString('private-person@example.test', json_encode($record->context));
        }
    }

    public function test_health_checks_and_a_disabled_flag_log_nothing(): void
    {
        $this->get('/up')->assertOk();
        $this->assertSame([], $this->handler->getRecords());

        config(['performance.enabled' => false]);
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->assertSame([], $this->handler->getRecords());
    }

    public function test_an_unauthenticated_request_is_still_logged_with_its_status(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();

        $line = collect($this->handler->getRecords())->first(fn ($record) => $record->message === 'http_request');
        $this->assertNotNull($line);
        $this->assertSame(401, $line->context['status']);
        $this->assertNull($line->context['user_id']);
    }
}
