<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** An upload that was read in full and refused on its content: it carries what was observed (hash, size, sniffed type) so the refusal can be recorded as evidence. */
final class FileRejection extends FileException
{
    public function __construct(string $message, ?string $field, string $reasonCode, public readonly string $sha256, public readonly int $size, public readonly string $detectedMediaType)
    {
        parent::__construct($message, $field, $reasonCode);
    }
}
