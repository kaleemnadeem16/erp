<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Reading where a draft stands with its approval: a preparer (prepare) or a decider (approve), each with the independent view (all STAGED). Either path is enough; the Action judges the record. */
class ShowQuotationApprovalRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null) {
            return false;
        }
        foreach ([PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_APPROVE] as $duty) {
            try {
                DB::transaction(fn () => app(QuotationAccess::class)->queryFor($actor, [$duty, PermissionVocabulary::QUOTATION_VIEW]));

                return true;
            } catch (AuthorizationException) {
                continue;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
