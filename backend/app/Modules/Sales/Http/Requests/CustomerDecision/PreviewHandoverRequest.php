<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

class PreviewHandoverRequest extends CustomerDecisionRequest
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
        return ['path' => ['sometimes', 'string', 'max:30'], 'location_kind' => ['sometimes', 'string', 'in:UNKNOWN,REMOTE,ON_SITE'],
            'contact_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'], 'instructions' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
