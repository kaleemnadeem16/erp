<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Release;

use App\Modules\Sales\Http\Requests\CustomerDecision\CustomerDecisionRequest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

/** Preliminary duty check only; the Action repeats it under the access fence. Whoever may record the conditions or release the service may read the current ones. */
final class ShowCompletionBasisRequest extends CustomerDecisionRequest
{
    public function authorize(): bool
    {
        return SalesAccess::may($this->user(), P::CUSTOMER_DECISION_VIEW) && (SalesAccess::may($this->user(), P::HANDOVER_RELEASE) || SalesAccess::may($this->user(), P::HANDOVER_COMPLETION_BASIS_RECORD));
    }

    protected function capability(): string
    {
        return P::HANDOVER_COMPLETION_BASIS_RECORD;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [];
    }
}
