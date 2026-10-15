<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use App\Modules\Parties\Models\PartyContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PartyContact */
class PartyContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PartyContact $contact */
        $contact = $this->resource;

        return [
            'id' => $contact->id,
            'party_id' => $contact->party_id,
            'name' => $contact->name,
            'title' => $contact->title,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'is_primary' => $contact->is_primary,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }
}
