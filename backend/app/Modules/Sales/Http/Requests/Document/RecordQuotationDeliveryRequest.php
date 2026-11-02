<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Document;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** Records that the PDF was handed over outside the system: `sales.delivery.record`. Default-deny: the duty is STAGED, so nobody passes until it is activated; the Action asks again and also needs the right to see the quotation. */
class RecordQuotationDeliveryRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::DELIVERY_RECORD;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:HAND,COURIER,POST,OTHER'],
            'delivered_on' => ['required', 'date_format:Y-m-d'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'recipient_detail' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
            'corrects_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
