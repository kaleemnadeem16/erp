<?php

declare(strict_types=1);

namespace App\Modules\Company\Support\Brand;

/**
 * The typed Company contract Documents reads (ADR 0162, ADR 0081, ADR 0137): one immutable brand version. A brand with nothing set is never returned (it is "no brand").
 * Documents pins it into the manifest at the freeze; it never reads a current brand at render time.
 */
final readonly class CompanyBrand
{
    public function __construct(
        public int $version,
        public ?string $displayName,
        public ?string $tagline,
        public ?CompanyBrandLogo $logo,
    ) {}

    /** @return array{version: int, display_name: string|null, tagline: string|null} the text part, as pinned in a manifest */
    public function pinned(): array
    {
        return ['version' => $this->version, 'display_name' => $this->displayName, 'tagline' => $this->tagline];
    }

    /** @return list<array{role: string, blob_id: int, sha256: string, media_type: string, width: int, height: int, size_bytes: int}> */
    public function assets(): array
    {
        return $this->logo === null ? [] : [$this->logo->asset()];
    }
}
