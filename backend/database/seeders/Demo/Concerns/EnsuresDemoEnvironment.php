<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Concerns;

use RuntimeException;

trait EnsuresDemoEnvironment
{
    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data may only be seeded in local or testing environments.');
        }
    }
}
