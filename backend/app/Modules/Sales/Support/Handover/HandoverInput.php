<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover;

use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;
use App\Modules\Sales\Support\Kernel\OperationIntent;

/** @internal Operational context only; no client commercial terms, readiness assertion or completion-condition editor. */
final class HandoverInput
{
    /**
     * @param  array<string,mixed>  $fields
     * @return array{path:string,location_kind:string,contact_id:int|null,site_id:int|null,instructions:string}
     */
    public static function normalise(array $fields): array
    {
        if (array_diff(array_keys($fields), ['path', 'location_kind', 'contact_id', 'site_id', 'instructions']) !== []) {
            throw R::make(R::INVALID_INPUT, 'Only operational handover fields are accepted.');
        }
        $path = $fields['path'] ?? 'SERVICE_JOB';
        if (! in_array($path, ['SERVICE_JOB', 'GOODS_ORDER'], true)) {
            throw R::make(R::HANDOVER_PATH_UNSUPPORTED, 'This handover path is not supported.', 'path');
        }
        $location = $fields['location_kind'] ?? 'UNKNOWN';
        if (! in_array($location, ['UNKNOWN', 'REMOTE', 'ON_SITE'], true)) {
            throw R::make(R::INVALID_INPUT, 'Choose remote or on-site service.', 'location_kind');
        }
        foreach (['contact_id', 'site_id'] as $field) {
            if (isset($fields[$field]) && (! is_int($fields[$field]) || $fields[$field] < 1)) {
                throw R::make(R::INVALID_INPUT, 'A selected operational identity must be positive.', $field);
            }
        }
        $instructions = trim((string) ($fields['instructions'] ?? ''));
        if (mb_strlen($instructions) > 2000 || ($location !== 'ON_SITE' && isset($fields['site_id']))) {
            throw R::make(R::INVALID_INPUT, 'Instructions are limited to 2000 characters; a site belongs only to on-site service.');
        }

        return ['path' => $path, 'location_kind' => $location, 'contact_id' => $fields['contact_id'] ?? null, 'site_id' => $fields['site_id'] ?? null, 'instructions' => $instructions];
    }

    /** @param array<string,mixed> $inputs */
    public static function digest(int $acceptanceId, array $inputs): string
    {
        return OperationIntent::hash('handover.prepare', ['acceptance_id' => $acceptanceId, 'inputs' => $inputs]);
    }
}
