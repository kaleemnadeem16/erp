<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use Illuminate\Http\Request;

/** HTTP coercion only, shared by preview, prepare and readiness. Domain validation lives in HandoverInput. */
final class HandoverFields
{
    /** @return array<string,mixed> */
    public static function from(Request $request): array
    {
        $fields = $request->only(['path', 'location_kind', 'contact_id', 'site_id', 'instructions']);
        foreach (['contact_id', 'site_id'] as $id) {
            if (isset($fields[$id])) {
                $fields[$id] = $request->integer($id);
            }
        }

        return $fields;
    }
}
