<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Modules\Imports\Support\ImportTargetRegistry;
use Illuminate\Support\ServiceProvider;

class ImportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImportTargetRegistry::class);
    }
}
