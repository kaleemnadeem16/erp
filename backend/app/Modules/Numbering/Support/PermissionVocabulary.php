<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/**
 * Numbering-owned capabilities registered through the shared permission registry. A document type's own manage permission
 * (for example purchasing.numbering.manage) is registered by the module that owns the document.
 */
final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'numbering.view',
            'numbering.series.select',
            'numbering.counter.raise',
            'numbering.constant.manage',
        ];
    }
}
