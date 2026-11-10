<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Actions\BootstrapDemoAccessOperator;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Support\Demo\QuotationDemoBoundary as Demo;
use App\Support\PermissionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR 0155: the create-once first-holder bootstrap of the synthetic demo access operator, and everything it refuses. */
final class BootstrapDemoAccessOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function activate(): void
    {
        $manifest = SalesDutyManifest::shipped()->withActivated(Demo::DUTIES);
        $this->app->instance(SalesDutyManifest::class, $manifest);
        $manifest->registerWith(app(PermissionRegistry::class));
        app(PermissionRegistry::class)->registerForTeam(1);
    }

    private function operator(): User
    {
        return User::factory()->create(['email' => Demo::OPERATOR_EMAIL]);
    }

    private function initiator(): User
    {
        return $this->initiator ??= User::factory()->create();
    }

    private ?User $initiator = null;

    /** @return list<string> */
    private function held(User $user): array
    {
        return $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();
    }

    private function approved(): array
    {
        $duties = Demo::DUTIES;
        sort($duties);

        return $duties;
    }

    public function test_it_grants_exactly_the_approved_set_once_with_a_receipt_and_an_audit_row(): void
    {
        $this->activate();
        $operator = $this->operator();
        $this->assertTrue(app(BootstrapDemoAccessOperator::class)($operator, $this->initiator()));

        $this->assertSame($this->approved(), $this->held($operator));
        $this->assertSame([], array_intersect($this->held($operator), [Duty::QUOTATION_ISSUE, Duty::DOCUMENT_REPAIR, Duty::QUOTATION_APPROVE, Duty::QUOTATION_WITHDRAW]));
        $receipt = DB::table('demo_access_operator_bootstraps')->where('user_id', $operator->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame($this->approved(), collect(json_decode($receipt->grants, true))->sort()->values()->all());
        $this->assertDatabaseHas('activity_log', ['description' => 'demo access operator bootstrapped', 'subject_id' => $operator->id, 'causer_id' => $this->initiator()->id]);
        $this->assertSame($this->initiator()->id, (int) $receipt->initiated_by_user_id);
        $this->assertDatabaseCount('access_scope_limits', 0);
    }

    public function test_a_rerun_writes_nothing_and_never_replenishes_a_revoked_grant(): void
    {
        $this->activate();
        $operator = $this->operator();
        $bootstrap = app(BootstrapDemoAccessOperator::class);
        $bootstrap($operator, $this->initiator());
        $operator->revokePermissionTo(Duty::QUOTATION_ACCESS_REVOKE);
        $audit = DB::table('activity_log')->count();

        $this->assertFalse($bootstrap($operator->fresh(), $this->initiator()));
        $this->assertNotContains(Duty::QUOTATION_ACCESS_REVOKE, $this->held($operator), 'a rerun is not a repair');
        $this->assertSame($audit, DB::table('activity_log')->count());
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 1);
    }

    public function test_a_replacement_user_with_the_operator_email_cannot_repeat_the_bootstrap(): void
    {
        $this->activate();
        $original = $this->operator();
        $bootstrap = app(BootstrapDemoAccessOperator::class);
        $this->assertTrue($bootstrap($original, $this->initiator()));

        // The original is renamed away and a brand-new user takes the designated identity: it has a new id and no receipt of its own.
        DB::table('users')->where('id', $original->id)->update(['email' => 'renamed-operator@erp.x1techs.com']);
        $replacement = $this->operator();
        $this->assertNotSame($original->id, $replacement->id);

        $this->assertThrows(fn () => $bootstrap($replacement, $this->initiator()), DomainInvariantException::class, 'already');
        $this->assertSame([], $this->held($replacement));
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 1);
    }

    public function test_the_database_allows_one_receipt_per_company_and_operator_identity(): void
    {
        $this->activate();
        $original = $this->operator();
        app(BootstrapDemoAccessOperator::class)($original, $this->initiator());
        $other = User::factory()->create();

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('demo_access_operator_bootstraps')->insert([
            'company_id' => $original->company_id, 'user_id' => $other->id, 'email' => strtoupper(Demo::OPERATOR_EMAIL), 'grants' => json_encode($this->approved()), 'provenance' => 'second receipt',
        ])), QueryException::class);
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 1);
    }

    public function test_an_operator_already_holding_a_duty_outside_the_ceiling_is_refused(): void
    {
        $manifest = SalesDutyManifest::shipped()->withActivated([...Demo::DUTIES, Duty::QUOTATION_ISSUE]);
        $this->app->instance(SalesDutyManifest::class, $manifest);
        $manifest->registerWith(app(PermissionRegistry::class));
        app(PermissionRegistry::class)->registerForTeam(1);
        $operator = $this->operator();
        $operator->givePermissionTo(Duty::QUOTATION_ISSUE);

        $this->assertThrows(fn () => app(BootstrapDemoAccessOperator::class)($operator, $this->initiator()), DomainInvariantException::class, 'outside');
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 0);
        $this->assertSame([Duty::QUOTATION_ISSUE], $this->held($operator));
    }

    public function test_any_other_grant_set_is_refused_including_issue_and_repair(): void
    {
        $this->activate();
        $operator = $this->operator();
        $bootstrap = app(BootstrapDemoAccessOperator::class);
        foreach ([[...Demo::DUTIES, Duty::QUOTATION_ISSUE], [...Demo::DUTIES, Duty::DOCUMENT_REPAIR], [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE], []] as $set) {
            $this->assertThrows(fn () => $bootstrap($operator, $this->initiator(), $set), DomainInvariantException::class, 'exactly the approved');
        }
        $this->assertSame([], $this->held($operator));
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 0);

        $bootstrap($operator, $this->initiator());
        $this->assertThrows(fn () => $bootstrap($operator, $this->initiator(), [...Demo::DUTIES, Duty::QUOTATION_ISSUE]), DomainInvariantException::class, 'exactly the approved');
        $this->assertSame($this->approved(), $this->held($operator), 'after success a different set is refused and nothing changes');
    }

    public function test_only_the_exact_operator_identity_in_company_one_and_active_is_accepted(): void
    {
        $this->activate();
        $bootstrap = app(BootstrapDemoAccessOperator::class);
        $other = User::factory()->create(['email' => 'someone-else@erp.x1techs.com']);
        $this->assertThrows(fn () => $bootstrap($other, $this->initiator()), DomainInvariantException::class, 'approved synthetic demo access operator');
        $main = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $this->assertThrows(fn () => $bootstrap($main, $this->initiator()), DomainInvariantException::class, 'approved synthetic demo access operator');

        $suspended = $this->operator();
        DB::table('users')->where('id', $suspended->id)->update(['status' => 'SUSPENDED']);
        $this->assertThrows(fn () => $bootstrap($suspended, $this->initiator()), DomainInvariantException::class, 'active member');

        // A look-alike in another company, hydrated as if it were ours, is refused before anything is written.
        $company = DB::table('companies')->insertGetId(['name' => 'Foreign synthetic company', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Foreign operator', 'email' => 'foreign-operator@fixture.test', 'password' => 'fixture-not-a-login', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::withoutGlobalScopes()->findOrFail($id);
        $this->assertThrows(fn () => $bootstrap($foreign, $this->initiator()), DomainInvariantException::class, 'unavailable');
        $this->assertSame([], $this->held($other));
        $this->assertSame([], $this->held($main));
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 0);
    }

    public function test_production_and_inactive_duties_are_refused_without_a_write(): void
    {
        $operator = $this->operator();
        // The duties are STAGED in the shipped manifest: the Action never activates them.
        $this->assertThrows(fn () => app(BootstrapDemoAccessOperator::class)($operator, $this->initiator()), DomainInvariantException::class, 'never activates a duty');

        $this->activate();
        config(['demo.mode' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertThrows(fn () => app(BootstrapDemoAccessOperator::class)($operator, $this->initiator()), DomainInvariantException::class, 'unavailable');
        $this->app->detectEnvironment(fn () => 'testing');
        config(['demo.mode' => false]);
        $this->app->detectEnvironment(fn () => 'staging');
        $this->assertThrows(fn () => app(BootstrapDemoAccessOperator::class)($operator, $this->initiator()), DomainInvariantException::class, 'unavailable');

        $this->assertSame([], $operator->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 0);
    }

    public function test_the_receipt_is_append_only_in_the_database(): void
    {
        $this->activate();
        $operator = $this->operator();
        app(BootstrapDemoAccessOperator::class)($operator, $this->initiator());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('demo_access_operator_bootstraps')->update(['provenance' => 'rewritten'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('demo_access_operator_bootstraps')->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::statement('TRUNCATE demo_access_operator_bootstraps')), QueryException::class);
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 1);
    }

    public function test_the_initiator_must_be_another_active_member_of_the_same_company(): void
    {
        $this->activate();
        $operator = $this->operator();
        $bootstrap = app(BootstrapDemoAccessOperator::class);

        $this->assertThrows(fn () => $bootstrap($operator, $operator), DomainInvariantException::class, 'initiated');
        $suspended = User::factory()->create(['status' => MemberStatus::Suspended]);
        $this->assertThrows(fn () => $bootstrap($operator, $suspended), DomainInvariantException::class, 'initiated');
        $this->assertDatabaseCount('demo_access_operator_bootstraps', 0);
        $this->assertSame([], $this->held($operator));
    }

    public function test_a_receipt_written_without_an_initiator_keeps_a_null_and_the_database_refuses_a_foreign_one(): void
    {
        $this->activate();
        $operator = $this->operator();
        $row = ['company_id' => 1, 'user_id' => $operator->id, 'email' => $operator->email, 'grants' => json_encode($this->approved()), 'provenance' => 'before the initiator was recorded'];
        DB::table('demo_access_operator_bootstraps')->insert($row);
        $this->assertNull(DB::table('demo_access_operator_bootstraps')->where('user_id', $operator->id)->value('initiated_by_user_id'));

        $this->expectException(QueryException::class);
        DB::table('demo_access_operator_bootstraps')->insert([...$row, 'user_id' => $this->initiator()->id, 'email' => 'x@example.com', 'initiated_by_user_id' => 999999]);
    }
}
