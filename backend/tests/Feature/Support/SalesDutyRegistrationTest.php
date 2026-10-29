<?php

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Sales\Support\Authority\DutyClass;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AU1 — safe registration, exclusions and guards for Sales duties (ADR 0138, R9 rollout). Registration adds names and grants nothing; a duty the manifest
 * does not list as ACTIVE confers nothing even if someone makes its permission row by hand.
 */
class SalesDutyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const FUTURE_ORDINARY = 'sales.quotation.view';

    private const FUTURE_SENSITIVE = 'sales.financial.configure';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    /** The shipped manifest plus two hypothetical live duties, to prove the foundation before any slice activates a real one. */
    private function futureManifest(): SalesDutyManifest
    {
        return $this->withActive([self::FUTURE_ORDINARY => DutyClass::NewOrdinary, self::FUTURE_SENSITIVE => DutyClass::Sensitive]);
    }

    /** @param  array<string, DutyClass>  $active */
    private function withActive(array $active): SalesDutyManifest
    {
        $duties = [];
        foreach (SalesDutyManifest::shipped()->all() as $duty) {
            $duties[] = isset($active[$duty->name]) ? new SalesDuty($duty->name, $active[$duty->name], DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty;
        }

        return new SalesDutyManifest($duties);
    }

    private function freshRegistry(SalesDutyManifest $manifest): PermissionRegistry
    {
        $registry = new PermissionRegistry(app(PermissionRegistrar::class));
        $manifest->registerWith($registry);

        return $registry;
    }

    /** @return array<string, mixed> */
    private function mandate(Role $role, array $over = []): array
    {
        return array_merge(['company_id' => 1, 'role_id' => $role->id, 'mandate' => 'ALL_REGISTERED_CAPABILITIES', 'approval_reference' => 'CHG-9', 'effective_from' => now()->subDay()->toDateString()], $over);
    }

    public function test_the_application_registers_the_eight_crm_duties_and_no_other_sales_duty(): void
    {
        $registry = app(PermissionRegistry::class);
        $sales = array_filter($registry->all(), fn (string $name): bool => str_starts_with($name, 'sales.'));

        $this->assertEqualsCanonicalizing(Sales::all(), $sales);
        $this->assertCount(8, $sales);
        $this->assertSame([Sales::OPPORTUNITY_ATTRIBUTION_CORRECT], array_values(array_filter($registry->notAutomaticallyGranted(), fn (string $name): bool => str_starts_with($name, 'sales.'))));
        foreach (SalesDutyManifest::shipped()->withStatus(DutyStatus::Specified) as $duty) {
            $this->assertNotContains($duty->name, $registry->all());
        }
    }

    public function test_registration_and_team_sync_are_idempotent_and_create_only_active_rows(): void
    {
        $registry = $this->freshRegistry(SalesDutyManifest::shipped());
        $first = $registry->all();
        SalesDutyManifest::shipped()->registerWith($registry);
        $this->assertSame($first, $registry->all());

        $registry->registerForTeam(1);
        $count = Permission::query()->where('name', 'like', 'sales.%')->count();
        $registry->registerForTeam(1);
        $this->assertSame($count, Permission::query()->where('name', 'like', 'sales.%')->count());
        $this->assertSame(8, $count, 'Registering the vocabulary creates rows for the eight live duties only.');
        $this->assertSame(0, Permission::query()->where('name', self::FUTURE_SENSITIVE)->count());
    }

    public function test_a_newly_active_duty_is_registered_but_never_part_of_an_automatic_grant(): void
    {
        $registry = $this->freshRegistry($this->futureManifest());

        $this->assertContains(self::FUTURE_ORDINARY, $registry->all());
        $this->assertContains(self::FUTURE_SENSITIVE, $registry->all());
        $this->assertContains(self::FUTURE_ORDINARY, $registry->notAutomaticallyGranted(), 'Even an ordinary new duty waits for an approved holder recipe.');
        $this->assertContains(self::FUTURE_SENSITIVE, $registry->notAutomaticallyGranted());
        $this->assertNotContains(self::FUTURE_ORDINARY, $registry->automaticGrants());
        $this->assertNotContains(self::FUTURE_SENSITIVE, $registry->automaticGrants());
        $this->assertContains(Sales::PIPELINE_VIEW, $registry->automaticGrants(), 'Existing CRM behaviour is untouched.');
        $this->assertContains(Sales::OPPORTUNITY_MANAGE, $registry->automaticGrants());
        $this->assertNotContains(Sales::OPPORTUNITY_ATTRIBUTION_CORRECT, $registry->automaticGrants());
    }

    public function test_syncing_an_administrator_does_not_grant_a_new_duty_but_keeps_an_explicit_one(): void
    {
        $registry = $this->freshRegistry($this->futureManifest());
        $registry->registerForTeam(1);
        $admin = User::factory()->create();
        $named = User::factory()->create();
        $named->givePermissionTo(self::FUTURE_SENSITIVE);

        $registry->syncAutomatic($admin);
        $registry->syncAutomatic($named);
        $registry->syncAutomatic($named);

        $this->assertFalse($admin->fresh()->hasPermissionTo(self::FUTURE_SENSITIVE));
        $this->assertFalse($admin->fresh()->hasPermissionTo(self::FUTURE_ORDINARY));
        $this->assertTrue($named->fresh()->hasPermissionTo(self::FUTURE_SENSITIVE), 'An explicit grant survives a re-sync.');
        $this->assertFalse($named->fresh()->hasPermissionTo(self::FUTURE_ORDINARY));
    }

    public function test_the_mandate_boundaries_hold_for_a_newly_active_duty(): void
    {
        $registry = $this->freshRegistry($this->futureManifest());
        $registry->registerForTeam(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Company administrator', 'guard_name' => 'web']);
        $holder = User::factory()->create();
        $holder->assignRole($role);
        $stranger = User::factory()->create();
        $named = Role::create(['team_id' => 1, 'name' => 'Admin', 'guard_name' => 'web']); // a role called admin is not a mandate
        $namedHolder = User::factory()->create();
        $namedHolder->assignRole($named);

        config(['access.full_access_mandates' => [$this->mandate($role)]]);
        $this->assertContains(self::FUTURE_SENSITIVE, $registry->automaticGrants($holder), 'A qualifying mandate covers its holder: the accepted mechanism, unchanged.');
        $this->assertNotContains(self::FUTURE_SENSITIVE, $registry->automaticGrants($stranger));
        $this->assertNotContains(self::FUTURE_SENSITIVE, $registry->automaticGrants($namedHolder), 'No inference from a role name.');

        $bad = [
            'foreign company' => $this->mandate($role, ['company_id' => 2]),
            'no approval reference' => $this->mandate($role, ['approval_reference' => '']),
            'wrong mandate' => $this->mandate($role, ['mandate' => 'SALES_ONLY']),
            'future' => $this->mandate($role, ['effective_from' => now()->addDay()->toDateString()]),
            'unknown role' => $this->mandate($role, ['role_id' => 99999]),
        ];
        foreach ($bad as $why => $mandate) {
            config(['access.full_access_mandates' => [$mandate]]);
            $this->assertNotContains(self::FUTURE_SENSITIVE, $registry->automaticGrants($holder), $why);
        }
    }

    public function test_a_duty_the_manifest_does_not_list_as_active_confers_nothing_even_with_a_hand_made_row(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate(self::FUTURE_ORDINARY, 'web');
        Permission::findOrCreate('sales.delivery.send', 'web');
        $user->givePermissionTo(self::FUTURE_ORDINARY, 'sales.delivery.send');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($user->fresh()->can(self::FUTURE_ORDINARY), 'The raw row is a grant in the permission store…');
        $this->assertFalse(SalesAccess::may($user->fresh(), self::FUTURE_ORDINARY), '…but not a Sales capability.');
        $this->assertFalse(SalesAccess::may($user->fresh(), 'sales.delivery.send'));
        $this->assertFalse(SalesAccess::may($user->fresh(), 'sales.*'));
        $this->assertFalse(SalesAccess::may($user->fresh(), 'sales.not.a.duty'));
        $this->assertFalse(SalesAccess::mayAll($user->fresh()), 'No duties named is not "all of them held".');
        $this->expectException(AuthorizationException::class);
        SalesAccess::require($user->fresh(), self::FUTURE_ORDINARY, 'view quotations');
    }

    public function test_an_active_duty_needs_the_grant_in_this_company_and_an_unrestricted_scope(): void
    {
        $this->app->instance(SalesDutyManifest::class, $this->futureManifest());
        Permission::findOrCreate(self::FUTURE_SENSITIVE, 'web');
        $user = User::factory()->create();
        $limited = User::factory()->create();

        $this->assertFalse(SalesAccess::may($user, self::FUTURE_SENSITIVE), 'Registered is not granted.');

        $user->givePermissionTo(self::FUTURE_SENSITIVE);
        $this->assertTrue(SalesAccess::may($user->fresh(), self::FUTURE_SENSITIVE));

        $limited->givePermissionTo(self::FUTURE_SENSITIVE);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $limited->id, 'permission' => self::FUTURE_SENSITIVE, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(SalesAccess::may($limited->fresh(), self::FUTURE_SENSITIVE), 'Restricted scope is unsupported and refuses rather than filters.');

        // A grant made for another company's team confers nothing here.
        $other = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(2);
        $other->givePermissionTo(self::FUTURE_SENSITIVE);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->assertFalse(SalesAccess::may($other->fresh(), self::FUTURE_SENSITIVE));
    }

    public function test_each_duty_is_judged_by_its_own_scope_and_an_unrelated_unrestricted_grant_cannot_rescue_it(): void
    {
        $this->app->instance(SalesDutyManifest::class, $this->futureManifest());
        Permission::findOrCreate(self::FUTURE_SENSITIVE, 'web');
        Permission::findOrCreate(Sales::PIPELINE_VIEW, 'web');
        $user = User::factory()->create();
        $user->givePermissionTo(self::FUTURE_SENSITIVE, Sales::PIPELINE_VIEW);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $user->id, 'permission' => self::FUTURE_SENSITIVE, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(SalesAccess::may($user->fresh(), Sales::PIPELINE_VIEW));
        $this->assertFalse(SalesAccess::may($user->fresh(), self::FUTURE_SENSITIVE));
        $this->assertFalse(SalesAccess::mayAll($user->fresh(), self::FUTURE_SENSITIVE, Sales::PIPELINE_VIEW));
    }

    public function test_a_limited_role_is_not_widened_by_an_automatic_sync(): void
    {
        $registry = $this->freshRegistry($this->futureManifest());
        $registry->registerForTeam(1);
        $this->app->instance(SalesDutyManifest::class, $this->futureManifest());
        $role = Role::create(['team_id' => 1, 'name' => 'Sales finance', 'guard_name' => 'web']);
        $role->givePermissionTo(self::FUTURE_SENSITIVE);
        $user = User::factory()->create();
        $user->assignRole($role);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $user->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(SalesAccess::may($user->fresh(), self::FUTURE_SENSITIVE));

        $registry->syncAutomatic($user);

        $this->assertFalse($user->fresh()->getDirectPermissions()->pluck('name')->contains(self::FUTURE_SENSITIVE), 'The role grant was not converted into an unrestricted direct grant.');
        $this->assertFalse(SalesAccess::may($user->fresh(), self::FUTURE_SENSITIVE));
    }

    public function test_the_existing_crm_duties_behave_exactly_as_before(): void
    {
        foreach (Sales::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $manager = User::factory()->create();
        $manager->givePermissionTo(Sales::OPPORTUNITY_MANAGE);
        $corrector = User::factory()->create();
        $corrector->givePermissionTo(Sales::OPPORTUNITY_ATTRIBUTION_CORRECT);
        $both = User::factory()->create();
        $both->givePermissionTo(Sales::OPPORTUNITY_ATTRIBUTION_CORRECT, Sales::PIPELINE_VIEW);

        $this->assertTrue(SalesAccess::may($manager->fresh(), Sales::OPPORTUNITY_MANAGE));
        $this->assertTrue(SalesAccess::mayRead($manager->fresh()));
        $this->assertFalse(SalesAccess::may($manager->fresh(), Sales::STAGE_MANAGE));
        $this->assertFalse(SalesAccess::mayCorrectAttribution($manager->fresh()), 'Managing deals does not imply correcting attribution.');
        $this->assertFalse(SalesAccess::mayCorrectAttribution($corrector->fresh()), 'The duty alone, without an independent read, is not enough.');
        $this->assertTrue(SalesAccess::mayCorrectAttribution($both->fresh()));
    }

    public function test_registering_the_foundation_opens_no_route(): void
    {
        $dormant = array_map(fn (SalesDuty $duty): string => $duty->name, array_filter(SalesDutyManifest::shipped()->all(), fn (SalesDuty $duty): bool => ! $duty->isActive()));

        // The only quotation routes are the QL1b adapters: three reads and the two draft writes. Their duties are STAGED, so they refuse everyone
        // (QuotationDraftReviewApiTest, QuotationDraftWriteApiTest). Each URI may only carry the methods listed here.
        $adapters = [
            'api/v1/quotations' => ['POST'],
            'api/v1/quotations/{revision}' => ['GET', 'HEAD', 'PATCH'],
            'api/v1/quotations/{revision}/editable' => ['GET', 'HEAD'],
            'api/v1/quotations/{revision}/preview' => ['GET', 'HEAD'],
        ];

        foreach (Route::getRoutes() as $route) {
            $declared = implode(' ', array_merge($route->gatherMiddleware(), [$route->uri()]));
            foreach ($dormant as $name) {
                $this->assertStringNotContainsString($name, $declared);
            }
            if (isset($adapters[$route->uri()])) {
                $this->assertSame([], array_diff($route->methods(), $adapters[$route->uri()]), 'A quotation adapter may only carry the methods QL1b delivers.');

                continue;
            }
            $this->assertDoesNotMatchRegularExpression('#sales-fx-polic|quotation-rounding|/quotations#', $route->uri(), 'No Phase 4 configuration or other quotation route exists yet.');
        }
    }
}
