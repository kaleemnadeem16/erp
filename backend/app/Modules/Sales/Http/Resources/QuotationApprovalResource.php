<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where a draft stands with its approval: the state for the draft as it is now, the latest request with its decisions (who, what, when, the written reason) and what the caller may
 * do. Codes, names and written reasons only - never an amount, a limit, an authority ceiling or a cost.
 */
final class QuotationApprovalResource extends JsonResource
{
    /**
     * @return array{
     *     revision_id: int, input_generation: int, is_draft: bool, approval_needed: bool, state: string, superseded: bool,
     *     request: array{id: int, status: string, requested_at: string, requested_by: array{id: int, name: string}|null, closed_at: string|null, closing_reason: string|null, concessions: string|null,
     *         decisions: list<array{decision: string, reason: string|null, decided_at: string, decided_by: array{id: int, name: string}|null}>}|null,
     *     can: array{request: bool, withdraw: bool, decide: bool}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{revision_id: int, input_generation: int, is_draft: bool, approval_needed: bool, state: string, superseded: bool, request: array{id: int, status: string, requested_at: string, requested_by: array{id: int, name: string}|null, closed_at: string|null, closing_reason: string|null, concessions: string|null, decisions: list<array{decision: string, reason: string|null, decided_at: string, decided_by: array{id: int, name: string}|null}>}|null, can: array{request: bool, withdraw: bool, decide: bool}} $data */
        $data = $this->resource;

        return $data;
    }
}
