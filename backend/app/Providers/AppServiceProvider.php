<?php

namespace App\Providers;

use App\Contracts\NumberingScheme;
use App\Support\CompanyContext;
use App\Support\DefaultNumberingScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        $this->app->bind(NumberingScheme::class, DefaultNumberingScheme::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // docs/architecture/api-conventions.md: 120/min for authenticated requests.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->id ?: $request->ip()
        ));
    }
}
