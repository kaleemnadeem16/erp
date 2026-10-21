<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** One resolved part of the policy and where it came from: SHIPPED (code default, no row), COMPANY ('*' default), TYPE (the type's own default) or EXPLICIT. */
final readonly class ResolvedComponent
{
    public function __construct(
        public string $source,
        public ?int $versionId,
        public string $key,
        public int $version,
        public TemplateConfiguration|PagePolicyConfiguration $configuration,
    ) {}
}
