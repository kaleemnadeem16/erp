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
