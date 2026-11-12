<?php

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyCatalogue;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 5 Batch 1 — the strict no-automatic-growth gate for Jobs duties. A Jobs duty that is ACTIVE is registered but never granted by an automatic
 * "everything registered" grant: not to an administrator sync, not to a person covered by a verified full-access mandate. An explicit grant survives a re-sync.
 * The ordinary Sales/CRM mandate behaviour is unchanged (SalesDutyRegistrationTest).
 */
class JobDutyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = 'job.view';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    private function registryWithLiveDuty(): PermissionRegistry
    {
        $duties = array_map(
            fn (JobDuty $d): JobDuty => $d->name === self::LIVE ? new JobDuty($d->name, JobDutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate) : $d,
            JobDutyCatalogue::all(),
        );
        $registry = new PermissionRegistry(app(PermissionRegistrar::class));
        $registry->registerVocabulary('sales', ['sales.quotation.view']);
        (new JobDutyManifest($duties))->registerWith($registry);

        return $registry;
    }

    public function test_the_shipped_application_registers_no_jobs_duty(): void
    {
        $registry = app(PermissionRegistry::class);
        $this->assertSame([], array_values(array_filter($registry->all(), fn (string $n): bool => str_starts_with($n, 'job.'))));
        $this->assertSame([], $registry->strictlyExcluded());
    }

    public function test_an_active_duty_is_registered_but_strictly_excluded(): void
    {
        $registry = $this->registryWithLiveDuty();
        $this->assertContains(self::LIVE, $registry->all());
        $this->assertSame([self::LIVE], $registry->strictlyExcluded());
        $this->assertNotContains(self::LIVE, $registry->automaticGrants());
        $this->assertNotContains(self::LIVE, $registry->notAutomaticallyGranted(), 'strict exclusion is a separate set');
    }

    public function test_a_full_access_mandate_does_not_grow_into_a_jobs_duty(): void
    {
        $registry = $this->registryWithLiveDuty();
        $registry->registerForTeam(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Company administrator', 'guard_name' => 'web']);
        $holder = User::factory()->create();
        $holder->assignRole($role);
        config(['access.full_access_mandates' => [['company_id' => 1, 'role_id' => $role->id, 'mandate' => 'ALL_REGISTERED_CAPABILITIES', 'approval_reference' => 'CHG-9', 'effective_from' => now()->subDay()->toDateString()]]]);

        $grants = $registry->automaticGrants($holder);
        $this->assertNotContains(self::LIVE, $grants, 'a verified mandate must not carry a Jobs duty');
        $this->assertContains('sales.quotation.view', $grants, 'the accepted mandate mechanism is unchanged for other modules');
    }

    public function test_an_admin_sync_does_not_grant_but_keeps_an_explicit_grant(): void
    {
        $registry = $this->registryWithLiveDuty();
        $registry->registerForTeam(1);
        $admin = User::factory()->create();
        $explicit = User::factory()->create();
        $explicit->givePermissionTo(self::LIVE);

        $this->assertNotContains(self::LIVE, $registry->syncAutomatic($admin));
        $this->assertFalse($admin->fresh()->hasPermissionTo(self::LIVE));
        $this->assertContains(self::LIVE, $registry->syncAutomatic($explicit), 'an explicit, audited grant survives a re-sync');
        $this->assertTrue($explicit->fresh()->hasPermissionTo(self::LIVE));
    }
}
