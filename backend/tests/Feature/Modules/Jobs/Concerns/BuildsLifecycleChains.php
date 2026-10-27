<?php

namespace Tests\Feature\Modules\Jobs\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raw-SQL builders for coherent Job lifecycle chains (J1-F1). Each step writes the event, its own child, its receipt and the head advance together, so a test
 * that wants an incoherent chain overrides one column or skips one write instead of rebuilding everything.
 */
trait BuildsLifecycleChains
{
    /** The connection the builders write through; a test that needs a second session overrides this. */
    protected function conn(): Connection
    {
        return DB::connection();
    }

    protected function at(int $minutes): string
    {
        return Carbon::parse('2026-10-27 09:00:00', 'UTC')->addMinutes($minutes)->format('Y-m-d H:i:s').'+00';
    }

    protected function actorOf(int $company): int
    {
        return $company === 1 ? $this->user->id : $this->otherUser;
    }

    /** A Job head (RELEASED, generation 1) and its initial RELEASE event, the only coherent start. */
    protected function newJob(string $code = 'JOB-1', int $company = 1): int
    {
        $job = $this->conn()->table('service_jobs')->insertGetId(['company_id' => $company, 'code' => $code, 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->conn()->table('job_events')->insert(['company_id' => $company, 'job_id' => $job, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED',
            'prior_generation' => 0, 'next_generation' => 1, 'occurrence_precision' => 'EXACT', 'recorded_at' => $this->at(0), 'actor_id' => $this->actorOf($company), 'evidence_version' => 1]);

        return $job;
    }

    protected function latest(int $job): object
    {
        return $this->conn()->table('job_events')->where('job_id', $job)->orderByDesc('sequence')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $over
     */
    protected function appendEvent(int $job, string $operation, array $over = []): int
    {
        $last = $this->latest($job);

        return $this->conn()->table('job_events')->insertGetId(['company_id' => $last->company_id, 'job_id' => $job, 'sequence' => $last->sequence + 1, 'operation' => $operation,
            'from_state' => $last->to_state, 'to_state' => $operation === 'START' ? 'ACTIVE' : $last->to_state, 'prior_generation' => $last->next_generation,
            'next_generation' => $last->next_generation + 1, 'occurrence_precision' => 'EXACT', 'recorded_at' => $this->at($last->sequence + 1), 'actor_id' => $this->actorOf($last->company_id),
            'reason' => 'because', 'evidence_version' => 1, 'command_identity' => (string) Str::uuid(), 'semantic_intent_hash' => hash('sha256', (string) Str::uuid()), ...$over]);
    }

    protected function advanceHead(int $job): void
    {
        $last = $this->latest($job);
        $this->conn()->table('service_jobs')->where('id', $job)->update(['state' => $last->to_state, 'generation' => $last->next_generation]);
    }

    protected function receipt(int $job, int $eventId, ?string $key = null, ?int $actor = null): void
    {
        $event = $this->conn()->table('job_events')->find($eventId);
        $this->conn()->table('job_operation_receipts')->insert(['company_id' => $event->company_id, 'job_id' => $job, 'actor_id' => $actor ?? $event->actor_id, 'request_key' => $key ?? 'key-'.Str::uuid(),
            'operation' => $event->operation, 'intent_hash' => hash('sha256', (string) Str::uuid()), 'result_event_id' => $eventId, 'contract_version' => 1, 'recorded_at' => $event->recorded_at]);
    }

    /**
     * @param  array<string, mixed>  $eventOver
     * @param  array<string, mixed>  $childOver
     */
    protected function assign(int $job, int $responsibleUser, array $eventOver = [], array $childOver = []): int
    {
        $eventId = $this->appendEvent($job, 'ASSIGN', $eventOver);
        $event = $this->conn()->table('job_events')->find($eventId);
        $prev = $this->conn()->table('job_assignment_events')->where('job_id', $job)->orderByDesc('sequence')->first();
        $this->conn()->table('job_assignment_events')->insert(['company_id' => $event->company_id, 'job_id' => $job, 'event_id' => $eventId, 'sequence' => $prev ? $prev->sequence + 1 : 1,
            'predecessor_id' => $prev?->id, 'responsible_user_id' => $responsibleUser, 'actor_id' => $event->actor_id, 'occurred_at' => $event->occurred_at,
            'occurrence_precision' => $event->occurrence_precision, 'reason' => $event->reason, 'recorded_at' => $event->recorded_at, ...$childOver]);
        $this->receipt($job, $eventId);
        $this->advanceHead($job);

        return $eventId;
    }

    /**
     * @param  array<string, mixed>  $eventOver
     * @param  array<string, mixed>  $childOver
     * @return array{event: int, hold: int}
     */
    protected function placeHold(int $job, array $eventOver = [], array $childOver = []): array
    {
        $eventId = $this->appendEvent($job, 'HOLD', $eventOver);
        $event = $this->conn()->table('job_events')->find($eventId);
        $holdId = $this->conn()->table('job_holds')->insertGetId(['company_id' => $event->company_id, 'job_id' => $job, 'event_id' => $eventId, 'scope_kind' => 'JOB', 'reason' => $event->reason,
            'effective_from' => $event->recorded_at, 'actor_id' => $event->actor_id, 'recorded_at' => $event->recorded_at, ...$childOver]);
        $this->receipt($job, $eventId);
        $this->advanceHead($job);

        return ['event' => $eventId, 'hold' => $holdId];
    }

    /**
     * @param  array<string, mixed>  $eventOver
     * @param  array<string, mixed>  $childOver
     */
    protected function resolveHold(int $job, int $holdId, array $eventOver = [], array $childOver = []): int
    {
        $eventId = $this->appendEvent($job, 'RESOLVE_HOLD', $eventOver);
        $event = $this->conn()->table('job_events')->find($eventId);
        $this->conn()->table('job_hold_resolutions')->insert(['company_id' => $event->company_id, 'job_id' => $job, 'event_id' => $eventId, 'hold_id' => $holdId, 'resolved_effective_at' => $event->recorded_at,
            'actor_id' => $event->actor_id, 'reason' => $event->reason, 'recorded_at' => $event->recorded_at, ...$childOver]);
        $this->receipt($job, $eventId);
        $this->advanceHead($job);

        return $eventId;
    }

    protected function start(int $job): int
    {
        $eventId = $this->appendEvent($job, 'START');
        $this->receipt($job, $eventId);
        $this->advanceHead($job);

        return $eventId;
    }
}
