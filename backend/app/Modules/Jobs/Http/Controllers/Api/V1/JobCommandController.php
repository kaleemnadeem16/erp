<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\ResolveJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Http\Requests\AssessJobCloseoutRequest;
use App\Modules\Jobs\Http\Requests\AssignJobRequest;
use App\Modules\Jobs\Http\Requests\CloseJobRequest;
use App\Modules\Jobs\Http\Requests\CompleteJobRequest;
use App\Modules\Jobs\Http\Requests\CompletionAttestationContextRequest;
use App\Modules\Jobs\Http\Requests\JobOperationContextRequest;
use App\Modules\Jobs\Http\Requests\PlaceJobHoldRequest;
use App\Modules\Jobs\Http\Requests\ResolveJobHoldRequest;
use App\Modules\Jobs\Http\Requests\StartJobRequest;
use App\Modules\Jobs\Http\Resources\JobCloseoutAssessmentResource;
use App\Modules\Jobs\Http\Resources\JobCommandResource;
use App\Modules\Jobs\Http\Resources\JobOperationContextResource;
use App\Modules\Jobs\Queries\ReadJobOperationContext;
use Closure;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JobCommandController extends Controller
{
    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe command result, including retries.', type: 'array{data:JobCommandResource}')]
    public function jobsAssign(AssignJobRequest $request, int $job, AssignJobResponsibility $action): JobCommandResource
    {
        return new JobCommandResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, assigneeId: $request->integer('assignee_id'),
            expectedGeneration: $request->integer('expected_generation'), requestKey: $request->string('request_key')->toString(),
            commandIdentity: $request->string('command_identity')->toString(), reason: $request->validated('reason'),
            intendedFrom: $request->timestamp('intended_from'), intendedUntil: $request->timestamp('intended_until'), instructions: $request->validated('instructions'),
            occurredAt: $request->timestamp('occurred_at'), occurrencePrecision: $request->validated('occurrence_precision', 'NONE'),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe command result, including retries.', type: 'array{data:JobCommandResource}')]
    public function jobsStart(StartJobRequest $request, int $job, StartJob $action): JobCommandResource
    {
        return new JobCommandResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, expectedGeneration: $request->integer('expected_generation'),
            requestKey: $request->string('request_key')->toString(), commandIdentity: $request->string('command_identity')->toString(), reason: $request->validated('reason'),
            occurredAt: $request->timestamp('occurred_at'), occurrencePrecision: $request->validated('occurrence_precision', 'NONE'),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe command result, including retries.', type: 'array{data:JobCommandResource}')]
    public function jobsHold(PlaceJobHoldRequest $request, int $job, PlaceJobHold $action): JobCommandResource
    {
        return new JobCommandResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, reason: $request->string('reason')->toString(), expectedGeneration: $request->integer('expected_generation'),
            requestKey: $request->string('request_key')->toString(), commandIdentity: $request->string('command_identity')->toString(),
            occurredAt: $request->timestamp('occurred_at'), occurrencePrecision: $request->validated('occurrence_precision', 'NONE'),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe command result, including retries.', type: 'array{data:JobCommandResource}')]
    public function jobsResolveHold(ResolveJobHoldRequest $request, int $job, ResolveJobHold $action): JobCommandResource
    {
        return new JobCommandResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, holdId: $request->integer('hold_id'), reason: $request->string('reason')->toString(),
            expectedGeneration: $request->integer('expected_generation'), requestKey: $request->string('request_key')->toString(), commandIdentity: $request->string('command_identity')->toString(),
            occurredAt: $request->timestamp('occurred_at'), occurrencePrecision: $request->validated('occurrence_precision', 'NONE'),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe command result, including retries.', type: 'array{data:JobCommandResource}')]
    public function jobsComplete(CompleteJobRequest $request, int $job, CompleteJob $action): JobCommandResource
    {
        return new JobCommandResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, expectedGeneration: $request->integer('expected_generation'),
            requestKey: $request->string('request_key')->toString(), commandIdentity: $request->string('command_identity')->toString(), statement: $request->string('statement')->toString(),
            occurredAt: $request->timestamp('occurred_at'), occurrencePrecision: $request->validated('occurrence_precision', 'NONE'),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Recorded READY or BLOCKED assessment.', type: 'array{data:JobCloseoutAssessmentResource}')]
    public function jobsAssessCloseout(AssessJobCloseoutRequest $request, int $job, AssessJobCloseout $action): JobCloseoutAssessmentResource
    {
        return new JobCloseoutAssessmentResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, expectedGeneration: $request->integer('expected_generation'), requestKey: $request->string('request_key')->toString(),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe command result, including retries.', type: 'array{data:JobCommandResource}')]
    public function jobsClose(CloseJobRequest $request, int $job, CloseJob $action): JobCommandResource
    {
        return new JobCommandResource($this->found(fn () => $action->execute(
            actor: $request->user(), jobId: $job, expectedGeneration: $request->integer('expected_generation'), assessmentId: $request->integer('assessment_id'),
            requestKey: $request->string('request_key')->toString(), commandIdentity: $request->string('command_identity')->toString(), statement: $request->validated('statement'),
            occurredAt: $request->timestamp('occurred_at'), occurrencePrecision: $request->validated('occurrence_precision', 'NONE'),
        )));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Minimal source-eligible operation context.', type: 'array{data:JobOperationContextResource}')]
    public function jobsOperationContext(JobOperationContextRequest $request, int $job, ReadJobOperationContext $query): JobOperationContextResource
    {
        return new JobOperationContextResource($this->found(fn () => $query->read($request->user(), $job, $request->operation())));
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Frozen completion attestation context.', type: 'array{data:array{job_id:int,number:string,state:string,generation:int,baseline_version:int,deliverable:string,agreed_conditions:string}}')]
    public function jobsCompletionAttestationContext(CompletionAttestationContextRequest $request, int $job, ReadJobOperationContext $query): JsonResponse
    {
        return response()->json(['data' => $this->found(fn () => $query->readCompletionAttestation($request->user(), $job))])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function found(Closure $work): mixed
    {
        try {
            return $work();
        } catch (DomainInvariantException $exception) {
            if ($exception->reasonCode === 'JOB_NOT_FOUND') {
                throw new NotFoundHttpException('The Job was not found.');
            }
            throw $exception;
        }
    }
}
