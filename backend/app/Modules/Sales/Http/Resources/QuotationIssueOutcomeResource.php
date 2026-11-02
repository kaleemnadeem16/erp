<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What an issue reports: the revision, its one official number and the database's instant of issue; a replay reports the original with replayed=true. Nothing monetary. */
final class QuotationIssueOutcomeResource extends JsonResource
{
    /** @return array{family_id: int, revision_id: int, issue_id: int, official_number: string, issued_at: string, replayed: bool} */
    public function toArray(Request $request): array
    {
        /** @var array{family_id: int, revision_id: int, issue_id: int, official_number: string, issued_at: string, replayed: bool} $data */
        $data = $this->resource;

        return $data;
    }
}
