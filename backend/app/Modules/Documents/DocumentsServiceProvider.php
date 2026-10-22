<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Modules\Documents\Support\DocumentConfigurationUsage;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\NoPinnedConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * Shared Documents infrastructure (ADR 0137). DF0 registers the type registry and DF1a binds the configuration-usage port: no routes, no permission vocabulary, no live duty. Modules register
 * their document types against the registry from their own providers.
 */
class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentTypeRegistry::class);
        $this->app->bindIf(DocumentConfigurationUsage::class, NoPinnedConfiguration::class);
    }
}
