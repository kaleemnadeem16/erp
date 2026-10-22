<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The curated, code-owned layout catalogue. A template names one of these ids; tenant configuration never supplies CSS, HTML, script or a layout of its
 * own (ADR 0137). A new layout is a code change reviewed with the renderer that draws it.
 */
final class DocumentLayouts
{
    public const STANDARD = 'standard-table';

    public const COMPACT = 'compact-table';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::STANDARD, self::COMPACT];
    }

    public static function has(string $id): bool
    {
        return in_array($id, self::all(), true);
    }
}
