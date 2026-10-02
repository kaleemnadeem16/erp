<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** What the engine returns: the final number as plain text, and the facts recorded with it. */
final class AllocatedNumber
{
    /** @param  array<string, array{value: string, source: string|null}>  $variables */
    public function __construct(
        public readonly string $number,
        public readonly int $allocationId,
        public readonly string $seriesCode,
        public readonly int $versionId,
        public readonly string $counterKey,
        public readonly int $counterValue,
        public readonly array $variables,
    ) {}
}
