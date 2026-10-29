<?php

namespace Tests\Unit\Modules\Jobs;

use App\Exceptions\DomainInvariantException;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** The canonical v1 intent: what is part of the business effect, what is transport, and that the hashes are stable and strict. Pure: no database. */
class JobLifecycleIntentTest extends TestCase
{
    private const UUID = '6f1d2c3e-1111-4222-8333-444455556666';

    private function intent(array $over = []): JobLifecycleIntent
    {
        $args = ['operation' => 'ASSIGN', 'jobId' => 7, 'commandIdentity' => self::UUID, 'reason' => 'Because', 'targets' => ['assignee_id' => 3, 'instructions' => null],
            'expected' => ['generation' => 2, 'open_holds' => []], 'occurredAt' => null, 'occurrencePrecision' => 'NONE', ...$over];

        return new JobLifecycleIntent(...$args);
    }

    public function test_the_semantic_hash_is_stable_across_key_order_and_names_every_business_input(): void
    {
        $a = $this->intent(['targets' => ['assignee_id' => 3, 'instructions' => null], 'expected' => ['generation' => 2, 'open_holds' => []]]);
        $b = $this->intent(['targets' => ['instructions' => null, 'assignee_id' => 3], 'expected' => ['open_holds' => [], 'generation' => 2]]);
        $this->assertSame($a->semanticHash(), $b->semanticHash());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->semanticHash());
        $this->assertSame(1, JobLifecycleIntent::CONTRACT_VERSION);

        $base = $a->semanticHash();
        foreach ([['operation' => 'START'], ['jobId' => 8], ['commandIdentity' => '7f1d2c3e-1111-4222-8333-444455556666'], ['reason' => 'Other'], ['reason' => null],
            ['targets' => ['assignee_id' => 4, 'instructions' => null]], ['targets' => ['assignee_id' => 3, 'instructions' => 'Take over']],
            ['expected' => ['generation' => 3, 'open_holds' => []]], ['expected' => ['generation' => 2, 'open_holds' => [9]]], ['expected' => ['generation' => 2, 'open_holds' => [], 'baseline_version' => 1]],
            ['occurredAt' => Carbon::parse('2026-10-28T10:00:00Z')], ['occurrencePrecision' => 'EXACT']] as $change) {
            $this->assertNotSame($base, $this->intent($change)->semanticHash(), json_encode($change));
        }
    }

    public function test_the_request_hash_adds_actor_and_key_and_the_semantic_hash_does_not(): void
    {
        $intent = $this->intent();
        $this->assertNotSame($intent->requestHash(1, 'k'), $intent->requestHash(2, 'k'));
        $this->assertNotSame($intent->requestHash(1, 'k'), $intent->requestHash(1, 'other'));
        $this->assertSame($intent->requestHash(1, 'k'), $intent->requestHash(1, 'k'));
        $this->assertNotSame($intent->semanticHash(), $intent->requestHash(1, 'k'));
        // Whitespace around the reason is not a different command; a time in another zone for the same instant is not either.
        $this->assertSame($this->intent(['reason' => 'Because'])->semanticHash(), $this->intent(['reason' => '  Because  '])->semanticHash());
        $this->assertSame($this->intent(['occurredAt' => Carbon::parse('2026-10-28T10:00:00Z')])->semanticHash(), $this->intent(['occurredAt' => Carbon::parse('2026-10-28T15:00:00+05:00')])->semanticHash());
    }

    public function test_invalid_intents_are_refused(): void
    {
        foreach ([['commandIdentity' => 'not-a-uuid'], ['commandIdentity' => strtoupper(self::UUID)], ['expected' => []], ['expected' => ['generation' => 0]], ['expected' => ['generation' => '2']],
            ['reason' => '   '], ['reason' => str_repeat('x', 1001)], ['occurrencePrecision' => ' '], ['occurrencePrecision' => str_repeat('x', 21)]] as $bad) {
            try {
                $this->intent($bad);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (DomainInvariantException $e) {
                $this->assertSame('INVALID_INPUT', $e->reasonCode, json_encode($bad));
            }
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', JobLifecycleIntent::newCommandIdentity());
    }

    public function test_the_outcome_exposes_only_safe_references(): void
    {
        $names = array_map(fn ($p) => $p->getName(), (new \ReflectionClass(JobLifecycleOutcome::class))->getProperties());
        $this->assertSame(['jobId', 'eventId', 'sequence', 'operation', 'state', 'generation', 'replayed'], $names, 'no hash, payload, key, source context or user detail');
    }
}
