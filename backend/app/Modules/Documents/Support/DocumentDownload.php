<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * What an authorised download hands the (future) delivery layer: the verified bytes as a stream and the headers it must send. Always an attachment with a safe generated
 * name, a fixed media type and no-store caching; no storage key, path, URL or token is ever part of it.
 */
final class DocumentDownload
{
    /** @param resource $stream */
    public function __construct(public $stream, public readonly string $filename, public readonly string $mediaType, public readonly int $size, public readonly string $sha256) {}

    /** @return array<string, string> */
    public function headers(): array
    {
        return [
            'Content-Type' => $this->mediaType,
            'Content-Length' => (string) $this->size,
            'Content-Disposition' => 'attachment; filename="'.$this->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ];
    }
}
