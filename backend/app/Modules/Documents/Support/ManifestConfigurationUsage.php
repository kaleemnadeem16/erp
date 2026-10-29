<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Illuminate\Support\Facades\DB;

/**
 * The answer to "which configuration versions do live documents depend on?": a version is pinned when any frozen manifest of the company resolved to it. The
 * downgrade check in ActivateDocumentConfiguration refuses to leave a pinned version (DF1a/DF2).
 */
final class ManifestConfigurationUsage implements DocumentConfigurationUsage
{
    /** @param string $kind 'template' or 'page_policy'; anything else is refused rather than answered false. */
    public function isPinned(int $companyId, string $kind, int $versionId): bool
    {
        $column = match ($kind) {
            'template' => 'template_version_id',
            'page_policy' => 'page_policy_version_id',
            default => throw new DocumentException("Unknown configuration kind '{$kind}'.", 'kind', 'kind_unknown'),
        };

        return DB::table('document_manifests')->where('company_id', $companyId)->where($column, $versionId)->exists();
    }
}
