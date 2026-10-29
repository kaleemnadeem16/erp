<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Modules\Files\Support\FileOwnerRegistry;
use App\Modules\Files\Support\FileScanner;
use App\Modules\Files\Support\NotConfiguredScanner;
use Illuminate\Support\ServiceProvider;

/**
 * The shared Files storage kernel (ADR 0153), disabled: it registers the owner registry and the default scanner, which reports that nothing is configured. No route,
 * permission vocabulary, duty, upload, download, attachment or URL. Owning modules register their file owners against the registry from their own providers.
 */
class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FileOwnerRegistry::class);
        $this->app->bindIf(FileScanner::class, NotConfiguredScanner::class);
    }
}
