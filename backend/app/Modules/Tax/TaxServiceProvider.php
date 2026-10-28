<?php

declare(strict_types=1);

namespace App\Modules\Tax;

use App\Modules\Tax\Models\TaxRateVersion;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Modules\Tax\Support\Quotation\UnavailableQuotationTaxConsumer;
use App\Support\CompanyLocks\ExistsProbe;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

final class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TaxDutyManifest::class);
        $this->app->bind(QuotationTaxContextAuthorizer::class, UnavailableQuotationTaxConsumer::class);
        $this->app->afterResolving(FinancialActivity::class, static function (FinancialActivity $activity): void {
            $activity->register(new ExistsProbe('fixed Tax rates', fn (): bool => TaxRateVersion::query()->whereNotNull('amount')->exists()));
        });
        $this->app->afterResolving(PermissionRegistry::class, function (PermissionRegistry $registry): void {
            $this->app->make(TaxDutyManifest::class)->registerWith($registry);
        });
    }
}
