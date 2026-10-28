<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Actions\ReadQuotationDraft;
use App\Modules\Sales\Actions\ReadQuotationDraftForEdit;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Http\Requests\Quotation\EditQuotationDraftRequest;
use App\Modules\Sales\Http\Requests\Quotation\PreviewQuotationRequest;
use App\Modules\Sales\Http\Requests\Quotation\ShowQuotationDraftRequest;
use App\Modules\Sales\Http\Requests\Quotation\StoreQuotationDraftRequest;
use App\Modules\Sales\Http\Requests\Quotation\UpdateQuotationDraftRequest;
use App\Modules\Sales\Http\Resources\QuotationDraftEditableResource;
use App\Modules\Sales\Http\Resources\QuotationDraftResource;
use App\Modules\Sales\Http\Resources\QuotationDraftWriteResource;
use App\Modules\Sales\Http\Resources\QuotationPreviewResource;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class QuotationController extends Controller
{
    /**
     * A quotation that is not in the caller's company is indistinguishable from one that does not exist: 404, never a refusal that says it is there. The lookup
     * runs after the Request's capability guard, so an unauthorised caller learns nothing from it, and the company scope on the model does the filtering.
     */
    private function revisionId(int $revision): int
    {
        return QuotationRevision::query()->findOrFail($revision)->id;
    }

    public function show(ShowQuotationDraftRequest $request, int $revision, ReadQuotationDraft $read): QuotationDraftResource
    {
        return new QuotationDraftResource($read($request->user(), $this->revisionId($revision)));
    }

    /** The draft as a form needs it: header and lines in the shape the PATCH accepts, at the generation to send back. For preparers (`sales.quotation.prepare`), not viewers. */
    public function editable(EditQuotationDraftRequest $request, int $revision, ReadQuotationDraftForEdit $edit): QuotationDraftEditableResource
    {
        return new QuotationDraftEditableResource($edit($request->user(), $this->revisionId($revision)));
    }

    public function preview(PreviewQuotationRequest $request, int $revision, PreviewQuotation $preview): QuotationPreviewResource
    {
        return new QuotationPreviewResource($preview($request->user(), $this->revisionId($revision)));
    }

    /**
     * Starts a draft. The Idempotency-Key header is the Action's request key: an exact retry replays the stored result (200), a first success is 201.
     */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct change (1 to 85 characters, unique per person). Retrying the same change with the same key replays the stored result; the same key with a different body is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The draft was created.', type: 'array{data: QuotationDraftWriteResource}')]
    #[Response(200, 'An exact retry: the stored result of the first request, with the header Idempotency-Replayed: true.', type: 'array{data: QuotationDraftWriteResource}')]
    #[Response(409, 'The Idempotency-Key was already used for a different request (reason_code REQUEST_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function store(StoreQuotationDraftRequest $request, CreateQuotationDraft $create): JsonResponse
    {
        $outcome = $this->refusing(fn (): QuotationDraftOutcome => $create($request->user(), $request->header('Idempotency-Key'), $request->json()->all()));

        return $this->written($outcome, $outcome->replayed ? 200 : 201);
    }

    /**
     * Changes a draft the caller last saw at `expected_generation`. A replay of the same key and body returns the stored result with the header Idempotency-Replayed: true.
     */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct change (1 to 85 characters, unique per person). Retrying the same change with the same key replays the stored result; the same key with a different body is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(200, 'The draft was changed, or this is an exact retry (header Idempotency-Replayed: true) and the stored result is returned.', type: 'array{data: QuotationDraftWriteResource}')]
    #[Response(404, 'The quotation does not exist or is not visible to the caller; the answer never says which.', type: 'array{message: string}')]
    #[Response(409, 'The Idempotency-Key was already used for a different request (reason_code REQUEST_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function update(UpdateQuotationDraftRequest $request, int $revision, UpdateQuotationDraft $update): JsonResponse
    {
        $id = $this->revisionId($revision);
        $changes = $request->json()->all();
        unset($changes['expected_generation']);
        $outcome = $this->refusing(fn (): QuotationDraftOutcome => $update($request->user(), $id, $request->integer('expected_generation'), $request->header('Idempotency-Key'), $changes));

        return $this->written($outcome, 200);
    }

    /**
     * Gives a refusal its documented status (QuotationRefusalStatus): 404 when the quotation is not there, 409 when the key belongs to a different request. Every other
     * refusal is rethrown to the global 422 renderer, which already carries the `reason_code`.
     *
     * @param  Closure(): QuotationDraftOutcome  $work
     */
    private function refusing(Closure $work): QuotationDraftOutcome
    {
        try {
            return $work();
        } catch (DomainInvariantException $refusal) {
            $status = QuotationRefusalStatus::for($refusal->reasonCode);
            if ($status === QuotationRefusalStatus::NOT_FOUND) {
                throw (new ModelNotFoundException)->setModel(QuotationRevision::class);
            }
            if ($status === QuotationRefusalStatus::CONFLICT) {
                throw new HttpResponseException(response()->json(array_filter([
                    'message' => $refusal->getMessage(), 'reason_code' => $refusal->reasonCode, 'errors' => [($refusal->field ?? 'id') => [$refusal->getMessage()]],
                ], fn ($value) => $value !== null), $status));
            }

            throw $refusal;
        }
    }

    private function written(QuotationDraftOutcome $outcome, int $status): JsonResponse
    {
        $response = (new QuotationDraftWriteResource($outcome))->response()->setStatusCode($status);
        if ($outcome->replayed) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
