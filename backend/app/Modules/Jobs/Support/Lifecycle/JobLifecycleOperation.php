<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobHold;
use App\Modules\Jobs\Models\JobHoldResolution;
use App\Modules\Jobs\Models\JobOperationReceipt;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Jobs\Support\JobAccess;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one transactional orchestration every Job lifecycle command shares, so the four Actions differ only in their duties, preconditions and child row.
 *
 * Order: (1) authority — the exact duties, each by its own ALL scope, for a currently active member, before the Job is looked up; (2) the Job, company-scoped, with one
 * non-disclosing refusal for absent or foreign; (3) REPLAY before any freshness: the actor's own key resolves its original event (a different request under it
 * conflicts), and a stable command identity already applied resolves the same event for any key or actor when the semantic intent is identical (another actor gets
 * only its own receipt, never a new event) — a head that has moved on never invalidates a valid replay; (4) NEW work only: the source verifier is entered FIRST (source
 * fences before the Job lock, same transaction), then the Job head is locked, the declared expected versions are compared, the operation's preconditions run, and the
 * event, its exact child, the head advance and the actor's receipt are written together. The deferred database checks run at the real COMMIT; this class never returns
 * durable success before it, and inside a caller's outer transaction it cannot be retried (a failed outer transaction is aborted), so it refuses instead.
 */
final class JobLifecycleOperation
{
    public const ASSIGN = 'ASSIGN';

    public const START = 'START';

    public const HOLD = 'HOLD';

    public const RESOLVE_HOLD = 'RESOLVE_HOLD';

    private const ATTEMPTS = 3;

    public function __construct(private readonly JobExecutionContextVerifier $verifier, private readonly CompanyContext $company) {}

    /**
     * @param  list<string>  $duties  every one must pass independently
     * @param  string|null  $toState  the state the command moves the Job to, or null to retain its current state
     * @param  Closure(JobSnapshot, JobExecutionContext): void  $precheck  operation preconditions under the Job lock; throws a refusal
     * @param  Closure(JobEvent, JobSnapshot): void  $writeChild  writes the exact child row of the appended event
     */
    public function run(User $actor, array $duties, JobLifecycleIntent $intent, string $requestKey, ?string $toState, Closure $precheck, Closure $writeChild): JobLifecycleOutcome
    {
        if (! JobAccess::mayAll($actor, ...$duties)) {
            throw new AuthorizationException('You are not permitted to change this Job.');
        }
        $requestKey = trim($requestKey);
        if ($requestKey === '' || mb_strlen($requestKey) > 120) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::REQUEST_KEY_REQUIRED, 'A request key of at most 120 characters is required so that retrying cannot repeat the change.', 'request_key');
        }
        $job = ServiceJob::query()->find($intent->jobId) ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_NOT_FOUND, 'The Job was not found.', 'job_id');

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): JobLifecycleOutcome => $this->attempt($actor, $job, $intent, $requestKey, $toState, $precheck, $writeChild));
            } catch (UniqueConstraintViolationException|QueryException $e) {
                // A lost race on a unique key, a deadlock or a serialisation failure: restart the WHOLE operation (authority, replay, fences, locks, checks) — never
                // continue in an aborted transaction. Only possible when this is the outermost transaction; inside a caller's it is refused for the caller to retry.
                if (! $this->retryable($e)) {
                    throw $e;
                }
                if (DB::transactionLevel() > 0 || $attempt >= self::ATTEMPTS) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::CONCURRENT_CHANGE, 'The Job changed at the same time; retry the request.', 'job_id');
                }
            }
        }
    }

    private function retryable(Throwable $e): bool
    {
        $state = $e instanceof QueryException ? (string) ($e->errorInfo[0] ?? '') : '';

        return $e instanceof UniqueConstraintViolationException || in_array($state, ['40P01', '40001'], true);
    }

    private function attempt(User $actor, ServiceJob $job, JobLifecycleIntent $intent, string $requestKey, ?string $toState, Closure $precheck, Closure $writeChild): JobLifecycleOutcome
    {
        $requestHash = $intent->requestHash($actor->id, $requestKey);
        $semanticHash = $intent->semanticHash();

        if (($replay = $this->replay($actor, $job, $intent, $requestKey, $requestHash, $semanticHash)) !== null) {
            return $replay;
        }

        // New work. Source fences first, on this connection and inside this transaction, before the Job row is held.
        $context = $this->verifier->enter($actor, $job, $intent->operation);
        if (! $context->recordEligible) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_NOT_FOUND, 'The Job was not found.', 'job_id');
        }
        $locked = ServiceJob::query()->whereKey($job->id)->lockForUpdate()->first() ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_NOT_FOUND, 'The Job was not found.', 'job_id');
        // The head lock is the serialisation point: a competing identical request may have committed while we waited for it, and a valid replay must win over a
        // freshness check that its own success has just made stale. Looked up again, after the lock, in a new statement.
        if (($replay = $this->replay($actor, $job, $intent, $requestKey, $requestHash, $semanticHash)) !== null) {
            return $replay;
        }
        $snapshot = $this->snapshot($locked);

        $this->assertExpected($intent, $snapshot, $context);
        $precheck($snapshot, $context);

        $latest = $snapshot->latestEvent;
        $generation = $locked->generation + 1;
        // START moves RELEASED to ACTIVE; every other command retains the state the Job is in (the database refuses anything else).
        $toState ??= $locked->state;
        $event = JobEvent::query()->create([
            'job_id' => $locked->id, 'sequence' => $latest->sequence + 1, 'operation' => $intent->operation, 'from_state' => $locked->state, 'to_state' => $toState,
            'prior_generation' => $locked->generation, 'next_generation' => $generation, 'occurred_at' => $intent->occurredAt, 'occurrence_precision' => $intent->occurrencePrecision,
            'actor_id' => $actor->id, 'reason' => $intent->reason === null ? null : trim($intent->reason), 'evidence_version' => 1,
            'command_identity' => $intent->commandIdentity, 'semantic_intent_hash' => $semanticHash,
        ])->refresh();
        $writeChild($event, $snapshot);
        $locked->forceFill(['state' => $toState, 'generation' => $generation])->save();
        $this->receipt($actor, $requestKey, $intent, $requestHash, $event);

        return $this->outcome($event, false);
    }

    /**
     * The original outcome when this actor's key, or this stable command, was already applied; a different request under either is refused. It reads the database (and
     * may write the actor's receipt), so it answers differently before and after the Job lock.
     *
     * @phpstan-impure
     */
    private function replay(User $actor, ServiceJob $job, JobLifecycleIntent $intent, string $requestKey, string $requestHash, string $semanticHash): ?JobLifecycleOutcome
    {
        $receipt = JobOperationReceipt::query()->where(['actor_id' => $actor->id, 'request_key' => $requestKey])->first();
        if ($receipt !== null) {
            if ($receipt->intent_hash !== $requestHash) {
                throw JobLifecycleRefusal::make(JobLifecycleRefusal::REQUEST_KEY_REUSED, 'That request key was already used for a different request.', 'request_key');
            }

            return $this->outcome(JobEvent::query()->findOrFail($receipt->result_event_id), true);
        }
        $applied = JobEvent::query()->where(['job_id' => $job->id, 'command_identity' => $intent->commandIdentity])->first();
        if ($applied !== null) {
            if ($applied->semantic_intent_hash !== $semanticHash) {
                throw JobLifecycleRefusal::make(JobLifecycleRefusal::COMMAND_CONFLICT, 'That command was already applied with a different intent.', 'command_identity');
            }
            $this->receipt($actor, $requestKey, $intent, $requestHash, $applied);

            return $this->outcome($applied, true);
        }

        return null;
    }

    private function assertExpected(JobLifecycleIntent $intent, JobSnapshot $snapshot, JobExecutionContext $context): void
    {
        $expected = $intent->expected;
        if ($snapshot->job->generation !== $expected['generation']) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::STALE_GENERATION, "The Job is at generation {$snapshot->job->generation}, not {$expected['generation']}; reload it before changing it.", 'expected_generation');
        }
        $vector = ['baseline_version' => $snapshot->baselineVersion, 'assignment_sequence' => $snapshot->assignmentSequence, 'open_holds' => $snapshot->openHoldIds];
        foreach ($vector as $part => $actual) {
            if (array_key_exists($part, $expected) && $expected[$part] !== null && $expected[$part] !== $actual) {
                throw JobLifecycleRefusal::make(JobLifecycleRefusal::STALE_VERSION_VECTOR, "The Job's {$part} is not the one the request was based on; reload it before changing it.", $part);
            }
        }
        if (array_key_exists('source_vector', $expected) && $expected['source_vector'] !== null && $expected['source_vector'] !== $context->sourceVector) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::STALE_SOURCE_CONTEXT, 'The Job source context is not the one the request was based on; reload it before changing it.', 'source_vector');
        }
    }

    private function snapshot(ServiceJob $job): JobSnapshot
    {
        $latest = JobEvent::query()->where('job_id', $job->id)->orderByDesc('sequence')->firstOrFail();
        $assignment = DB::table('job_assignment_events')->where('company_id', $this->company->id())->where('job_id', $job->id)->orderByDesc('sequence')->first(['id', 'sequence']);
        $baseline = DB::table('job_baselines')->where('company_id', $this->company->id())->where('job_id', $job->id)->max('version');
        $open = JobHold::query()->where('job_id', $job->id)
            ->whereNotIn('id', JobHoldResolution::query()->where('job_id', $job->id)->select('hold_id'))->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return new JobSnapshot($job, $latest, (int) ($baseline ?? 0), $assignment === null ? 0 : (int) $assignment->sequence, $assignment === null ? null : (int) $assignment->id, $open);
    }

    private function receipt(User $actor, string $requestKey, JobLifecycleIntent $intent, string $requestHash, JobEvent $event): void
    {
        JobOperationReceipt::query()->create([
            'job_id' => $event->job_id, 'actor_id' => $actor->id, 'request_key' => $requestKey, 'operation' => $intent->operation, 'intent_hash' => $requestHash,
            'result_event_id' => $event->id, 'contract_version' => JobLifecycleIntent::CONTRACT_VERSION,
        ]);
    }

    private function outcome(JobEvent $event, bool $replayed): JobLifecycleOutcome
    {
        return new JobLifecycleOutcome($event->job_id, $event->id, $event->sequence, $event->operation, $event->to_state, $event->next_generation, $replayed);
    }

    /** Active, same-company member: responsibility can only be given to someone who can currently hold it. */
    public static function eligibleAssignee(int $userId): User
    {
        $user = User::query()->where('company_id', app(CompanyContext::class)->id())->whereKey($userId)->first();
        if ($user === null || $user->status !== MemberStatus::Active) {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::ASSIGNEE_NOT_ELIGIBLE, 'Only an active member of this company can be made responsible.', 'assignee_id');
        }

        return $user;
    }
}
