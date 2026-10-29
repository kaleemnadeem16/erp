<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

use App\Modules\Documents\Support\ProjectionSchema;

/** Everything a renderer may read: the frozen payload, the schema the owner declared for it and the template and page policy exactly as pinned in the manifest. */
final readonly class RenderInput
{
    /**
     * @param  array<string, mixed>  $payload  the frozen projection
     * @param  array<string, mixed>  $template  the manifest's resolved_template (source, key, version, configuration)
     * @param  array<string, mixed>  $pagePolicy  the manifest's resolved_page_policy
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
    ) {}
}
