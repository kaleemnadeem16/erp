<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Modules\Documents\Support\DocumentConfigurationUsage;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\ManifestConfigurationUsage;
use Illuminate\Support\ServiceProvider;

/**
 * Shared Documents infrastructure (ADR 0137). DF0 registers the type registry; DF1a added the configuration-usage port, which DF2 binds to the frozen manifests: no routes, no permission vocabulary, no live duty. Modules register
 * their document types against the registry from their own providers.
 */
class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentTypeRegistry::class);
        $this->app->bind(DocumentConfigurationUsage::class, ManifestConfigurationUsage::class);
    }
}
