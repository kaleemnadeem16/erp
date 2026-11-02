<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Whether a draft could be issued now, as named blockers (codes and scope only: no amount, cost, rate or free text). */
final class QuotationIssueReadinessResource extends JsonResource
{
    /** @return array{revision_id: int, input_generation: int, issue_ready: bool, blockers: list<array{code: string, scope: string, line_id: int|null}>} */
    public function toArray(Request $request): array
    {
        /** @var array{revision_id: int, input_generation: int, issue_ready: bool, blockers: list<array{code: string, scope: string, line_id: int|null}>} $data */
        $data = $this->resource;

        return $data;
    }
}
