<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

use App\Modules\Imports\Support\ImportPurpose;

/** A validation-only domain contract. It intentionally has no commit/write method. */
interface ImportTarget
{
    public function purpose(): ImportPurpose;

    public function entityType(): string;

    /** @return array<string, array<int, mixed>> Laravel validation rules keyed by target field. */
    public function rules(): array;
}
