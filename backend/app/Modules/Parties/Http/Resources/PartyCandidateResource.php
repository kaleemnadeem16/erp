<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One probable match for an identity being entered (ADR 0105): enough to decide, never the whole record. */
class PartyCandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{id: int, code: string, name: string, kind: string, status: string, matched_on: list<string>} $candidate */
        $candidate = $this->resource;

        return [
            /** @var int */
            'id' => $candidate['id'],
            /** @var string */
            'code' => $candidate['code'],
            /** @var string */
            'name' => $candidate['name'],
            /** @var 'ORGANISATION'|'INDIVIDUAL' */
            'kind' => $candidate['kind'],
            /** @var 'ACTIVE'|'ARCHIVED' */
            'status' => $candidate['status'],
            /** @var array<int, string> */
            'matched_on' => $candidate['matched_on'],
        ];
    }
}
