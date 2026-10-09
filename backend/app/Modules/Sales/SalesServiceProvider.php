<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Parties\Support\PartyCandidateAccess;
use App\Modules\Sales\Numbering\LeadNumbering;
use App\Modules\Sales\Numbering\OpportunityNumbering;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(PermissionRegistry::class, fn (PermissionRegistry $registry) => $registry->registerVocabulary('sales', PermissionVocabulary::all()));
        $this->app->afterResolving(NumberingRegistry::class, static function (NumberingRegistry $registry): void {
            $registry->register(new LeadNumbering);
            $registry->register(new OpportunityNumbering);
        });
    }

    public function boot(): void
    {
        // People who record enquiries and deals choose or create the Party they are about, so they may ask whether it already exists (ADR 0105).
        PartyCandidateAccess::allow(PermissionVocabulary::LEAD_MANAGE);
        PartyCandidateAccess::allow(PermissionVocabulary::OPPORTUNITY_MANAGE);
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
