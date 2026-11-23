<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Release;

/** The releaser names only what they reviewed: the exact basis and readiness fingerprint. Nothing else is client-supplied. */
final class ReleaseServiceRequest extends PreviewServiceReleaseRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['basis_id' => ['required', 'integer', 'min:1'], 'readiness_fingerprint' => ['required', 'string', 'max:128']];
    }
}
