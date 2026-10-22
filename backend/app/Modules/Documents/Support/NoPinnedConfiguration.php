<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** DF1a: no manifests or assets exist yet, so no version is pinned. Replaced by the manifest-backed implementation in DF2. */
final class NoPinnedConfiguration implements DocumentConfigurationUsage
{
    public function isPinned(int $companyId, string $kind, int $versionId): bool
    {
        return false;
    }
}
