<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves the current company for scoping queries, cache keys and storage paths.
 * Single-organisation today — id() always returns 1. Enabling multi-tenancy means
 * changing id() to read from the authenticated user or subdomain; no query, cache
 * key or storage path in the application changes.
 *
 * See docs/architecture/multi-tenancy.md.
 */
class CompanyContext
{
    public function id(): int
    {
        return 1;
    }

    public function cacheKey(string $key): string
    {
        return "company:{$this->id()}:{$key}";
    }

    public function storagePath(string $path): string
    {
        return "companies/{$this->id()}/{$path}";
    }
}
