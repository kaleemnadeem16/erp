<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** An upload that passed every check, held in a private temporary file until it is stored. The handle must stay referenced for the file to exist. */
final class ValidatedUpload
{
    /** @param resource $handle */
    public function __construct(public $handle, public readonly string $sha256, public readonly int $size, public readonly string $mediaType, public readonly ?string $declaredMediaType) {}
}
