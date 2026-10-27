<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Actions\ReadQuotationDraft;
use App\Modules\Sales\Http\Requests\Quotation\PreviewQuotationRequest;
use App\Modules\Sales\Http\Requests\Quotation\ShowQuotationDraftRequest;
use App\Modules\Sales\Http\Resources\QuotationDraftResource;
use App\Modules\Sales\Http\Resources\QuotationPreviewResource;
use App\Modules\Sales\Models\QuotationRevision;

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

    public function preview(PreviewQuotationRequest $request, int $revision, PreviewQuotation $preview): QuotationPreviewResource
    {
        return new QuotationPreviewResource($preview($request->user(), $this->revisionId($revision)));
    }
}
