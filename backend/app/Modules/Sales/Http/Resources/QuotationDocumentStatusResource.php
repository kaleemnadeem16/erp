<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The safe status of an issued quotation's official PDF. `state` is PENDING, RUNNING, FAILED (with a stable `error_code`) or READY; a READY document says whether its
 * stored file can be handed out right now (`available`). It carries no storage key, path, hash, payload or owner identity, and no prices.
 *
 * @property array{revision_id: int, state: string, error_code: string|null, artefact: array{id: int, pages: int, size_bytes: int, media_type: string, created_at: string, available: bool}|null} $resource
 */
class QuotationDocumentStatusResource extends JsonResource
{
    /** @return array{revision_id: int, state: string, error_code: string|null, artefact: array{id: int, pages: int, size_bytes: int, media_type: string, created_at: string, available: bool}|null} */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
