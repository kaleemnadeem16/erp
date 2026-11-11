<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

final class AssessAcceptanceReadinessRequest extends ShowCustomerAcceptanceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['path' => ['sometimes', 'string', 'in:SERVICE_JOB,GOODS_ORDER']];
    }
}
