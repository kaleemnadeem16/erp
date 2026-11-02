<?php

declare(strict_types=1);

namespace App\Support\Scope;

/** Module-owned declaration of supported duty/scope pairs; registration never grants capability. */
interface ResourceScopeAdapter
{
    public function supports(string $duty, string $scope): bool;
}
