<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\QuotationDocumentDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person's record that the official PDF was handed to the customer outside the system. It is NOT receipt, reading or acceptance by the customer. A correction names the
 * record it replaces (`corrects_id`).
 *
 * @property QuotationDocumentDelivery $resource
 */
class QuotationDeliveryResource extends JsonResource
{
    /** @return array{id: int, revision_id: int, recorded_by: int, channel: string, delivered_on: string, recipient_name: string, recipient_detail: string|null, note: string|null, corrects_id: int|null, recorded_at: string} */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => $d->id, 'revision_id' => $d->revision_id, 'recorded_by' => $d->recorded_by, 'channel' => $d->channel, 'delivered_on' => $d->delivered_on->format('Y-m-d'),
            'recipient_name' => $d->recipient_name, 'recipient_detail' => $d->recipient_detail, 'note' => $d->note, 'corrects_id' => $d->corrects_id, 'recorded_at' => $d->recorded_at->toIso8601String(),
        ];
    }
}
