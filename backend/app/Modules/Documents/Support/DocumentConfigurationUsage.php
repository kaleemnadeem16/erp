<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * Port: which configuration versions live documents depend on. Manifests (DF2) and assets (DF3/DF4) will answer it; until they exist nothing is pinned, so
 * the bound default reports none. Activation consults it before a downgrade and refuses if the version being left is still pinned.
 */
interface DocumentConfigurationUsage
{
    /** @param 'template'|'page_policy' $kind */
    public function isPinned(int $companyId, string $kind, int $versionId): bool;
}
