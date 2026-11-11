<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Sales\Actions\AppendCustomerDecisionEvidence;
use App\Modules\Sales\Actions\ListCustomerDecisionCases;
use App\Modules\Sales\Actions\OpenCustomerDecisionCase;
use App\Modules\Sales\Actions\ReadCustomerDecisionCase;
use App\Modules\Sales\Http\Requests\CustomerDecision\AppendCustomerDecisionEvidenceRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\ListCustomerDecisionCasesRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\OpenCustomerDecisionCaseRequest;
use App\Modules\Sales\Http\Requests\CustomerDecision\ShowCustomerDecisionCaseRequest;
use App\Modules\Sales\Http\Resources\CustomerDecisionCaseResource;
use App\Modules\Sales\Http\Resources\CustomerDecisionCaseSummaryResource;
use App\Modules\Sales\Http\Resources\CustomerDecisionOutcomeResource;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOutcome;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusalStatus;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What a customer sent, recorded as shared intake cases (CA1, slice 1 of customer acceptance). Observations only: nothing here records consent, accepts a quotation, claims
 * quantity or protects an offer from withdrawal. Reads need `sales.customer-decision.view` and writes `sales.customer-decision.evidence.record`; both are STAGED, so every caller is
 * refused until a reviewed holder recipe activates them. Each route is guarded by its Request and again by its Action, inside the transaction and before anything is looked up.
 */
class CustomerDecisionCaseController extends Controller
{
    /** The intake cases, newest first. Safe identities and counts only. */
    public function index(ListCustomerDecisionCasesRequest $request, ListCustomerDecisionCases $list): AnonymousResourceCollection
    {
        return CustomerDecisionCaseSummaryResource::collection($list($request->user(), $request->validated()));
    }

    /** One case with its components and the whole observation chain. A case that does not exist is a plain 404, after the caller's duty was checked. */
    #[Response(404, 'The case does not exist in this company.', type: 'array{message: string}')]
    public function show(ShowCustomerDecisionCaseRequest $request, int $case, ReadCustomerDecisionCase $read): CustomerDecisionCaseResource
    {
        return new CustomerDecisionCaseResource($this->refusing(fn (): array => $read($request->user(), $case)));
    }

    /** Opens an intake case for a customer Party, optionally with the components the message is about. An intake identity that already exists is never merged: 409 INTAKE_IDENTITY_EXISTS. */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct change (1 to 85 characters, unique per person). Retrying the same change with the same key replays the stored result; the same key with a different body is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The case was opened.', type: 'array{data: CustomerDecisionOutcomeResource}')]
    #[Response(200, 'An exact retry: the stored result, with the header Idempotency-Replayed: true.', type: 'array{data: CustomerDecisionOutcomeResource}')]
    #[Response(409, 'The key was used for a different request (REQUEST_KEY_REUSED) or the intake identity already exists (INTAKE_IDENTITY_EXISTS).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function store(OpenCustomerDecisionCaseRequest $request, OpenCustomerDecisionCase $open): JsonResponse
    {
        $outcome = $this->refusing(fn (): CustomerDecisionOutcome => $open(
            $request->user(), $request->header('Idempotency-Key'), $request->integer('party_id'), (string) $request->input('intake_identity'), array_values((array) $request->input('components', [])),
        ));

        return $this->written($outcome);
    }

    /** Appends one observation to a case the caller last saw at `expected_generation`. A moved case is 409 DECISION_CASE_STALE: reload it. An observation is never consent. */
    #[HeaderParameter('Idempotency-Key', 'Chosen by the client for each distinct change (1 to 85 characters, unique per person). Retrying the same change with the same key replays the stored result; the same key with a different body is refused with 409 REQUEST_KEY_REUSED.', required: true, type: 'string')]
    #[Response(201, 'The observation was recorded.', type: 'array{data: CustomerDecisionOutcomeResource}')]
    #[Response(200, 'An exact retry: the stored result, with the header Idempotency-Replayed: true.', type: 'array{data: CustomerDecisionOutcomeResource}')]
    #[Response(404, 'The case does not exist in this company.', type: 'array{message: string}')]
    #[Response(409, 'The key was used for a different request (REQUEST_KEY_REUSED) or the case moved since it was read (DECISION_CASE_STALE).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function appendEvidence(AppendCustomerDecisionEvidenceRequest $request, int $case, AppendCustomerDecisionEvidence $append): JsonResponse
    {
        $fields = [];
        foreach (['source_kind', 'external_reference', 'source_version', 'customer_representative_snapshot', 'authority_verification_basis', 'occurrence_precision', 'source_timezone', 'factual_description'] as $name) {
            $fields[$name] = $request->input($name);
        }
        // A stated time needs the zone it was stated in: the company's when the client names none. An unknown time carries no zone (the database refuses one).
        if ($fields['occurrence_precision'] !== 'UNKNOWN' && ($fields['source_timezone'] === null || $fields['source_timezone'] === '')) {
            $fields['source_timezone'] = $this->companyZone();
        }
        $fields['occurred_at'] = $this->instant($request->input('occurred_at'), $fields['source_timezone']);
        $fields['occurred_until'] = $this->instant($request->input('occurred_until'), $fields['source_timezone']);
        $predecessor = $request->input('predecessor_id');
        $outcome = $this->refusing(fn (): CustomerDecisionOutcome => $append(
            $request->user(), $request->header('Idempotency-Key'), $case, $request->integer('expected_generation'), $fields, $predecessor === null ? null : (int) $predecessor,
        ));

        return $this->written($outcome);
    }

    /**
     * A time with no offset is read in the stated source time zone (the company's when none is given), never in the server's or the database session's: a date typed as "10 November"
     * must be the start of that day where the customer's message was sent.
     */
    private function instant(mixed $value, mixed $zone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timezone = is_string($zone) && $zone !== '' ? $zone : $this->companyZone();

        return CarbonImmutable::parse((string) $value, $timezone)->toIso8601String();
    }

    private function companyZone(): string
    {
        return (string) Company::query()->whereKey(app(CompanyContext::class)->id())->value('timezone');
    }

    private function written(CustomerDecisionOutcome $outcome): JsonResponse
    {
        $response = (new CustomerDecisionOutcomeResource($outcome))->response()->setStatusCode($outcome->replayed ? 200 : 201);
        if ($outcome->replayed) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * Gives a refusal its documented status (CustomerDecisionRefusalStatus): 404 for a case that is not there, 409 for a key, a moved case or an intake identity that conflicts. Every
     * other refusal is rethrown to the global 422 renderer, which already carries the `reason_code`.
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
            $status = CustomerDecisionRefusalStatus::for($refusal->reasonCode);
            if ($status === CustomerDecisionRefusalStatus::NOT_FOUND) {
                throw (new ModelNotFoundException)->setModel(CustomerDecisionCase::class);
            }
            if ($status === CustomerDecisionRefusalStatus::CONFLICT) {
                throw new HttpResponseException(response()->json(array_filter([
                    'message' => $refusal->getMessage(), 'reason_code' => $refusal->reasonCode, 'errors' => [($refusal->field ?? 'id') => [$refusal->getMessage()]],
                ], fn ($value) => $value !== null), $status));
            }

            throw $refusal;
        }
    }
}
