<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the official PDF's timeline: GENERATED, DOWNLOAD_DELIVERED / PRINT_DELIVERED (a hand-off, never receipt by the customer), *_UNAVAILABLE, MISSING_DETECTED,
 * QUARANTINED or RESTORED. The detail holds stable words and ids only.
 *
 * @property array{at: string, event: string, detail: array<string, string|int|null>} $resource
 */
class QuotationDocumentHistoryResource extends JsonResource
{
    /** @return array{at: string, event: string, detail: array<string, string|int|null>} */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
