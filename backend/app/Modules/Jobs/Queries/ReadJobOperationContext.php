<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\JobsException;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReadJobOperationContext
{
    public function __construct(private readonly JobExecutionContextVerifier $verifier) {}

    public function read(?User $actor, int $jobId, string $operation): JobOperationContextProjection
    {
        $duties = JobLifecycleOperation::DUTIES[$operation] ?? [];
        if (! JobAccess::mayAll($actor, ...$duties)) {
            throw new AuthorizationException('You are not permitted to use this Job operation.');
        }

        return DB::transaction(function () use ($actor, $jobId, $operation, $duties): JobOperationContextProjection {
            app(CompanyAccessFence::class)->lock();
            $this->requireAuthority($actor, $duties);
            $company = app(CompanyContext::class)->id();
            $freshActor = User::query()->where('company_id', $company)->findOrFail($actor->id);
            // Locator only, never locked: the existing source boundary owns the source fences before any Job lock.
            $job = ServiceJob::query()->where('company_id', $company)->find($jobId) ?? $this->notFound();
            try {
                $context = $this->verifier->enter($freshActor, $job, $operation);
            } catch (DomainInvariantException $exception) {
                // These are the owning boundary's known record refusals. Infrastructure and unexpected domain errors propagate.
                if (in_array($exception->reasonCode, ['SOURCE_CONTEXT_UNAVAILABLE', 'JOB_NOT_FOUND'], true)) {
                    $this->notFound();
                }
                throw $exception;
            }
            $this->requireAuthority($actor, $duties);
            if (! $context->recordEligible) {
                $this->notFound();
            }
            // One SQL snapshot pins head and children together without a Job lock. Never reproduce command readiness here.
            $row = DB::table('service_jobs as j')->where('j.company_id', $company)->where('j.id', $jobId)
                ->select(['j.id', 'j.code', 'j.state', 'j.generation'])
                ->selectRaw('(SELECT a.id FROM job_assignment_events a JOIN job_events e ON e.company_id=a.company_id AND e.job_id=a.job_id AND e.id=a.event_id
                    WHERE a.company_id=j.company_id AND a.job_id=j.id AND e.next_generation<=j.generation ORDER BY a.sequence DESC LIMIT 1) AS assignment_id')
                ->selectRaw("(SELECT COALESCE(jsonb_agg(jsonb_build_object('id',h.id,'reason',h.reason,'recorded_at',e.recorded_at) ORDER BY e.sequence),'[]'::jsonb)
                    FROM job_holds h JOIN job_events e ON e.company_id=h.company_id AND e.job_id=h.job_id AND e.id=h.event_id
                    WHERE h.company_id=j.company_id AND h.job_id=j.id AND e.next_generation<=j.generation AND NOT EXISTS
                    (SELECT 1 FROM job_hold_resolutions r JOIN job_events re ON re.company_id=r.company_id AND re.job_id=r.job_id AND re.id=r.event_id
                    WHERE r.company_id=h.company_id AND r.job_id=h.job_id AND r.hold_id=h.id AND re.next_generation<=j.generation)) AS open_holds")
                ->selectRaw('(SELECT e.recorded_at FROM job_completions c JOIN job_events e ON e.company_id=c.company_id AND e.job_id=c.job_id AND e.id=c.event_id
                    WHERE c.company_id=j.company_id AND c.job_id=j.id AND e.next_generation<=j.generation) AS completion_recorded_at')->first() ?? $this->notFound();
            $holds = json_decode($row->open_holds, true, flags: JSON_THROW_ON_ERROR);
            foreach ($holds as &$hold) {
                $hold['recorded_at'] = Carbon::parse($hold['recorded_at'])->utc()->format('Y-m-d\TH:i:s\Z');
            }
            unset($hold);
            $fields = ['job_id' => (int) $row->id, 'number' => $row->code, 'state' => $row->state, 'generation' => (int) $row->generation, 'operation' => $operation];
            if ($operation === 'ASSIGN') {
                $fields['responsibility_id'] = $row->assignment_id === null ? null : (int) $row->assignment_id;
                $fields['has_responsibility'] = $row->assignment_id !== null;
            }
            if ($operation === 'START') {
                $fields['has_responsibility'] = $row->assignment_id !== null;
            }
            if (in_array($operation, ['START', 'COMPLETE', 'ASSESS_CLOSEOUT', 'CLOSE'], true)) {
                $fields['open_hold_count'] = count($holds);
            }
            if ($operation === 'RESOLVE_HOLD') {
                $fields['open_holds'] = $holds;
            }
            if (in_array($operation, ['ASSESS_CLOSEOUT', 'CLOSE'], true)) {
                $fields['completion_recorded_at'] = $row->completion_recorded_at === null ? null : Carbon::parse($row->completion_recorded_at)->utc()->format('Y-m-d\TH:i:s\Z');
            }
            $this->requireAuthority($actor, $duties);

            return new JobOperationContextProjection($fields);
        });
    }

    /** @param list<string> $duties */
    private function requireAuthority(?User $actor, array $duties): void
    {
        if (! JobAccess::mayAll($actor, ...$duties)) {
            throw new AuthorizationException('You are not permitted to use this Job operation.');
        }
    }

    private function notFound(): never
    {
        throw new JobsException('The Job was not found.', 'job_id', 'JOB_NOT_FOUND');
    }
}
