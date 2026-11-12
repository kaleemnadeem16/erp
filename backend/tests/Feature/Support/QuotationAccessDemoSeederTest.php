<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Actions\ApplyQuotationDemoPreset;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Support\QuotationDemoPresets;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Support\Demo\QuotationDemoBoundary as Demo;
use Database\Seeders\Demo\Sales\QuotationAccessDemoSeeder;
use Database\Seeders\Demo\Sales\QuotationApprovalsDemoSeeder;
use Database\Seeders\Demo\Sales\QuotationIssuingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The quotation access demo (ADR 0155/0156/0159/0161): operator, personas, owned/shared/transferred quotations, two-stage approval, and every switchable main-login preset.
 * It runs only under the local preview; a rerun adds nothing.
 */
final class QuotationAccessDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function local(): void
    {
        config(['sales.dev_preview.quotation_duties' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->app->forgetInstance(SalesDutyManifest::class);
    }

    private function seeded(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->local();
        $this->seed(QuotationIssuingDemoSeeder::class);
        $this->seed(QuotationApprovalsDemoSeeder::class);
        $this->seed(QuotationAccessDemoSeeder::class);
    }

    private function user(string $email): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @return list<string> */
    private function held(User $user): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        return $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();
    }

    public function test_it_writes_nothing_outside_the_local_preview(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertNull(User::query()->where('email', Demo::OPERATOR_EMAIL)->first());
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 0);
        $this->assertSame(0, QuotationFamily::query()->count());
    }

    public function test_it_refuses_a_company_that_is_not_the_designated_demo_company(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->local();
        $this->assertThrows(fn () => app(QuotationAccessDemoSeeder::class)->seedFor(2), DomainInvariantException::class, 'unavailable');
    }

    public function test_the_operator_personas_and_scenarios_exist_and_a_rerun_adds_nothing(): void
    {
        $this->seeded();
        $counts = fn (): array => [
            QuotationFamily::query()->count(), QuotationRevision::query()->count(), DB::table('quotation_access_grants')->count(), DB::table('quotation_access_revocations')->count(),
            DB::table('quotation_ownership_events')->count(), ApprovalRequest::query()->count(), DB::table('model_has_roles')->count(), DB::table('model_has_permissions')->count(),
            DB::table('access_scope_limits')->count(), DB::table('roles')->count(), DB::table('users')->count(), DB::table('activity_log')->count(), DB::table('demo_access_operator_bootstraps')->count(),
        ];
        $first = $counts();
        $this->seed(QuotationAccessDemoSeeder::class);
        $this->assertSame($first, $counts());

        // The operator: access administration plus exactly the five approved duties; never issue, repair or approve.
        $operator = $this->user(Demo::OPERATOR_EMAIL);
        $held = $this->held($operator);
        $this->assertEqualsCanonicalizing(['administration.access.assign', 'administration.role.manage', ...Demo::DUTIES], $held);
        $this->assertNotContains(Duty::QUOTATION_ISSUE, $held);
        $this->assertNotContains(Duty::DOCUMENT_REPAIR, $held);
        $this->assertNotContains(Duty::QUOTATION_APPROVE, $held);

        // The shipped manifest is untouched.
        foreach ([...Demo::DUTIES, Duty::QUOTATION_APPROVE, Duty::QUOTATION_DISCOUNT] as $duty) {
            $this->assertFalse(SalesDutyManifest::shipped()->isActive($duty));
        }

        // Personas hold exactly what their preset says; nobody holds issue or repair.
        $expect = [
            QuotationAccessDemoSeeder::MANAGER => [Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW],
            QuotationAccessDemoSeeder::GOVERNOR => [Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE, Duty::QUOTATION_ASSIGN, Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW],
            QuotationAccessDemoSeeder::RECOVERY => [Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE, Duty::QUOTATION_ASSIGN, Duty::QUOTATION_VIEW],
            QuotationAccessDemoSeeder::DELEGATE => [Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE, Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW],
            QuotationAccessDemoSeeder::COLLABORATOR => [Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW],
            QuotationAccessDemoSeeder::VIEWER => [Duty::QUOTATION_VIEW],
            QuotationAccessDemoSeeder::DIRECTOR => [Duty::QUOTATION_APPROVE, Duty::QUOTATION_VIEW],
        ];
        foreach ($expect as $email => $duties) {
            $this->assertEqualsCanonicalizing($duties, $this->held($this->user($email)), $email);
        }

        // Ownership, units, shares: real Actions left their history.
        $this->assertSame(2, DB::table('quotation_access_grants')->where('level', 'VIEW')->count(), 'two VIEW shares (one later revoked)');
        $this->assertSame(1, DB::table('quotation_access_grants')->where('level', 'PREPARE')->whereNotNull('ends_at')->count());
        $this->assertSame(1, DB::table('quotation_access_revocations')->count());
        $this->assertSame(2, DB::table('quotation_ownership_events')->where('kind', 'REASSIGNED')->count());
        $this->assertSame(1, DB::table('quotation_ownership_events')->where('kind', 'UNIT_CHANGED')->count());
        $unitManager = $this->user(QuotationAccessDemoSeeder::MANAGER);
        $this->assertSame(1, QuotationFamily::query()->where('responsible_owner_id', $unitManager->id)->whereNotNull('access_unit_id')->count());
        $this->assertTrue(OrganisationUnit::query()->where('code', 'hq')->firstOrFail()->members()->where('users.id', $unitManager->id)->exists());

        // Two-stage approval: both fictitious scenarios exist, the first still waiting for the director.
        $statuses = ApprovalRequest::query()->where('subject_type', 'QUOTATION_REVISION')->orderBy('id')->get()->map(fn ($r) => $r->status->value)->all();
        $this->assertSame(['PENDING', 'APPROVED'], array_slice($statuses, -2));
    }

    public function test_each_preset_gives_exactly_its_rights_keeps_unrelated_access_and_never_grants_repair(): void
    {
        $this->seeded();
        $main = $this->user(Demo::MAIN_EMAIL);
        $unrelated = array_values(array_diff($this->held($main), [...Demo::DUTIES, ...QuotationDemoPresets::ADDONS]));
        $this->assertNotSame([], $unrelated, 'the main login holds unrelated ERP access');
        $apply = app(ApplyQuotationDemoPreset::class);

        foreach (QuotationDemoPresets::all() as $name => $definition) {
            $result = $apply($name);
            $rights = collect($result['rights'])->keyBy('duty');
            $held = $this->held($main);

            $role = $definition['role'] === null ? [] : QuotationDemoPresets::roles()[$definition['role']];
            foreach (Demo::DUTIES as $duty) {
                $this->assertSame(in_array($duty, $role, true), in_array($duty, $held, true), "{$name}: {$duty}");
            }
            foreach (QuotationDemoPresets::ADDONS as $duty) {
                $this->assertSame(in_array($duty, $definition['addons'], true), in_array($duty, $held, true), "{$name}: {$duty}");
            }
            $this->assertNotContains(Duty::DOCUMENT_REPAIR, $held, "{$name}: never repair");
            foreach ($unrelated as $right) {
                $this->assertContains($right, $held, "{$name}: unrelated right {$right} is kept");
            }
            if ($definition['scope'] !== null && $definition['scope'] !== 'ALL') {
                $this->assertNotSame('ALL', $rights[Duty::QUOTATION_VIEW]['scope'], "{$name}: limited scope");
            }
            if ($definition['scope'] === 'ALL') {
                $this->assertSame('ALL', $rights[Duty::QUOTATION_VIEW]['scope'], "{$name}: company-wide");
            }
            $again = $this->held($main);
            $apply($name);
            $this->assertSame($again, $this->held($main), "{$name}: applying again changes nothing");
        }
    }

    public function test_presets_change_what_the_screens_return_for_the_main_login(): void
    {
        $this->seeded();
        $main = $this->user(Demo::MAIN_EMAIL);
        $apply = app(ApplyQuotationDemoPreset::class);
        $list = function () use ($main) {
            app(PermissionRegistrar::class)->setPermissionsTeamId(1);
            Sanctum::actingAs($main->fresh());

            return $this->getJson('/api/v1/quotations?per_page=100');
        };

        $apply('off');
        $list()->assertForbidden();

        $apply('viewer');
        $all = $list()->assertOk()->json('meta.total');
        $this->assertGreaterThan(10, $all, 'view-only sees every quotation');

        $apply('preparer');
        $own = $list()->assertOk()->json('meta.total');
        $this->assertLessThan($all, $own, 'OWN sees only the quotations it is responsible for');

        $apply('full');
        $this->assertSame($all, $list()->assertOk()->json('meta.total'));

        // Only 'full' opens the Discount authority settings; the duties are otherwise held by no preset.
        $authority = [Duty::FINANCIAL_CONFIGURE, Duty::PRICE_REFERENCE_APPROVE];
        $this->assertEqualsCanonicalizing($authority, array_values(array_intersect($this->held($main), $authority)));
        foreach (['policies', 'references'] as $collection) {
            app(PermissionRegistrar::class)->setPermissionsTeamId(1);
            Sanctum::actingAs($main->fresh());
            $this->getJson("/api/v1/sales-discount-authority/{$collection}")->assertOk();
        }
        foreach (QuotationDemoPresets::all() as $name => $definition) {
            $this->assertSame($name === 'full', array_intersect($authority, $definition['addons']) !== [], "{$name}: discount authority duties");
        }
        // The Customer decisions screen opens under 'full' (it was 403 while these duties were in no preset).
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Sanctum::actingAs($main->fresh());
        $this->getJson('/api/v1/customer-decision-cases')->assertOk();
        $decision = [...SalesDevPreview::CUSTOMER_DECISION_DUTIES, ...SalesDevPreview::AUTHORITY_EVIDENCE_DUTIES];
        $this->assertEqualsCanonicalizing($decision, array_values(array_intersect($this->held($main), $decision)));
        foreach (QuotationDemoPresets::all() as $name => $definition) {
            $this->assertSame($name === 'full', array_intersect($decision, $definition['addons']) !== [], "{$name}: customer decision duties");
        }
        $apply('governor');
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Sanctum::actingAs($main->fresh());
        $this->getJson('/api/v1/customer-decision-cases')->assertForbidden();
        $this->assertSame([], array_values(array_intersect($this->held($main), $decision)));
        $this->assertSame([], array_values(array_intersect($this->held($main), $authority)), 'switching away takes them back');
    }

    public function test_the_main_login_cannot_apply_a_preset_to_itself_and_a_non_demo_actor_is_refused(): void
    {
        $this->seeded();
        $main = $this->user(Demo::MAIN_EMAIL);
        $operator = $this->user(Demo::OPERATOR_EMAIL);
        $this->assertThrows(fn () => app(ApplyQuotationDemoPreset::class)('viewer', $main, $main), DomainInvariantException::class, 'cannot change your own access');
        $this->assertThrows(fn () => app(ApplyQuotationDemoPreset::class)('viewer', $this->user(QuotationAccessDemoSeeder::MANAGER), $main), DomainInvariantException::class);
        $this->assertThrows(fn () => app(ApplyQuotationDemoPreset::class)('nonsense'), DomainInvariantException::class, 'Unknown preset');
        // The ordinary full-replacement path still refuses the operator handing the main login a duty the operator does not hold.
        $this->assertThrows(fn () => app(SyncMemberAccess::class)($main, ['role_ids' => [], 'direct_permissions' => [Duty::QUOTATION_ISSUE], 'scope_limits' => []], $operator), DomainInvariantException::class);
    }

    public function test_the_command_lists_applies_and_refuses_outside_the_local_preview(): void
    {
        $this->seeded();
        $this->artisan('sales:demo-preset', ['--list' => true])->assertSuccessful();
        $this->artisan('sales:demo-preset', ['preset' => 'governor'])->assertSuccessful();
        $this->assertContains(Duty::QUOTATION_ASSIGN, $this->held($this->user(Demo::MAIN_EMAIL)));
        $this->artisan('sales:demo-preset', ['--revoke' => true])->assertSuccessful();
        $this->assertNotContains(Duty::QUOTATION_ASSIGN, $this->held($this->user(Demo::MAIN_EMAIL)));
        $this->artisan('sales:demo-preset', ['preset' => 'bogus'])->assertFailed();

        config(['sales.dev_preview.quotation_duties' => false]);
        $this->artisan('sales:demo-preset', ['preset' => 'full'])->assertFailed();
    }
}
