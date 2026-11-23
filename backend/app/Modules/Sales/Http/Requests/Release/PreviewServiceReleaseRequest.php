<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Release;

use App\Modules\Sales\Http\Requests\CustomerDecision\CustomerDecisionRequest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

/** Preliminary duty check only; the Action repeats it under the access fence. The receiver's own `job.receive` is judged by the release, never here. */
class PreviewServiceReleaseRequest extends CustomerDecisionRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayAll($this->user(), P::HANDOVER_RELEASE, P::CUSTOMER_DECISION_VIEW);
    }

    protected function capability(): string
    {
        return P::HANDOVER_RELEASE;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [];
    }
}
