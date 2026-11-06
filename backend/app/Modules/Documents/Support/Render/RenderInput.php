<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

use App\Modules\Documents\Support\ProjectionSchema;

/**
 * Everything a renderer may read: the frozen payload, the schema the owner declared for it, the template and page policy exactly as pinned in the manifest and, when the
 * manifest pinned a company brand (ADR 0162), that brand's text and the bytes of the logo it pinned (read and hash-checked by the caller before rendering; the renderer itself reads
 * nothing). No brand means exactly the document that was always made.
 */
final readonly class RenderInput
{
    /**
     * @param  array<string, mixed>  $payload  the frozen projection
     * @param  array<string, mixed>  $template  the manifest's resolved_template (source, key, version, configuration)
     * @param  array<string, mixed>  $pagePolicy  the manifest's resolved_page_policy
     * @param  array{version: int, display_name: string|null, tagline: string|null}|null  $brand  the pinned brand text
     * @param  array{media_type: string, bytes: string}|null  $logo  the pinned logo, sanitised pixel data
     */
    public function __construct(
        public string $title,
        public string $purpose,
        public array $payload,
        public ProjectionSchema $schema,
        public array $template,
        public array $pagePolicy,
        public \DateTimeInterface $created,
        public string $manifestDigest,
        public ?array $brand = null,
        public ?array $logo = null,
    ) {}
}
