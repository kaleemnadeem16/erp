<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The outcome of ResolveDocumentPolicy. The brand is NOT_AVAILABLE unless Company supplies a governed brand through its own contract (ADR 0162), in which case it is PINNED
 * and carries the brand text and the pinned asset references; it is never replaced by a fallback brand, so a caller cannot mistake an absent brand for a chosen one. $notes
 * lists defaults that were skipped because the document type's constraints override them.
 */
final readonly class ResolvedDocumentPolicy
{
    public const BRAND_NOT_AVAILABLE = 'NOT_AVAILABLE';

    public const BRAND_PINNED = 'PINNED';

    /**
     * @param  list<string>  $notes
     * @param  array{version: int, display_name: string|null, tagline: string|null}|null  $brandPin  the brand text and version, when the brand is PINNED
     * @param  list<array<string, mixed>>  $brandAssets  the pinned asset references (the logo, with its hash), when the brand is PINNED
     */
    public function __construct(
        public string $typeKey,
        public ResolvedComponent $template,
        public ResolvedComponent $pagePolicy,
        public array $notes = [],
        public string $brand = self::BRAND_NOT_AVAILABLE,
        public ?array $brandPin = null,
        public array $brandAssets = [],
    ) {}
}
