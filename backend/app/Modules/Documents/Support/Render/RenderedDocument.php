<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

/** The result of a render: the bytes, how many pages they hold and their SHA-256. */
final readonly class RenderedDocument
{
    public function __construct(public string $bytes, public int $pages, public string $sha256) {}
}
