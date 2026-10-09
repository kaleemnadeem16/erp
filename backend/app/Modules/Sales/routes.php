<?php

use App\Modules\Sales\Http\Controllers\Api\V1\LeadController;
use App\Modules\Sales\Http\Controllers\Api\V1\OpportunityController;
use App\Modules\Sales\Http\Controllers\Api\V1\SalesOwnerController;
use App\Modules\Sales\Http\Controllers\Api\V1\SalesStageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
    Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->name('leads.show');
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->name('leads.update');
    Route::post('leads/{lead}/link-party', [LeadController::class, 'linkParty'])->whereNumber('lead')->name('leads.link-party');
    Route::post('leads/{lead}/qualify', [LeadController::class, 'qualify'])->whereNumber('lead')->name('leads.qualify');
    Route::post('leads/{lead}/disqualify', [LeadController::class, 'disqualify'])->whereNumber('lead')->name('leads.disqualify');
    Route::post('leads/{lead}/revive', [LeadController::class, 'revive'])->whereNumber('lead')->name('leads.revive');
    Route::post('leads/{lead}/assign', [LeadController::class, 'assign'])->whereNumber('lead')->name('leads.assign');
    Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead')->name('leads.convert');
    Route::post('leads/{lead}/notes', [LeadController::class, 'addNote'])->whereNumber('lead')->name('leads.notes.store');

    Route::get('opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
    Route::post('opportunities', [OpportunityController::class, 'store'])->name('opportunities.store');
    Route::get('opportunities/{opportunity}', [OpportunityController::class, 'show'])->whereNumber('opportunity')->name('opportunities.show');
    Route::patch('opportunities/{opportunity}', [OpportunityController::class, 'update'])->whereNumber('opportunity')->name('opportunities.update');
    Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'stage'])->whereNumber('opportunity')->name('opportunities.stage');
    Route::post('opportunities/{opportunity}/reassign', [OpportunityController::class, 'reassign'])->whereNumber('opportunity')->name('opportunities.reassign');
    Route::post('opportunities/{opportunity}/close', [OpportunityController::class, 'close'])->whereNumber('opportunity')->name('opportunities.close');
    Route::post('opportunities/{opportunity}/reopen', [OpportunityController::class, 'reopen'])->whereNumber('opportunity')->name('opportunities.reopen');
    Route::post('opportunities/{opportunity}/notes', [OpportunityController::class, 'addNote'])->whereNumber('opportunity')->name('opportunities.notes.store');

    Route::get('sales-owners', [SalesOwnerController::class, 'index'])->name('sales-owners.index');
    Route::get('sales-stages', [SalesStageController::class, 'index'])->name('sales-stages.index');
    Route::post('sales-stages', [SalesStageController::class, 'store'])->name('sales-stages.store');
    Route::post('sales-stages/seed-suggested', [SalesStageController::class, 'seedSuggested'])->name('sales-stages.seed-suggested');
    Route::patch('sales-stages/{stage}', [SalesStageController::class, 'update'])->whereNumber('stage')->name('sales-stages.update');
});
