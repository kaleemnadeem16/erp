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

class QuotationController extends Controller
{
    public function show(ShowQuotationDraftRequest $request, int $revision, ReadQuotationDraft $read): QuotationDraftResource
    {
        return new QuotationDraftResource($read($request->user(), $revision));
    }

    public function preview(PreviewQuotationRequest $request, int $revision, PreviewQuotation $preview): QuotationPreviewResource
    {
        return new QuotationPreviewResource($preview($request->user(), $revision));
    }
}
