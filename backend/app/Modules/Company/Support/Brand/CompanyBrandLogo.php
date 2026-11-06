<?php

declare(strict_types=1);

namespace App\Modules\Company\Support\Brand;

/** The logo of one brand version: a reference to a verified Files blob with the hash and measures that were checked when it was accepted. Never bytes. */
final readonly class CompanyBrandLogo
{
    public function __construct(
        public int $blobId,
        public string $sha256,
        public string $mediaType,
        public int $width,
        public int $height,
        public int $sizeBytes,
    ) {}

    /** @return array{role: string, blob_id: int, sha256: string, media_type: string, width: int, height: int, size_bytes: int} */
    public function asset(): array
    {
        return ['role' => 'logo', 'blob_id' => $this->blobId, 'sha256' => $this->sha256, 'media_type' => $this->mediaType, 'width' => $this->width, 'height' => $this->height, 'size_bytes' => $this->sizeBytes];
    }
}
