<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\ListQuotations;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Actions\ReadIssuedQuotation;
use App\Modules\Sales\Actions\ReadQuotationDraft;
use App\Modules\Sales\Actions\ReadQuotationDraftForEdit;
use App\Modules\Sales\Actions\ReadQuotationTaxDeclarations;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Http\Requests\Quotation\AssessQuotationIssueReadinessRequest;
use App\Modules\Sales\Http\Requests\Quotation\EditQuotationDraftRequest;
use App\Modules\Sales\Http\Requests\Quotation\IndexQuotationRequest;
use App\Modules\Sales\Http\Requests\Quotation\IssueQuotationRequest;
use App\Modules\Sales\Http\Requests\Quotation\PreviewQuotationRequest;
use App\Modules\Sales\Http\Requests\Quotation\ShowIssuedQuotationRequest;
use App\Modules\Sales\Http\Requests\Quotation\ShowQuotationDraftRequest;
use App\Modules\Sales\Http\Requests\Quotation\ShowQuotationTaxDeclarationsRequest;
use App\Modules\Sales\Http\Requests\Quotation\StoreQuotationDraftRequest;
use App\Modules\Sales\Http\Requests\Quotation\UpdateQuotationDraftRequest;
use App\Modules\Sales\Http\Resources\QuotationDraftEditableResource;
use App\Modules\Sales\Http\Resources\QuotationDraftResource;
use App\Modules\Sales\Http\Resources\QuotationDraftWriteResource;
use App\Modules\Sales\Http\Resources\QuotationIssuedResource;
use App\Modules\Sales\Http\Resources\QuotationIssueOutcomeResource;
use App\Modules\Sales\Http\Resources\QuotationIssueReadinessResource;
use App\Modules\Sales\Http\Resources\QuotationPreviewResource;
use App\Modules\Sales\Http\Resources\QuotationSummaryResource;
use App\Modules\Sales\Http\Resources\QuotationTaxDeclarationResource;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationIssueOutcome;
use App\Modules\Sales\Support\Quotation\QuotationRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuotationController extends Controller
{
    /** List safe quotation identities visible to the current view holder. */
    public function index(IndexQuotationRequest $request, ListQuotations $list): AnonymousResourceCollection
    {
        return QuotationSummaryResource::collection($list($request->user(), $request->validated()));
    }

    public function show(ShowQuotationDraftRequest $request, int $revision, ReadQuotationDraft $read): QuotationDraftResource
    {
        return new QuotationDraftResource($this->refusing(fn () => $read($request->user(), $revision)));
    }

    /** The draft as a form needs it: header and lines in the shape the PATCH accepts, at the generation to send back. For preparers (`sales.quotation.prepare`), not viewers. */
    public function editable(EditQuotationDraftRequest $request, int $revision, ReadQuotationDraftForEdit $edit): QuotationDraftEditableResource
    {
        return new QuotationDraftEditableResource($this->refusing(fn () => $edit($request->user(), $revision)));
    }

    /** Every version of the seller's tax declarations for the draft's manual lines, oldest first (evidence: a correction is a later version, never an edit). */
    public function taxDeclarations(ShowQuotationTaxDeclarationsRequest $request, int $revision, ReadQuotationTaxDeclarations $read): AnonymousResourceCollection
    {
        return QuotationTaxDeclarationResource::collection($this->refusing(fn () => $read($request->user(), $revision)));
    }

    public function preview(PreviewQuotationRequest $request, int $revision, PreviewQuotation $preview): QuotationPreviewResource
    {
        return new QuotationPreviewResource($this->refusing(fn () => $preview($request->user(), $revision)));
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
        $id = $revision;
        $changes = $request->json()->all();
        unset($changes['expected_generation']);
        $outcome = $this->refusing(fn (): QuotationDraftOutcome => $update($request->user(), $id, $request->integer('expected_generation'), $request->header('Idempotency-Key'), $changes));

        return $this->written($outcome, 200);
    }

    /** Whether the draft could be issued now: named blockers only (codes and scope, never an amount). Needs prepare with the independent view; both STAGED. */
    public function readiness(AssessQuotationIssueReadinessRequest $request, int $revision, AssessQuotationIssueReadiness $assess): QuotationIssueReadinessResource
    {
        return new QuotationIssueReadinessResource($this->refusing(fn (): array => $assess($request->user(), $revision)));
    }

    /**
     * Issues the draft the caller reviewed at `expected_generation`: the official number is allocated and the terms are frozen. Needs issue with the independent view;
     * both STAGED. An exact retry with the same Idempotency-Key replays the original outcome (200, header Idempotency-Replayed: true); a first success is 201.
     */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct issue attempt (1 to 85 characters, unique per person). Retrying the same attempt with the same key replays the stored outcome; the same key with a different request is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The quotation was issued.', type: 'array{data: QuotationIssueOutcomeResource}')]
    #[Response(200, 'An exact retry: the original outcome, with the header Idempotency-Replayed: true.', type: 'array{data: QuotationIssueOutcomeResource}')]
    #[Response(404, 'The quotation does not exist or is not visible to the caller; the answer never says which.', type: 'array{message: string}')]
    #[Response(409, 'The Idempotency-Key was already used for a different request (reason_code REQUEST_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function issue(IssueQuotationRequest $request, int $revision, IssueQuotation $issue): JsonResponse
    {
        $outcome = $this->refusing(fn (): QuotationIssueOutcome => $issue($request->user(), $revision, $request->integer('expected_generation'), $request->header('Idempotency-Key')));
        $response = (new QuotationIssueOutcomeResource([
            'family_id' => $outcome->familyId, 'revision_id' => $outcome->revisionId, 'issue_id' => $outcome->issueId, 'official_number' => $outcome->officialNumber,
            'issued_at' => $outcome->issuedAt, 'replayed' => $outcome->replayed,
        ]))->response()->setStatusCode($outcome->replayed ? 200 : 201);
        if ($outcome->replayed) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /** The issued quotation's official number and frozen header terms; totals only for holders of prepare or issue on that revision. A draft is not found here. */
    public function issued(ShowIssuedQuotationRequest $request, int $revision, ReadIssuedQuotation $read): QuotationIssuedResource
    {
        return new QuotationIssuedResource($this->refusing(fn (): array => $read($request->user(), $revision)));
    }

    /**
     * Gives a refusal its documented status (QuotationRefusalStatus): 404 when the quotation is not there, 409 when the key belongs to a different request. Every other
     * refusal is rethrown to the global 422 renderer, which already carries the `reason_code`.
     *
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
