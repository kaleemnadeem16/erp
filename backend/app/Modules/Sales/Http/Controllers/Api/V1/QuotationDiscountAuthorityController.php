<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Modules\Sales\Actions\BindQuotationAuthorityReferences;
use App\Modules\Sales\Actions\ReadQuotationDiscountAuthority;
use App\Modules\Sales\Http\Requests\Quotation\ReadQuotationDiscountAuthorityRequest;
use App\Modules\Sales\Http\Requests\Quotation\RequestQuotationApprovalRequest;
use App\Modules\Sales\Http\Resources\QuotationAuthorityBindingResource;
use App\Modules\Sales\Http\Resources\QuotationDiscountAuthorityResource;
use Dedoc\Scramble\Attributes\HeaderParameter;

class QuotationDiscountAuthorityController extends AuthorityApiController
{
    public function show(ReadQuotationDiscountAuthorityRequest $request, int $revision, ReadQuotationDiscountAuthority $action): QuotationDiscountAuthorityResource
    {
        return new QuotationDiscountAuthorityResource($action($request->user(), $revision));
    }

    public function history(ReadQuotationDiscountAuthorityRequest $request, int $revision, ReadQuotationDiscountAuthority $action): QuotationDiscountAuthorityResource
    {
        return new QuotationDiscountAuthorityResource($action($request->user(), $revision, true, $request->integer('page', 1), $request->integer('per_page', 25)));
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact binding operation; 1 to 85 characters.', required: true, type: 'string')]
    public function bind(RequestQuotationApprovalRequest $request, int $revision, BindQuotationAuthorityReferences $action): QuotationAuthorityBindingResource
    {
        return new QuotationAuthorityBindingResource($action($request->user(), $revision, $request->integer('expected_generation'), (string) $request->header('Idempotency-Key')));
    }
}
