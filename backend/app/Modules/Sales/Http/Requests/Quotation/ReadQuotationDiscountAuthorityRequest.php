<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Support\Facades\DB;

class ReadQuotationDiscountAuthorityRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_VIEW;
    }

    public function authorize(): bool
    {
        return DB::transaction(function (): bool {
            app(CompanyAccessFence::class)->lock();
            $person = $this->user();

            return parent::authorize() && $person !== null && SalesAccess::may(ActiveActor::current($person), PermissionVocabulary::AUTHORITY_EVIDENCE_VIEW);
        });
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
