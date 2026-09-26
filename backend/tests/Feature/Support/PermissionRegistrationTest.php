<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PermissionRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_module_contributes_an_owned_permission_vocabulary(): void
    {
        $vocabularies = app(PermissionRegistry::class)->byOwner();

        $this->assertSame(['estimating', 'inventory', 'purchasing'], array_keys($vocabularies));
        $this->assertContains('estimating.material_preview.run', $vocabularies['estimating']);
        $this->assertContains('estimating.inventory_availability.view', $vocabularies['estimating']);
        $this->assertContains('estimating.internal_cost.view', $vocabularies['estimating']);
        $this->assertContains('estimating.material_plan.adjust', $vocabularies['estimating']);
        $this->assertContains('estimating.package_plan.adopt', $vocabularies['estimating']);
        $this->assertContains('estimating.material_variance.request', $vocabularies['estimating']);
        $this->assertContains('estimating.material_variance.approve', $vocabularies['estimating']);
        $this->assertContains('estimating.material_resolution_history.view', $vocabularies['estimating']);
    }

    public function test_two_modules_cannot_claim_the_same_permission(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is owned by both [estimating] and [rogue-module]');

        $registry->registerVocabulary('rogue-module', ['estimating.material_preview.run']);
    }

    public function test_the_application_permission_vocabulary_can_be_registered_idempotently(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->artisan('permission:register')
            ->expectsOutputToContain('Registered '.count($registry->all()).' application permission(s)')
            ->assertSuccessful();

        $this->assertSame(count($registry->all()), Permission::query()->count());

        $this->artisan('permission:register')->assertSuccessful();

        $this->assertSame(count($registry->all()), Permission::query()->count());
    }

    public function test_create_admin_all_registers_permissions_on_a_fresh_database(): void
    {
        $this->artisan('erp:create-admin', [
            '--email' => 'first-admin@example.com',
            '--name' => 'First Admin',
            '--permissions' => ['all'],
        ])->expectsQuestion('Password (input hidden)', 'A-strong-password-123!')
            ->assertSuccessful();

        $user = User::query()->where('email', 'first-admin@example.com')->firstOrFail();

        $this->assertCount(count(app(PermissionRegistry::class)->all()), $user->getAllPermissions());
    }
}
