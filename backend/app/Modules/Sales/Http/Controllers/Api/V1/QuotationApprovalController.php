<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Sales\Actions\DecideQuotationApproval;
use App\Modules\Sales\Actions\ReadQuotationApproval;
use App\Modules\Sales\Actions\RequestQuotationApproval;
use App\Modules\Sales\Actions\WithdrawQuotationApproval;
use App\Modules\Sales\Http\Requests\Quotation\DecideQuotationApprovalRequest;
use App\Modules\Sales\Http\Requests\Quotation\RequestQuotationApprovalRequest;
use App\Modules\Sales\Http\Requests\Quotation\ShowQuotationApprovalRequest;
use App\Modules\Sales\Http\Requests\Quotation\WithdrawQuotationApprovalRequest;
use App\Modules\Sales\Http\Resources\QuotationApprovalOutcomeResource;
use App\Modules\Sales\Http\Resources\QuotationApprovalResource;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalOutcome;
use App\Modules\Sales\Support\Quotation\QuotationRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/** Approval of a draft's recorded discount or final-net override (ADR 0158): read where it stands, ask, decide, withdraw. Every duty is STAGED; the Actions judge the record per revision. */
class QuotationApprovalController extends Controller
{
    /** Where the draft stands with its approval, and what the caller may do now. For a preparer (prepare) or a decider (approve), each with the independent view. */
    public function show(ShowQuotationApprovalRequest $request, int $revision, ReadQuotationApproval $read): QuotationApprovalResource
    {
        return new QuotationApprovalResource($this->refusing(fn (): array => $read($request->user(), $revision)));
    }

    /** Asks for approval of the discount or override the draft carries, for the draft exactly as the caller saw it (`expected_generation`). Needs prepare with the independent view; both STAGED. */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct request (1 to 85 characters, unique per person). Retrying the same request with the same key replays the stored outcome; the same key with a different request is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The approval was requested.', type: 'array{data: QuotationApprovalOutcomeResource}')]
    #[Response(200, 'An exact retry: the original outcome, with the header Idempotency-Replayed: true.', type: 'array{data: QuotationApprovalOutcomeResource}')]
    #[Response(404, 'The quotation does not exist or is not visible to the caller; the answer never says which.', type: 'array{message: string}')]
    #[Response(409, 'The Idempotency-Key was already used for a different request (reason_code REQUEST_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function store(RequestQuotationApprovalRequest $request, int $revision, RequestQuotationApproval $action): JsonResponse
    {
        return $this->outcome($this->refusing(fn (): QuotationApprovalOutcome => $action($request->user(), $revision, $request->integer('expected_generation'), $request->header('Idempotency-Key'))));
    }

    /** Approves or rejects the pending approval of the draft exactly as the decider reviewed it. Needs approve with the independent view; both STAGED. The preparer can never decide. */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct decision (1 to 85 characters, unique per person). Retrying the same decision with the same key replays the stored outcome; the same key with a different request is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The decision was recorded.', type: 'array{data: QuotationApprovalOutcomeResource}')]
    #[Response(200, 'An exact retry: the original outcome, with the header Idempotency-Replayed: true.', type: 'array{data: QuotationApprovalOutcomeResource}')]
    #[Response(404, 'The quotation does not exist or is not visible to the caller; the answer never says which.', type: 'array{message: string}')]
    #[Response(409, 'The Idempotency-Key was already used for a different request (reason_code REQUEST_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function decide(DecideQuotationApprovalRequest $request, int $revision, DecideQuotationApproval $action): JsonResponse
    {
        $decision = ApprovalDecisionKind::from((string) $request->input('decision'));
        $reason = $request->input('reason');

        return $this->outcome($this->refusing(fn (): QuotationApprovalOutcome => $action($request->user(), $revision, $request->integer('expected_generation'), $decision, is_string($reason) ? $reason : null, $request->header('Idempotency-Key'))));
    }

    /** Takes a pending approval request back so the draft can be changed. Needs prepare with the independent view; both STAGED. */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct withdrawal (1 to 85 characters, unique per person). Retrying the same withdrawal with the same key replays the stored outcome; the same key with a different request is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The approval request was withdrawn.', type: 'array{data: QuotationApprovalOutcomeResource}')]
    #[Response(200, 'An exact retry: the original outcome, with the header Idempotency-Replayed: true.', type: 'array{data: QuotationApprovalOutcomeResource}')]
    #[Response(404, 'The quotation does not exist or is not visible to the caller; the answer never says which.', type: 'array{message: string}')]
    #[Response(409, 'The Idempotency-Key was already used for a different request (reason_code REQUEST_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function withdraw(WithdrawQuotationApprovalRequest $request, int $revision, WithdrawQuotationApproval $action): JsonResponse
    {
        return $this->outcome($this->refusing(fn (): QuotationApprovalOutcome => $action($request->user(), $revision, $request->integer('expected_generation'), (string) $request->input('reason'), $request->header('Idempotency-Key'))));
    }

    private function outcome(QuotationApprovalOutcome $outcome): JsonResponse
    {
        $response = (new QuotationApprovalOutcomeResource([
            'family_id' => $outcome->familyId, 'revision_id' => $outcome->revisionId, 'approval_request_id' => $outcome->approvalRequestId, 'status' => $outcome->status,
            'input_generation' => $outcome->inputGeneration, 'replayed' => $outcome->replayed,
        ]))->response()->setStatusCode($outcome->replayed ? 200 : 201);
        if ($outcome->replayed) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * Gives a refusal its documented status (QuotationRefusalStatus): 404 when the quotation is not there, 409 when the key belongs to a different request; every other refusal is
     * rethrown to the global 422 renderer, which carries the `reason_code`.
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
}
