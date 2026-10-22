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

        $this->assertSame(['accounting', 'administration', 'approvals', 'company', 'currency', 'estimating', 'inventory', 'numbering', 'parties', 'purchasing', 'sales'], array_keys($vocabularies));
        $this->assertContains('administration.access.assign', $vocabularies['administration']);
        foreach (['accounting.account-mapping.view', 'accounting.account-mapping.manage'] as $capability) {
            $this->assertContains($capability, $vocabularies['accounting']);
        }
        foreach (['parties.credit-limit.view', 'parties.credit-limit.manage', 'parties.payment-terms.manage'] as $capability) {
            $this->assertContains($capability, $vocabularies['parties']);
        }
        $this->assertContains('sales.lead.manage', $vocabularies['sales']);
        $this->assertContains('sales.opportunity.manage', $vocabularies['sales']);
        $this->assertContains('inventory.package-state.open', $vocabularies['inventory']);
        $this->assertContains('inventory.package-state.issue', $vocabularies['inventory']);
        $this->assertContains('inventory.package-state.transfer', $vocabularies['inventory']);
        $this->assertContains('inventory.package-state.activate', $vocabularies['inventory']);
        $this->assertContains('inventory.package-state.recount', $vocabularies['inventory']);
        $this->assertContains('inventory.package-state.reverse', $vocabularies['inventory']);
        foreach ([
            'estimating.rate-dimension.manage',
            'estimating.application-rate.manage',
            'estimating.formulation.manage',
            'estimating.assembly-group.manage',
            'estimating.assembly.manage',
            'estimating.resource-rate.manage',
        ] as $capability) {
            $this->assertContains($capability, $vocabularies['estimating']);
        }
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

        // Every registered capability except those no automatic grant may include (a sensitive duty needs an explicit grant).
        $this->assertCount(count(app(PermissionRegistry::class)->automaticGrants()), $user->getAllPermissions());
    }

    public function test_sync_admin_permissions_grants_names_registered_after_the_user_was_created(): void
    {
        // Simulates the real production gap: an administrator created back when
        // the registry was smaller than it is now, and never re-granted anything
        // since — exactly what `permission:register` alone does not fix.
        $registry = app(PermissionRegistry::class);
        $registry->registerForTeam(1);
        $this->assertGreaterThan(1, count($registry->all()));

        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->givePermissionTo('estimating.overhead-policy.manage');

        $this->artisan('erp:sync-admin-permissions', ['email' => 'admin@example.com'])
            ->assertSuccessful();

        $user->unsetRelation('permissions');
        $this->assertSame(count($registry->automaticGrants()), $user->getAllPermissions()->count());
    }

    public function test_sync_admin_permissions_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->artisan('erp:sync-admin-permissions', ['email' => 'admin@example.com'])->assertSuccessful();
        $this->artisan('erp:sync-admin-permissions', ['email' => 'admin@example.com'])
            ->expectsOutputToContain('already held all')
            ->assertSuccessful();
    }

    public function test_sync_admin_permissions_fails_for_an_unknown_email(): void
    {
        $this->artisan('erp:sync-admin-permissions', ['email' => 'nobody@example.com'])
            ->assertFailed();
    }
}
