<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationLogicalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * CA0a — installing and removing the disabled intake foundation, empty and populated. Down and up run inside the test transaction (PostgreSQL DDL is transactional), so every case
 * is undone at the end; migrate-cycle.sh proves the same on a fresh database. Existing Sales, Jobs and Documents guards and rows are unaffected either way.
 */
class CustomerDecisionIntakeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['customer_decision_cases', 'customer_decision_components', 'customer_decision_evidence'];

    private const FUNCTIONS = ['customer_decision_append_only_guard', 'customer_decision_case_guard', 'customer_decision_case_truncate_guard', 'customer_decision_evidence_chain_guard', 'customer_decision_stamp_recorded_at'];

    private User $user;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->party = Party::create(['code' => 'CUSTOMER', 'name' => 'Customer Ltd']);
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_27_140000_create_customer_decision_intake_foundation.php');
    }

    /**
     * The later gated slices that reference the intake storage, in the order they were applied (acceptance, then the handover that references an acceptance). They roll back in reverse
     * before the intake can, and apply again after it.
     *
     * @return list<object>
     */
    private function dependants(): array
    {
        return [
            require base_path('database/migrations/2026_12_11_100000_create_customer_acceptance_foundation.php'),
            require base_path('database/migrations/2026_12_12_100000_create_service_handover_preparation.php'),
            require base_path('database/migrations/2026_12_13_100000_create_service_release_evidence.php'),
        ];
    }

    private function rollBackDependants(): void
    {
        foreach (array_reverse($this->dependants()) as $migration) {
            $migration->down();
        }
    }

    private function applyDependants(): void
    {
        foreach ($this->dependants() as $migration) {
            $migration->up();
        }
    }

    /** @return list<string> */
    private function ourFunctions(): array
    {
        return collect(DB::select("select proname from pg_proc where proname like 'customer_decision_%' order by 1"))->pluck('proname')->all();
    }

    /** @return list<string> every trigger that is not ours, so "existing guards unchanged" is a comparison and not an assumption */
    private function otherTriggers(): array
    {
        return collect(DB::select("select c.relname || '.' || t.tgname as name from pg_trigger t join pg_class c on c.oid = t.tgrelid where not t.tgisinternal and c.relname not like 'customer\\_decision\\_%' order by 1"))->pluck('name')->all();
    }

    private function assertInstalled(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame(self::FUNCTIONS, $this->ourFunctions());
        $this->assertSame(10, (int) DB::selectOne("select count(*) c from pg_trigger t join pg_class c on c.oid = t.tgrelid where not t.tgisinternal and c.relname like 'customer\\_decision\\_%'")->c, 'stamp x3, chain guard, append-only row+truncate x2, case guard + truncate guard');
    }

    private function assertUninstalled(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertSame([], $this->ourFunctions());
    }

    private function populate(string $depth): void
    {
        $case = CustomerDecisionCase::create(['party_id' => $this->party->id, 'intake_identity' => 'PO-1', 'recorded_by' => $this->user->id]);
        if ($depth === 'component') {
            $family = QuotationFamily::create(['created_by' => $this->user->id]);
            $scope = QuotationLogicalScope::create(['family_id' => $family->id, 'intent_ref' => 's', 'created_by' => $this->user->id, 'created_at' => now()]);
            CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => 'delivery', 'family_id' => $family->id, 'original_scope_anchor_id' => $scope->id, 'recorded_by' => $this->user->id]);
        }
        if ($depth === 'evidence') {
            CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'UNVERIFIED', 'occurrence_precision' => 'UNKNOWN',
                'factual_description' => 'An email.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->user->id]);
        }
    }

    public function test_an_empty_store_rolls_back_and_applies_again_leaving_every_other_guard_alone(): void
    {
        $migration = $this->migration();
        $this->assertInstalled();
        $everything = $this->otherTriggers();
        $this->assertNotEmpty($everything, 'there are existing guards to compare');
        // The acceptance and handover storage reference the intake tables, so they roll back first (their own empty-store rollback) and are applied again last.
        $this->rollBackDependants();
        $others = $this->otherTriggers();
        $this->assertNotEmpty($others);
        $families = DB::table('quotation_families')->count();

        $migration->down();
        $this->assertUninstalled();
        $this->assertSame($others, $this->otherTriggers(), 'no other trigger was touched by the rollback');
        $this->assertSame($families, DB::table('quotation_families')->count());
        foreach (['quotation_families', 'quotation_logical_scopes', 'parties', 'users', 'service_jobs', 'job_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} survives the rollback");
        }

        $migration->up();
        $this->assertInstalled();
        $this->assertSame($others, $this->otherTriggers(), 'nor by applying it again');

        $this->applyDependants();
        $this->assertSame($everything, $this->otherTriggers(), 'every guard, including the later slices, is back after the whole chain is applied again');
    }

    /** @return array<string, array{0: string}> */
    public static function populatedDepths(): array
    {
        return ['a case' => ['case'], 'a case and a component' => ['component'], 'a case and evidence' => ['evidence']];
    }

    #[DataProvider('populatedDepths')]
    public function test_a_populated_store_refuses_to_roll_back_before_dropping_anything(string $depth): void
    {
        $this->populate($depth);
        $before = ['cases' => DB::table('customer_decision_cases')->count(), 'components' => DB::table('customer_decision_components')->count(), 'evidence' => DB::table('customer_decision_evidence')->count()];

        try {
            $this->migration()->down();
            $this->fail('A populated store must refuse to roll back.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('would destroy evidence', $refusal->getMessage());
        }

        $this->assertInstalled();
        $this->assertSame($before, ['cases' => DB::table('customer_decision_cases')->count(), 'components' => DB::table('customer_decision_components')->count(), 'evidence' => DB::table('customer_decision_evidence')->count()]);
    }

    public function test_nothing_outside_the_intake_storage_depends_on_it_so_it_can_always_be_rolled_back_on_its_own(): void
    {
        // Issue #101: a relationship from the receipts (or any other table) to the case table would make this rollback fail once that table exists. The receipt rule is a trigger on purpose.
        // The only dependants are the later gated acceptance foundation's own two relationships; they are named so a new one cannot appear unnoticed, and they roll back first (below).
        $outside = DB::select("select conrelid::regclass::text as owner, conname from pg_constraint where contype = 'f' and confrelid::regclass::text like 'customer\\_decision\\_%' and conrelid::regclass::text not like 'customer\\_decision\\_%' order by 1, 2");
        $this->assertSame(['customer_acceptances.customer_acceptance_component_fk', 'customer_acceptances.customer_acceptance_evidence_fk'], array_map(fn (object $row): string => "{$row->owner}.{$row->conname}", $outside), 'only the acceptance foundation may reference the intake storage');

        $views = DB::select("select distinct v.relname from pg_depend d join pg_rewrite r on r.oid = d.objid join pg_class v on v.oid = r.ev_class join pg_class t on t.oid = d.refobjid where t.relname like 'customer\\_decision\\_%' and v.relname not like 'customer\\_decision\\_%'");
        $this->assertSame([], $views, 'no view may depend on the intake storage');

        $this->rollBackDependants();
        $this->migration()->down();
        $this->assertUninstalled();
        $this->assertTrue(Schema::hasTable('sales_customer_decision_receipts'), 'the receipts sit outside and survive');
        $this->migration()->up();
        $this->assertInstalled();
        $this->applyDependants();
    }

    public function test_the_migration_stamp_is_unique_and_sorts_after_everything_it_depends_on(): void
    {
        $names = collect(glob(base_path('database/migrations/*.php')))->map(fn (string $path): string => basename($path))->sort()->values();
        $ours = '2026_10_27_140000_create_customer_decision_intake_foundation.php';
        $stamps = $names->map(fn (string $name): string => substr($name, 0, 17));

        $this->assertTrue($names->contains($ours));
        $this->assertSame(1, $stamps->filter(fn (string $stamp): bool => $stamp === '2026_10_27_140000')->count(), 'no other migration shares the stamp');
        foreach (['2026_10_16_120000_create_quotation_foundation.php', '2026_10_22_140000_create_tax_party_profiles.php', '2026_10_26_090000_create_job_lifecycle_foundation.php'] as $dependency) {
            $this->assertLessThan($names->search($ours), $names->search($dependency), "{$dependency} (parties, users, quotation families and scopes) must run first");
        }
    }
}
