<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Imports\Contracts\ImportTarget;

final class ImportTargetRegistry
{
    /** @var array<string, ImportTarget> */
    private array $targets = [];

    public function register(ImportTarget $target): void
    {
        $this->targets[$this->key($target->purpose(), $target->entityType())] = $target;
    }

    public function get(ImportPurpose $purpose, string $entityType): ImportTarget
    {
        return $this->targets[$this->key($purpose, $entityType)]
            ?? throw new DomainInvariantException('This entity is not registered for the requested import purpose.', 'entity_type');
    }

    private function key(ImportPurpose $purpose, string $entityType): string
    {
        return $purpose->value.':'.strtoupper(trim($entityType));
    }
}
