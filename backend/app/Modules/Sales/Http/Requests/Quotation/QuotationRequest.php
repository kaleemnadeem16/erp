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
        if ($this instanceof EditQuotationDraftRequest || $this instanceof PreviewQuotationRequest || $this instanceof UpdateQuotationDraftRequest) {
            $duties[] = PermissionVocabulary::QUOTATION_VIEW;
        }
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->queryFor($actor, $duties));

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
