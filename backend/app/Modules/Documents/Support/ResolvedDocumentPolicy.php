<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The outcome of ResolveDocumentPolicy. The brand is reported as NOT_AVAILABLE until Company supplies a governed brand through its own contract; it is never
 * replaced by a fallback brand, so a caller cannot mistake an absent brand for a chosen one. $notes lists defaults that were skipped because the document
 * type's constraints override them.
 */
final readonly class ResolvedDocumentPolicy
{
    public const BRAND_NOT_AVAILABLE = 'NOT_AVAILABLE';

    /** @param list<string> $notes */
    public function __construct(
        public string $typeKey,
        public ResolvedComponent $template,
        public ResolvedComponent $pagePolicy,
        public array $notes = [],
        public string $brand = self::BRAND_NOT_AVAILABLE,
    ) {}
}
