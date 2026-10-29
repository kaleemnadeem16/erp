<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\DownloadQuotationDocument;
use App\Modules\Sales\Actions\ReadQuotationDocumentDeliveries;
use App\Modules\Sales\Actions\ReadQuotationDocumentHistory;
use App\Modules\Sales\Actions\ReadQuotationDocumentStatus;
use App\Modules\Sales\Actions\RecordManualQuotationDelivery;
use App\Modules\Sales\Actions\RepairQuotationDocument;
use App\Modules\Sales\Actions\RequestQuotationDocument;
use App\Modules\Sales\Http\Requests\Document\CheckQuotationDocumentRequest;
use App\Modules\Sales\Http\Requests\Document\DownloadQuotationDocumentRequest;
use App\Modules\Sales\Http\Requests\Document\ListQuotationDeliveriesRequest;
use App\Modules\Sales\Http\Requests\Document\RecordQuotationDeliveryRequest;
use App\Modules\Sales\Http\Requests\Document\RequestQuotationDocumentRequest;
use App\Modules\Sales\Http\Requests\Document\ShowQuotationDocumentRequest;
use App\Modules\Sales\Http\Resources\QuotationDeliveryResource;
use App\Modules\Sales\Http\Resources\QuotationDocumentHistoryResource;
use App\Modules\Sales\Http\Resources\QuotationDocumentStatusResource;
use App\Modules\Sales\Models\QuotationRevision;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The official PDF of an ISSUED quotation and its manual delivery evidence. Every route is guarded twice (the Request's duty, then the Action's duty plus the right to see the
 * quotation); the duties are STAGED, so every caller is refused until they are activated. A quotation that is unknown, in another company or not issued is a plain 404.
 * Nothing here emails, shares a link or signs a URL.
 */
class QuotationDocumentController extends Controller
{
    private const IDEMPOTENCY = 'Chosen by the client for each distinct request (1 to 120 characters, unique per person). Retrying the same request with the same key returns the stored result; the same key with different content is refused (409, reason_code DELIVERY_KEY_REUSED).';

    public function show(ShowQuotationDocumentRequest $request, int $revision, ReadQuotationDocumentStatus $status): QuotationDocumentStatusResource
    {
        return new QuotationDocumentStatusResource($this->refusing(fn () => $status($request->user(), $this->revisionId($revision))));
    }

    /** Generates the PDF from the original frozen at issue, or retries a failed generation. A failed generation is a status (`FAILED` with `error_code`), not an error. */
    #[HeaderParameter('Idempotency-Key', self::IDEMPOTENCY)]
    public function generate(RequestQuotationDocumentRequest $request, int $revision, RequestQuotationDocument $generate): QuotationDocumentStatusResource
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || mb_strlen($key) > 120) {
            throw new HttpResponseException(response()->json(['message' => 'An Idempotency-Key header of 1 to 120 characters is required.', 'reason_code' => 'REQUEST_KEY_REQUIRED', 'errors' => ['idempotency_key' => ['Required.']]], 422));
        }

        return new QuotationDocumentStatusResource($this->refusing(fn () => $generate($request->user(), $this->revisionId($revision), $key)));
    }

    /** The stored official PDF as an attachment (`?intent=print` records the hand-off as a print). Never a link: the bytes are streamed after the owner's decision and a hash check. */
    #[Response(200, 'The PDF bytes (application/pdf) as an attachment; Cache-Control: private, no-store.', type: 'string')]
    public function download(DownloadQuotationDocumentRequest $request, int $revision, DownloadQuotationDocument $download): StreamedResponse
    {
        $file = $this->refusing(fn () => $download($request->user(), $this->revisionId($revision), $request->query('intent') === 'print' ? 'PRINT' : 'DOWNLOAD'));

        return response()->stream(function () use ($file): void {
            fpassthru($file->stream);
            fclose($file->stream);
        }, 200, $file->headers());
    }

    public function history(ShowQuotationDocumentRequest $request, int $revision, ReadQuotationDocumentHistory $history): AnonymousResourceCollection
    {
        return QuotationDocumentHistoryResource::collection($this->refusing(fn () => $history($request->user(), $this->revisionId($revision))));
    }

    /** Compares the stored file with its recorded hash (operator). A missing or corrupt file is marked and reported; nothing is deleted or regenerated. */
    #[Response(200, 'The outcome: ok, missing, corrupt or unchanged.', type: 'array{data: array{outcome: string}}')]
    public function check(CheckQuotationDocumentRequest $request, int $revision, RepairQuotationDocument $repair): JsonResponse
    {
        return response()->json(['data' => ['outcome' => $this->refusing(fn () => $repair->check($request->user(), $this->revisionId($revision)))]]);
    }

    public function deliveries(ListQuotationDeliveriesRequest $request, int $revision, ReadQuotationDocumentDeliveries $read): AnonymousResourceCollection
    {
        return QuotationDeliveryResource::collection($this->refusing(fn () => $read($request->user(), $this->revisionId($revision))));
    }

    /** Records that the official PDF was handed over by hand or outside the system. A statement by a person: not receipt, reading or acceptance by the customer. */
    #[HeaderParameter('Idempotency-Key', self::IDEMPOTENCY)]
    #[Response(201, 'The delivery was recorded.', type: 'array{data: QuotationDeliveryResource}')]
    #[Response(200, 'An exact retry: the stored record, with the header Idempotency-Replayed: true.', type: 'array{data: QuotationDeliveryResource}')]
    #[Response(409, 'The Idempotency-Key was already used for a different record (reason_code DELIVERY_KEY_REUSED).', type: 'array{message: string, reason_code: string, errors: array<string, list<string>>}')]
    public function recordDelivery(RecordQuotationDeliveryRequest $request, int $revision, RecordManualQuotationDelivery $record): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        $result = $this->refusing(fn () => $record($request->user(), $this->revisionId($revision), [
            'channel' => (string) $request->input('channel'), 'delivered_on' => (string) $request->input('delivered_on'), 'recipient_name' => (string) $request->input('recipient_name'),
            'recipient_detail' => $request->input('recipient_detail'), 'note' => $request->input('note'),
        ], $key, $request->filled('corrects_id') ? $request->integer('corrects_id') : null));
        $response = (new QuotationDeliveryResource($result['delivery']))->response()->setStatusCode($result['replayed'] ? 200 : 201);
        if ($result['replayed']) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /** A quotation that is not in the caller's company is indistinguishable from one that does not exist: 404. */
    private function revisionId(int $revision): int
    {
        return QuotationRevision::query()->findOrFail($revision)->id;
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
            $code = (string) $refusal->reasonCode;
            if ($code === 'DOCUMENT_NOT_FOUND' || $code === 'artefact_not_found') {
                throw (new ModelNotFoundException)->setModel(QuotationRevision::class);
            }
            if ($code === 'access_denied') {
                throw new AuthorizationException('You are not permitted to do that.');
            }
            if ($code === 'DELIVERY_KEY_REUSED') {
                throw new HttpResponseException(response()->json(['message' => $refusal->getMessage(), 'reason_code' => $code, 'errors' => [($refusal->field ?? 'id') => [$refusal->getMessage()]]], 409));
            }

            throw $refusal;
        }
    }
}
