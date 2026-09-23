<?php

declare(strict_types=1);

namespace App\Modules\Estimating;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EstimatingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
