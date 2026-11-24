<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

/** The same preliminary duties as the preview; it takes no input at all. */
final class ShowHandoverOptionsRequest extends CustomerDecisionRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayAll($this->user(), P::HANDOVER_PREPARE, P::CUSTOMER_DECISION_VIEW);
    }

    protected function capability(): string
    {
        return P::HANDOVER_PREPARE;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [];
    }
}
