<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\Review\ApprovalReviewAccess;
use App\Modules\Approvals\Support\Review\ReviewProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalRequest */
class ApprovalDocumentReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApprovalRequest $row */
        $row = $this->resource;
        $profile = app(ApprovalReviewAccess::class)->profile($request->user(), $row);
        $snapshot = $row->facts['document_review'] ?? [];
        $sections = $profile === null ? [] : $snapshot['operational'];
        if ($profile === ReviewProfile::Commercial) {
            $sections = array_merge($sections, $snapshot['commercial']);
        }

        return [
            'available' => $profile !== null, 'profile' => $profile?->value,
            'captured_at' => $profile === null ? null : (string) $snapshot['captured_at'],
            'sections' => ApprovalReviewSectionResource::collection($sections),
        ];
    }
}
