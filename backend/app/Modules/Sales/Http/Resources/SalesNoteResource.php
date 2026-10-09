<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SalesNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesNote */
class SalesNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SalesNote $note */
        $note = $this->resource;

        return [
            'id' => $note->id,
            'body' => $note->body,
            'author_id' => $note->author_id,
            'author_name' => $note->relationLoaded('author') ? $note->author?->name : null,
            'created_at' => $note->created_at->toIso8601String(),
        ];
    }
}
