<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\Access\GrantQuotationAccess;
use App\Modules\Sales\Actions\Access\ReadQuotationAccessCandidates;
use App\Modules\Sales\Actions\Access\ReadQuotationFamilyAccess;
use App\Modules\Sales\Actions\Access\RevokeQuotationAccess;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Http\Requests\QuotationAccess\ChangeQuotationAccessUnitRequest;
use App\Modules\Sales\Http\Requests\QuotationAccess\GrantQuotationAccessRequest;
use App\Modules\Sales\Http\Requests\QuotationAccess\QuotationAccessCandidatesRequest;
use App\Modules\Sales\Http\Requests\QuotationAccess\ReassignQuotationOwnerRequest;
use App\Modules\Sales\Http\Requests\QuotationAccess\RevokeQuotationAccessRequest;
use App\Modules\Sales\Http\Requests\QuotationAccess\ShowQuotationAccessRequest;
use App\Modules\Sales\Http\Resources\QuotationAccessCandidateResource;
use App\Modules\Sales\Http\Resources\QuotationFamilyAccessResource;
use App\Modules\Sales\Http\Resources\QuotationOwnershipOutcomeResource;
use App\Modules\Sales\Http\Resources\QuotationShareOutcomeResource;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Quotation\QuotationRefusalStatus;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Family administration is separate from immutable quotation revisions and their commercial facts. */
class QuotationFamilyAccessController extends Controller
{
    #[Response(404, 'Unknown, foreign and hidden families have the same non-disclosing response.', type: 'array{message:string}')]
    public function show(ShowQuotationAccessRequest $request, int $family, ReadQuotationFamilyAccess $read): QuotationFamilyAccessResource
    {
        return new QuotationFamilyAccessResource($this->refusing(fn () => $read($request->user(), $family, $request->validated())));
    }

    /** Active company directory identities for this family's governed operation; the write Action decides eligibility. */
    #[Response(200, 'Cursor-paginated directory identities; final grant eligibility is checked by the write Action.', type: 'array{data:list<QuotationAccessCandidateResource>,links:array{first:null,last:null,prev:string|null,next:string|null},meta:array{path:string,per_page:int,next_cursor:string|null,prev_cursor:string|null}}')]
    #[Response(404, 'Unknown, foreign and hidden families have the same non-disclosing response.', type: 'array{message:string}')]
    public function candidates(QuotationAccessCandidatesRequest $request, int $family, ReadQuotationAccessCandidates $read): AnonymousResourceCollection
    {
        return QuotationAccessCandidateResource::collection($this->refusing(fn () => $read($request->user(), $family, $request->string('kind')->toString(), $request->input('level') ?? 'VIEW', $request->safe()->except(['kind', 'level']))));
    }

    #[HeaderParameter('Idempotency-Key', 'Unique request key (1 to 85 characters); identical retries replay the original outcome.', required: true, type: 'string')]
    #[Response(200, 'Success or identical retry; replay also sets Idempotency-Replayed: true.', type: 'array{data: QuotationOwnershipOutcomeResource}')]
    #[Response(404, 'Unknown, foreign and hidden families have the same non-disclosing response.', type: 'array{message:string}')]
    #[Response(409, 'The request key belongs to another intent.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function reassign(ReassignQuotationOwnerRequest $request, int $family, ReassignQuotationOwner $reassign): JsonResponse
    {
        $outcome = $this->refusing(fn () => $reassign($request->user(), $family, $request->integer('owner_id'), $request->integer('expected_ownership_generation'), $request->integer('expected_access_generation'), $request->header('Idempotency-Key'), $request->string('reason')->toString()));

        return $this->written(new QuotationOwnershipOutcomeResource($outcome), $outcome->replayed);
    }

    #[HeaderParameter('Idempotency-Key', 'Unique request key (1 to 85 characters); identical retries replay the original outcome.', required: true, type: 'string')]
    #[Response(200, 'Success or identical retry; replay also sets Idempotency-Replayed: true.', type: 'array{data: QuotationOwnershipOutcomeResource}')]
    #[Response(404, 'Unknown, foreign and hidden families have the same non-disclosing response.', type: 'array{message:string}')]
    #[Response(409, 'The request key belongs to another intent.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function unit(ChangeQuotationAccessUnitRequest $request, int $family, ChangeQuotationAccessUnit $change): JsonResponse
    {
        $outcome = $this->refusing(fn () => $change($request->user(), $family, $request->input('access_unit_id') === null ? null : $request->integer('access_unit_id'), $request->integer('expected_ownership_generation'), $request->integer('expected_access_generation'), $request->header('Idempotency-Key'), $request->string('reason')->toString()));

        return $this->written(new QuotationOwnershipOutcomeResource($outcome), $outcome->replayed);
    }

    #[HeaderParameter('Idempotency-Key', 'Unique request key (1 to 85 characters); identical retries replay the original outcome.', required: true, type: 'string')]
    #[Response(200, 'Success or identical retry; replay also sets Idempotency-Replayed: true.', type: 'array{data: QuotationShareOutcomeResource}')]
    #[Response(404, 'Unknown, foreign and hidden families have the same non-disclosing response.', type: 'array{message:string}')]
    #[Response(409, 'The request key belongs to another intent.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    #[Response(201, 'A new audited share was created.', type: 'array{data: QuotationShareOutcomeResource}')]
    public function grant(GrantQuotationAccessRequest $request, int $family, GrantQuotationAccess $grant): JsonResponse
    {
        $outcome = $this->refusing(fn () => $grant($request->user(), $family, $request->integer('recipient_id'), $request->string('level')->toString(), $request->integer('expected_ownership_generation'), $request->integer('expected_access_generation'), $request->header('Idempotency-Key'), $request->string('reason')->toString(), $request->input('starts_at'), $request->input('ends_at')));

        return $this->written(new QuotationShareOutcomeResource($outcome), $outcome->replayed, $outcome->changed && ! $outcome->replayed ? 201 : 200);
    }

    #[HeaderParameter('Idempotency-Key', 'Unique request key (1 to 85 characters); identical retries replay the original outcome.', required: true, type: 'string')]
    #[Response(200, 'Success or identical retry; replay also sets Idempotency-Replayed: true.', type: 'array{data: QuotationShareOutcomeResource}')]
    #[Response(404, 'Unknown, foreign and hidden families have the same non-disclosing response.', type: 'array{message:string}')]
    #[Response(409, 'The request key belongs to another intent.', type: 'array{message:string,reason_code:string,errors:array<string,list<string>>}')]
    public function revoke(RevokeQuotationAccessRequest $request, int $family, RevokeQuotationAccess $revoke): JsonResponse
    {
        $outcome = $this->refusing(fn () => $revoke($request->user(), $family, $request->integer('grant_id'), $request->integer('expected_ownership_generation'), $request->integer('expected_access_generation'), $request->header('Idempotency-Key'), $request->string('reason')->toString()));

        return $this->written(new QuotationShareOutcomeResource($outcome), $outcome->replayed);
    }

    private function written(QuotationOwnershipOutcomeResource|QuotationShareOutcomeResource $resource, bool $replayed, int $status = 200): JsonResponse
    {
        $response = $resource->response()->setStatusCode($status);
        if ($replayed) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /** @template T
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
                throw (new ModelNotFoundException)->setModel(QuotationFamily::class);
            }
            if ($status === QuotationRefusalStatus::CONFLICT) {
                throw new HttpResponseException(response()->json(['message' => $refusal->getMessage(), 'reason_code' => $refusal->reasonCode, 'errors' => [($refusal->field ?? 'id') => [$refusal->getMessage()]]], $status));
            }
            throw $refusal;
        }
    }
}
