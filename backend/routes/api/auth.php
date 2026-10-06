<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use Illuminate\Support\Facades\Route;

// docs/architecture/api-conventions.md: 5/min per IP on login.
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/user', [AuthController::class, 'user'])->name('user');

    // Self-service profile/password management — registered alongside auth
    // rather than under a business Module, since it's core identity, not a
    // domain concern. See AccountController.
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::patch('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');

    // The caller's own signed-in devices (bearer tokens) and signing one out. See DeviceController.
    Route::get('/account/devices', [DeviceController::class, 'index'])->name('account.devices.index');
    Route::delete('/account/devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device')->name('account.devices.destroy');
});
