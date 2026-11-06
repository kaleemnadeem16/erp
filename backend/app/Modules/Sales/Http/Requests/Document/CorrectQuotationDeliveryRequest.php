<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Document;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/**
 * Corrects or withdraws a recorded manual delivery by appending a new record (`sales.delivery.record`; the duty is STAGED, so nobody passes until it is activated, and the Action
 * asks again). `withdraw` = true says the delivery did not happen as recorded and carries no replacement values; otherwise the replacement values are required. A reason is always required.
 */
class CorrectQuotationDeliveryRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::DELIVERY_RECORD;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'withdraw' => ['nullable', 'boolean'],
            'channel' => ['exclude_if:withdraw,true', 'required', 'string', 'in:HAND,COURIER,POST,OTHER'],
            'delivered_on' => ['exclude_if:withdraw,true', 'required', 'date_format:Y-m-d'],
            'recipient_name' => ['exclude_if:withdraw,true', 'required', 'string', 'max:200'],
            'recipient_detail' => ['exclude_if:withdraw,true', 'nullable', 'string', 'max:200'],
            'note' => ['exclude_if:withdraw,true', 'nullable', 'string', 'max:1000'],
        ];
    }
}
