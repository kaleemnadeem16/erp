<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\RecordServiceCompletionBasis;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Http\Requests\Release\PreviewServiceReleaseRequest;
use App\Modules\Sales\Http\Requests\Release\RecordCompletionBasisRequest;
use App\Modules\Sales\Http\Requests\Release\ReleaseServiceRequest;
use App\Modules\Sales\Http\Resources\CompletionBasisOutcomeResource;
use App\Modules\Sales\Http\Resources\ServiceReleaseOutcomeResource;
use App\Modules\Sales\Http\Resources\ServiceReleasePreviewResource;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/** Thin HTTP surface over the Sales completion-basis, release-review and release Actions. Every business rule, guard and lock lives in the Actions. */
final class HandoverReleaseController extends Controller
{
    #[HeaderParameter('Idempotency-Key', 'Per-person request key. Current authority is rechecked before exact retry.', required: true, type: 'string')]
    #[Response(201, 'Completion basis recorded.', type: 'array{data:CompletionBasisOutcomeResource}')]
    #[Response(200, 'Exact request replay or identical current basis.', type: 'array{data:CompletionBasisOutcomeResource}')]
    #[Response(409, 'Reused key.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function recordBasis(RecordCompletionBasisRequest $request, int $acceptance, RecordServiceCompletionBasis $record): JsonResponse
    {
        $out = $this->refusing(fn (): array => $record($request->user(), $request->header('Idempotency-Key'), $acceptance, $request->input('agreed_text'), $request->integer('evidence_id')));

        return $this->respond(new CompletionBasisOutcomeResource(['basis_id' => $out['basis_id'], 'version' => $out['version'], 'replayed' => $out['replayed']]), $out['replayed']);
    }

    public function preview(PreviewServiceReleaseRequest $request, int $stage, PreviewServiceRelease $preview): ServiceReleasePreviewResource
    {
        $d = $this->refusing(fn (): array => $preview($request->user(), $stage));

        return new ServiceReleasePreviewResource([
            'ready' => $d['ready'],
            'blockers' => array_values($d['blockers']),
            'next_actions' => array_map(fn (array $a): array => ['fact' => $a['fact'], 'owner' => $a['owner'], 'action' => $a['action']], array_values($d['next_actions'])),
            'cost' => ['required' => (bool) $d['cost']['required'], 'status' => (string) $d['cost']['status']],
            'applicability' => ['status' => (string) $d['applicability']['status'], 'materials' => (string) $d['applicability']['scope']['materials'], 'subcontract' => (string) $d['applicability']['scope']['subcontract']],
            'basis_id' => $d['basis_id'],
            'basis_version' => $d['basis_version'],
            'fingerprint' => $d['fingerprint'],
        ]);
    }

    #[HeaderParameter('Idempotency-Key', 'Per-person request key. Current authority, including the receiver\'s own duty, is rechecked before exact retry.', required: true, type: 'string')]
    #[Response(201, 'Released: one numbered Job created.', type: 'array{data:ServiceReleaseOutcomeResource}')]
    #[Response(200, 'Exact request or business replay of the same release.', type: 'array{data:ServiceReleaseOutcomeResource}')]
    #[Response(409, 'Stale review, reused key or stage already released differently.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function release(ReleaseServiceRequest $request, int $stage, ReleaseHandoverComponent $release): JsonResponse
    {
        $out = $this->refusing(fn (): array => $release($request->user(), $request->header('Idempotency-Key'), $stage, $request->integer('basis_id'), (string) $request->input('readiness_fingerprint')));

        return $this->respond(new ServiceReleaseOutcomeResource(['release_event_id' => $out['release_event_id'], 'job_id' => $out['job_id'], 'job_code' => $out['job_code'], 'replayed' => $out['replayed']]), $out['replayed']);
    }

    private function respond(JsonResource $resource, bool $replayed): JsonResponse
    {
        $response = $resource->response()->setStatusCode($replayed ? 200 : 201);
        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
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
            if ($status === CustomerDecisionRefusalStatus::NOT_FOUND) {
                throw new ModelNotFoundException;
            }
            if ($status === CustomerDecisionRefusalStatus::CONFLICT) {
                throw new HttpResponseException(response()->json(['message' => $e->getMessage(), 'reason_code' => $e->reasonCode, 'errors' => [($e->field ?? 'id') => [$e->getMessage()]]], $status));
            }
            throw $e;
        }
    }
}
