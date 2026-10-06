<?php

use App\Modules\Administration\Http\Controllers\CustomFieldDefinitionController;
use App\Modules\Administration\Http\Controllers\MemberController;
use App\Modules\Administration\Http\Controllers\OrganisationUnitController;
use App\Modules\Administration\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::post('members', [MemberController::class, 'store'])->name('members.store');
Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
Route::post('members/{member}/access', [MemberController::class, 'access'])->name('members.access');
Route::post('members/{member}/suspend', [MemberController::class, 'suspend'])->name('members.suspend');
Route::post('members/{member}/reactivate', [MemberController::class, 'reactivate'])->name('members.reactivate');
Route::post('members/{member}/resend-invitation', [MemberController::class, 'resend'])->name('members.resend-invitation');
Route::post('members/{member}/send-password-reset', [MemberController::class, 'sendPasswordReset'])->name('members.send-password-reset');
Route::post('members/{member}/issue-temporary-password', [MemberController::class, 'issueTemporaryPassword'])->middleware('throttle:5,1')->name('members.issue-temporary-password');
Route::get('members/{member}/history', [MemberController::class, 'history'])->name('members.history');
Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
Route::apiResource('roles', RoleController::class);

Route::get('custom-field-definitions', [CustomFieldDefinitionController::class, 'index'])->name('custom-field-definitions.index');
Route::post('custom-field-definitions', [CustomFieldDefinitionController::class, 'store'])->name('custom-field-definitions.store');

// The company's own organisation tree, its members and the resources each unit owns (ADR 0089, Q26).
Route::get('organisation-units', [OrganisationUnitController::class, 'index'])->name('organisation-units.index');
Route::post('organisation-units', [OrganisationUnitController::class, 'store'])->name('organisation-units.store');
Route::get('organisation-units/{organisation_unit}', [OrganisationUnitController::class, 'show'])->name('organisation-units.show');
Route::patch('organisation-units/{organisation_unit}', [OrganisationUnitController::class, 'update'])->name('organisation-units.update');
Route::delete('organisation-units/{organisation_unit}', [OrganisationUnitController::class, 'destroy'])->name('organisation-units.destroy');
Route::put('organisation-units/{organisation_unit}/members', [OrganisationUnitController::class, 'members'])->name('organisation-units.members');
Route::put('organisation-units/{organisation_unit}/resources', [OrganisationUnitController::class, 'resources'])->name('organisation-units.resources');
