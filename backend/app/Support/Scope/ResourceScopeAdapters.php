<?php

declare(strict_types=1);

namespace App\Support\Scope;

use InvalidArgumentException;

final class ResourceScopeAdapters
{
    /** @var array<string, ResourceScopeAdapter> */
    private array $adapters = [];

    public function register(string $resource, ResourceScopeAdapter $adapter): void
    {
        if ($resource === '' || isset($this->adapters[$resource])) {
            throw new InvalidArgumentException('Resource scope adapter identity must be unique and nonempty.');
        }
        $this->adapters[$resource] = $adapter;
    }

    public function supports(string $resource, string $duty, string $scope): bool
    {
        return isset($this->adapters[$resource]) && $this->adapters[$resource]->supports($duty, $scope);
    }

    public function supportsAssignment(string $duty, string $scope): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($duty, $scope)) {
                return true;
            }
        }

        return false;
    }
}
