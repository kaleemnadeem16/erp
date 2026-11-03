<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Preliminary duty check; the Action repeats current duty and family policy under its own fence. */
abstract class QuotationRequest extends SalesRequest
{
    /**
     * Further duties this particular body needs (a field-level duty, e.g. a discount): judged by the same family policy, so a caller without one is refused before any lookup.
     *
     * @return list<string>
     */
    protected function extraDuties(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        if ($actor === null) {
            return false;
        }
        $capability = $this->capability();
        if ($capability === null) {
            return false;
        }
        $duties = [$capability];
        if ($this instanceof EditQuotationDraftRequest || $this instanceof ShowQuotationTaxDeclarationsRequest || $this instanceof PreviewQuotationRequest || $this instanceof UpdateQuotationDraftRequest
            || $this instanceof AssessQuotationIssueReadinessRequest || $this instanceof IssueQuotationRequest) {
            $duties[] = PermissionVocabulary::QUOTATION_VIEW;
        }
        array_push($duties, ...$this->extraDuties());
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->queryFor($actor, $duties));

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
