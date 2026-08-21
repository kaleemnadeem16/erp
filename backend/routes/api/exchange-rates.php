<?php

use App\Http\Controllers\Api\V1\ExchangeRateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
    Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');
    Route::get('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'show'])->name('exchange-rates.show');
    Route::patch('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'update'])->name('exchange-rates.update');
    Route::delete('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'destroy'])->name('exchange-rates.destroy');
});
