<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentConfigurationUsage;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\ManifestConfigurationUsage;
use App\Modules\Documents\Support\Render\DocumentRenderer;
use App\Modules\Documents\Support\Render\PdfDocumentRenderer;
use App\Modules\Files\Support\FileOwnerRegistry;
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
        // The one renderer: pure PHP, deterministic, standard fonts. A manifest pins its contract, version and fonts when it is frozen (DF3).
        $this->app->singleton(DocumentRenderer::class, PdfDocumentRenderer::class);
        // Generated PDFs are files in the shared store; Documents is their owner and the only one that can authorise operations on them.
        $this->app->afterResolving(FileOwnerRegistry::class, function (FileOwnerRegistry $registry): void {
            if (! $registry->has(DocumentArtefactFileOwner::OWNER)) {
                $registry->register(new DocumentArtefactFileOwner);
            }
        });
    }
}
