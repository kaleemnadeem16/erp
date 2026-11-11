<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\ReadHandoverPlan;
use App\Modules\Sales\Http\Requests\CustomerDecision\HandoverFields;
use App\Modules\Sales\Http\Requests\CustomerDecision\PrepareHandoverPlanRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\PreviewHandoverRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\ShowHandoverPlanRequest;
use App\Modules\Sales\Http\Resources\HandoverPlanOutcomeResource;
use App\Modules\Sales\Http\Resources\HandoverPlanResource;
use App\Modules\Sales\Http\Resources\HandoverPreviewResource;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/** Inactive preparation only; no release route, target id, Job, stock, credit or accounting effect. */
final class HandoverPlanController extends Controller
{
    public function preview(PreviewHandoverRequest $request, int $acceptance, PreviewHandover $preview): HandoverPreviewResource
    {
        return new HandoverPreviewResource($this->refusing(fn (): array => $preview($request->user(), $acceptance, HandoverFields::from($request))));
    }

    #[HeaderParameter('Idempotency-Key', 'Per-person request key. Current authority is rechecked before exact retry.', required: true, type: 'string')]
    #[Response(201, 'Pending service plan prepared.', type: 'array{data:HandoverPlanOutcomeResource}')]
    #[Response(200, 'Exact request receipt replay.', type: 'array{data:HandoverPlanOutcomeResource}')]
    #[Response(409, 'Stale review, reused key or acceptance already allocated.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function store(PrepareHandoverPlanRequest $request, int $acceptance, PrepareHandoverPlan $prepare): JsonResponse
    {
        $out = $this->refusing(fn (): array => $prepare($request->user(), $request->header('Idempotency-Key'), $acceptance, HandoverFields::from($request), (string) $request->input('preview_token')));
        $response = (new HandoverPlanOutcomeResource($out))->response()->setStatusCode($out['replayed'] ? 200 : 201);
        if ($out['replayed']) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function show(ShowHandoverPlanRequest $request, int $plan, ReadHandoverPlan $read): HandoverPlanResource
    {
        return new HandoverPlanResource($this->refusing(fn (): array => $read($request->user(), $plan)));
    }

    /**
     * @template T
     *
     * @param  Closure():T  $work
     * @return T
     */
    private function refusing(Closure $work): mixed
    {
        try {
            return $work();
        } catch (DomainInvariantException $e) {
            $status = CustomerDecisionRefusalStatus::for($e->reasonCode);
            if ($status === 409) {
                throw new HttpResponseException(response()->json(['message' => $e->getMessage(), 'reason_code' => $e->reasonCode, 'errors' => [($e->field ?? 'id') => [$e->getMessage()]]], $status));
            }
            throw $e;
        }
    }
}
