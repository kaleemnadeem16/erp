<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\AssessAcceptanceReadiness;
use App\Modules\Sales\Actions\PreviewCustomerAcceptance;
use App\Modules\Sales\Actions\ReadCustomerAcceptance;
use App\Modules\Sales\Actions\RecordCustomerAcceptance;
use App\Modules\Sales\Http\Requests\CustomerDecision\AssessAcceptanceReadinessRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\HandoverFields;
use App\Modules\Sales\Http\Requests\CustomerDecision\PreviewCustomerAcceptanceRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\RecordCustomerAcceptanceRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\ShowCustomerAcceptanceRequest;
use App\Modules\Sales\Http\Resources\CustomerAcceptanceOutcomeResource;
use App\Modules\Sales\Http\Resources\CustomerAcceptancePreviewResource;
use App\Modules\Sales\Http\Resources\CustomerAcceptanceReadinessResource;
use App\Modules\Sales\Http\Resources\CustomerAcceptanceResource;
use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/** Full unchanged acceptance only. All duties remain STAGED, including in dev preview; every route has a Request and an Action guard. No grant or activation. */
final class CustomerAcceptanceController extends Controller
{
    /** Review one full independent scope on exact issued terms. No quantity/price editor and no persistent review receipt. */
    public function preview(PreviewCustomerAcceptanceRequest $request, int $revision, PreviewCustomerAcceptance $preview): CustomerAcceptancePreviewResource
    {
        return new CustomerAcceptancePreviewResource($this->refusing(fn (): array => $preview($request->user(), $revision,
            $request->integer('component_id'), $request->integer('evidence_id'), $request->integer('issued_line_id'),
            $request->integer('expected_case_generation'), $request->integer('expected_scope_generation'),
            $request->input('expected_choice_generation') === null ? null : $request->integer('expected_choice_generation'))));
    }

    /** Records commercial consent, never a Job, Order, stock reservation, invoice, revenue or Opportunity outcome. */
    #[HeaderParameter('Idempotency-Key', 'Unique per person and request intent; an exact retry replays after current authority checks.', required: true, type: 'string')]
    #[Response(201, 'Acceptance recorded.', type: 'array{data: CustomerAcceptanceOutcomeResource}')]
    #[Response(200, 'Exact request retry.', type: 'array{data: CustomerAcceptanceOutcomeResource}')]
    #[Response(409, 'Stale review, claimed scope, conflicting consent or reused request key.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function store(RecordCustomerAcceptanceRequest $request, int $revision, RecordCustomerAcceptance $record): JsonResponse
    {
        $out = $this->refusing(fn (): array => $record($request->user(), $request->header('Idempotency-Key'), $revision,
            $request->integer('component_id'), $request->integer('evidence_id'), $request->integer('issued_line_id'),
            $request->integer('expected_case_generation'), $request->integer('expected_scope_generation'),
            $request->input('expected_choice_generation') === null ? null : $request->integer('expected_choice_generation'),
            (string) $request->input('preview_token'), $request->boolean('unchanged_terms')));
        $response = (new CustomerAcceptanceOutcomeResource($out))->response()->setStatusCode($out['replayed'] ? 200 : 201);
        if ($out['replayed']) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /** Immutable agreed terms, visible under current independent case and quotation read duties. */
    public function show(ShowCustomerAcceptanceRequest $request, int $acceptance, ReadCustomerAcceptance $read): CustomerAcceptanceResource
    {
        return new CustomerAcceptanceResource($this->refusing(fn (): array => $read($request->user(), $acceptance)));
    }

    /** Current readiness, separate from acceptance truth. No delivered target adapter in slice 2. */
    public function readiness(AssessAcceptanceReadinessRequest $request, int $acceptance, AssessAcceptanceReadiness $assess): CustomerAcceptanceReadinessResource
    {
        return new CustomerAcceptanceReadinessResource($this->refusing(fn (): array => $assess($request->user(), $acceptance, (string) $request->input('path', 'SERVICE_JOB'), HandoverFields::from($request))));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function refusing(Closure $work): mixed
    {
        try {
            return $work();
        } catch (DomainInvariantException $refusal) {
            $status = CustomerDecisionRefusalStatus::for($refusal->reasonCode);
            if ($status === CustomerDecisionRefusalStatus::NOT_FOUND) {
                throw (new ModelNotFoundException)->setModel(CustomerAcceptance::class);
            }
            if ($status === CustomerDecisionRefusalStatus::CONFLICT) {
                throw new HttpResponseException(response()->json(['message' => $refusal->getMessage(), 'reason_code' => $refusal->reasonCode,
                    'errors' => [($refusal->field ?? 'id') => [$refusal->getMessage()]]], $status));
            }
            throw $refusal;
        }
    }
}
