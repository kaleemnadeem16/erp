<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Release;

use App\Modules\Sales\Http\Requests\CustomerDecision\CustomerDecisionRequest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

/** Preliminary duty check only; the Action repeats it under the access fence and owns every business rule (evidence, scope declaration, digest). */
final class RecordCompletionBasisRequest extends CustomerDecisionRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayAll($this->user(), P::HANDOVER_COMPLETION_BASIS_RECORD, P::CUSTOMER_DECISION_VIEW);
    }

    protected function capability(): string
    {
        return P::HANDOVER_COMPLETION_BASIS_RECORD;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['agreed_text' => ['present', 'nullable', 'string'], 'evidence_id' => ['required', 'integer', 'min:1']];
    }
}
