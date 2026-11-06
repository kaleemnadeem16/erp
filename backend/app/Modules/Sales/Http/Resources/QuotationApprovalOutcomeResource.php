<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What an approval operation reports: the revision, the approval request and its status at the draft generation judged. A replay reports the original with replayed=true. Nothing monetary. */
final class QuotationApprovalOutcomeResource extends JsonResource
{
    /** @return array{family_id: int, revision_id: int, approval_request_id: int, status: string, input_generation: int, replayed: bool} */
    public function toArray(Request $request): array
    {
        /** @var array{family_id: int, revision_id: int, approval_request_id: int, status: string, input_generation: int, replayed: bool} $data */
        $data = $this->resource;

        return $data;
    }
}
