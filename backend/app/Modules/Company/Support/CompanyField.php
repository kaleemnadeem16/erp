<?php

declare(strict_types=1);

namespace App\Modules\Company\Support;

/**
 * One explicit, typed company-level field: a real column on `companies`, with its own validation, the permission that may change
 * it, and whether a change needs a reason. Not a generic setting: adding one means a column, a definition here and — where it
 * is read — a consumer (ADR 0081).
 */
final class CompanyField
{
    /**
     * @param  list<mixed>  $rules  Laravel validation rules, without nullable/sometimes (added by the request)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $section,
        public readonly string $label,
        public readonly string $description,
        public readonly CompanyFieldType $type,
        public readonly array $rules,
        public readonly string $permission,
        public readonly bool $governed = false,
        public readonly bool $nullable = true,
        public readonly ?int $maxLength = null,
    ) {}
}
