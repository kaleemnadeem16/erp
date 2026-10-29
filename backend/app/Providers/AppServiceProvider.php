<?php

namespace App\Providers;

use App\Contracts\NumberingScheme;
use App\Support\CompanyContext;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\DefaultNumberingScheme;
use App\Support\OpenApi\CustomFieldsRuleTransformer;
use App\Support\OpenApi\DecimalAsStringRuleTransformer;
use App\Support\Performance\QueryTimings;
use App\Support\PermissionRegistry;
use App\Support\Scope\ResourceScopeAdapters;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Defence in depth for cached or stale environment configuration: once the
        // application identifies as testing, no command may default to the dev database.
        if ($this->app->environment('testing')) {
            $this->app['config']->set('database.default', 'testing');
        }

        $this->app->singleton(CompanyContext::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(ResourceScopeAdapters::class);
        $this->app->singleton(FinancialActivity::class);
        $this->app->singleton(QueryTimings::class);
        $this->app->bind(NumberingScheme::class, DefaultNumberingScheme::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // docs/architecture/api-conventions.md: 120/min for authenticated requests (config/api.php; the dev stack raises it for e2e runs).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('api.rate_limit_per_minute'))->by(
            $request->user()?->id ?: $request->ip()
        ));

        // Per-request query count and time for the performance log (docs/architecture/observability.md).
        DB::listen(fn (QueryExecuted $query) => $this->app->make(QueryTimings::class)->record($query));

        // Invariant 6: decimals cross the wire as strings in both directions.
        Scramble::configure()->withRuleTransformers([DecimalAsStringRuleTransformer::class, CustomFieldsRuleTransformer::class]);
    }
}
