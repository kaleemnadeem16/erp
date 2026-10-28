<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationLogicalScope;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CA0a — the disabled customer-decision intake storage, attacked in raw SQL: tenancy by composite keys, immutable case identity, append-only components and evidence, one
 * successor per predecessor, truthful occurrence precision, bounded vocabularies, and the database's own clock. No Action, route or permission exists to reach any of it.
 */
class CustomerDecisionIntakeFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Party $party;

    private QuotationFamily $family;

    private QuotationLogicalScope $scope;

    private int $otherCompany;

    private int $otherUser;

    private int $otherParty;

    private int $otherFamily;

    private int $otherScope;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->party = Party::create(['code' => 'CUSTOMER', 'name' => 'Customer Ltd']);
        $this->family = QuotationFamily::create(['created_by' => $this->user->id]);
        $this->scope = QuotationLogicalScope::create(['family_id' => $this->family->id, 'intent_ref' => 'scope-a', 'created_by' => $this->user->id, 'created_at' => now()]);
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherParty = DB::table('parties')->insertGetId(['company_id' => $this->otherCompany, 'code' => 'F', 'name' => 'Foreign', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherFamily = DB::table('quotation_families')->insertGetId(['company_id' => $this->otherCompany, 'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $this->otherScope = DB::table('quotation_logical_scopes')->insertGetId(['company_id' => $this->otherCompany, 'family_id' => $this->otherFamily, 'intent_ref' => 'scope-x', 'created_by' => $this->otherUser, 'created_at' => now()]);
    }

    private function assertDbRefuses(Closure $call, ?string $needle = null): void
    {
        try {
            DB::transaction($call);
            $this->fail('Expected the database to refuse.');
        } catch (QueryException $refusal) {
            if ($needle !== null) {
                $this->assertStringContainsString($needle, $refusal->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }

    private function newCase(?string $identity = null, ?int $party = null): CustomerDecisionCase
    {
        return CustomerDecisionCase::create(['party_id' => $party ?? $this->party->id, 'intake_identity' => $identity ?? 'intake-'.++$this->keys, 'recorded_by' => $this->user->id]);
    }

    private function newComponent(CustomerDecisionCase $case, ?string $intent = null): CustomerDecisionComponent
    {
        return CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => $intent ?? 'intent-'.++$this->keys, 'family_id' => $this->family->id,
            'original_scope_anchor_id' => $this->scope->id, 'recorded_by' => $this->user->id]);
    }

    /** @param array<string, mixed> $more */
    private function newEvidence(CustomerDecisionCase $case, array $more = []): CustomerDecisionEvidence
    {
        return CustomerDecisionEvidence::create($more + ['case_id' => $case->id, 'source_kind' => 'PURCHASE_ORDER', 'authority_verification_basis' => 'UNVERIFIED', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'A purchase order arrived by post.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->user->id]);
    }

    /** @param array<string, mixed> $more */
    private function rawEvidence(int $case, int $company, int $recorder, array $more = []): array
    {
        return $more + ['company_id' => $company, 'case_id' => $case, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'STATED_BY_CUSTOMER', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'An email.', 'observation_state' => 'OBSERVED', 'recorded_by' => $recorder, 'recorded_at' => now()];
    }

    public function test_the_three_records_store_in_this_company_and_stamp_the_database_clock(): void
    {
        $case = $this->newCase('PO-1042');
        $component = $this->newComponent($case);
        $evidence = $this->newEvidence($case, ['external_reference' => 'PO-1042', 'customer_representative_snapshot' => 'A. Buyer, procurement', 'authority_verification_basis' => 'DOCUMENT_ON_FILE',
            'occurrence_precision' => 'DATE', 'occurred_at' => '2026-10-20 00:00:00+00', 'source_timezone' => 'Asia/Karachi']);

        $this->assertSame(1, (int) DB::table('customer_decision_cases')->where('id', $case->id)->value('company_id'));
        $this->assertSame(0, (int) $case->refresh()->generation);
        $this->assertSame($case->id, $component->case_id);
        $this->assertNull($this->newEvidence($case)->predecessor_id);
        $this->assertSame('DATE', $evidence->refresh()->occurrence_precision);
        foreach ([$case, $component, $evidence] as $row) {
            $this->assertLessThan(10, abs(now()->diffInSeconds($row->refresh()->recorded_at, false)), 'recorded_at is the database clock');
        }
        // A supplied recording time is replaced, not trusted.
        $forged = CustomerDecisionCase::create(['party_id' => $this->party->id, 'intake_identity' => 'forged-time', 'recorded_by' => $this->user->id, 'recorded_at' => '2001-01-01 00:00:00+00']);
        $this->assertGreaterThan(2020, $forged->refresh()->recorded_at->year);
    }

    public function test_the_models_cannot_be_pointed_at_another_company(): void
    {
        $this->expectException(DomainInvariantException::class);
        CustomerDecisionCase::create(['company_id' => $this->otherCompany, 'party_id' => $this->party->id, 'intake_identity' => 'x', 'recorded_by' => $this->user->id]);
    }

    public function test_every_cross_company_reference_is_refused_by_the_database(): void
    {
        $mine = $this->newCase();
        $theirs = DB::table('customer_decision_cases')->insertGetId(['company_id' => $this->otherCompany, 'party_id' => $this->otherParty, 'intake_identity' => 'theirs', 'recorded_by' => $this->otherUser, 'recorded_at' => now()]);

        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->insert(['company_id' => $this->otherCompany, 'party_id' => $this->party->id, 'intake_identity' => 'foreign-party', 'recorded_by' => $this->otherUser, 'recorded_at' => now()]), 'customer_decision_cases_party_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->insert(['company_id' => $this->otherCompany, 'party_id' => $this->otherParty, 'intake_identity' => 'foreign-user', 'recorded_by' => $this->user->id, 'recorded_at' => now()]), 'customer_decision_cases_recorder_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_components')->insert(['company_id' => $this->otherCompany, 'case_id' => $mine->id, 'intent_identity' => 'i', 'family_id' => $this->otherFamily,
            'original_scope_anchor_id' => $this->otherScope, 'recorded_by' => $this->otherUser, 'recorded_at' => now()]), 'customer_decision_components_case_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_components')->insert(['company_id' => $this->otherCompany, 'case_id' => $theirs, 'intent_identity' => 'i', 'family_id' => $this->family->id,
            'original_scope_anchor_id' => $this->scope->id, 'recorded_by' => $this->otherUser, 'recorded_at' => now()]), 'customer_decision_components_family_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_components')->insert(['company_id' => 1, 'case_id' => $mine->id, 'intent_identity' => 'i', 'family_id' => $this->family->id,
            'original_scope_anchor_id' => $this->otherScope, 'recorded_by' => $this->user->id, 'recorded_at' => now()]), 'customer_decision_components_scope_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_evidence')->insert($this->rawEvidence($mine->id, $this->otherCompany, $this->otherUser)), 'customer_decision_evidence_case_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_evidence')->insert($this->rawEvidence($mine->id, 1, $this->otherUser)), 'customer_decision_evidence_recorder_fk');
    }

    public function test_a_scope_of_another_family_is_not_an_anchor_even_in_the_same_company(): void
    {
        $case = $this->newCase();
        $otherFamilyInMyCompany = QuotationFamily::create(['created_by' => $this->user->id]);

        $this->assertDbRefuses(fn () => DB::table('customer_decision_components')->insert(['company_id' => 1, 'case_id' => $case->id, 'intent_identity' => 'i', 'family_id' => $otherFamilyInMyCompany->id,
            'original_scope_anchor_id' => $this->scope->id, 'recorded_by' => $this->user->id, 'recorded_at' => now()]), 'customer_decision_components_scope_fk');
    }

    public function test_identities_are_unique_where_they_should_be_and_only_there(): void
    {
        $case = $this->newCase('PO-7');
        $this->newComponent($case, 'delivery');
        $other = $this->newCase('PO-8');

        $this->assertDbRefuses(fn () => $this->newCase('PO-7'), 'customer_decision_cases_intake_unique');
        $this->assertDbRefuses(fn () => $this->newComponent($case, 'delivery'), 'customer_decision_components_intent_unique');
        $this->newComponent($other, 'delivery');
        $this->assertSame(2, CustomerDecisionComponent::query()->where('intent_identity', 'delivery')->count(), 'the same intent in another case is fine');
        $theirs = DB::table('customer_decision_cases')->insertGetId(['company_id' => $this->otherCompany, 'party_id' => $this->otherParty, 'intake_identity' => 'PO-7', 'recorded_by' => $this->otherUser, 'recorded_at' => now()]);
        $this->assertGreaterThan(0, $theirs, 'the same intake identity in another company is fine');
    }

    public function test_a_case_identity_cannot_be_rewritten_and_a_case_is_never_deleted(): void
    {
        $case = $this->newCase('PO-9');
        $this->newEvidence($case);
        $second = $this->party->replicate(['code'])->fill(['code' => 'CUSTOMER2']);
        $second->save();

        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->where('id', $case->id)->update(['intake_identity' => 'renamed']), 'identity is immutable');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->where('id', $case->id)->update(['party_id' => $second->id]), 'identity is immutable');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->where('id', $case->id)->update(['recorded_by' => $this->user->id, 'recorded_at' => '2001-01-01 00:00:00+00']), 'identity is immutable');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->where('id', $case->id)->delete(), 'never deleted');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->where('id', $this->newCase('PO-10')->id)->delete(), 'never deleted');
        $this->assertSame('PO-9', $case->refresh()->intake_identity);
    }

    public function test_the_disabled_generation_never_moves_backwards(): void
    {
        $case = $this->newCase();
        DB::table('customer_decision_cases')->where('id', $case->id)->update(['generation' => 3]);

        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->where('id', $case->id)->update(['generation' => 2]), 'never moves backwards');
        $this->assertSame(3, (int) $case->refresh()->generation);
    }

    public function test_components_and_evidence_refuse_update_delete_and_truncate(): void
    {
        $case = $this->newCase();
        $component = $this->newComponent($case);
        $evidence = $this->newEvidence($case);

        $this->assertDbRefuses(fn () => DB::table('customer_decision_components')->where('id', $component->id)->update(['intent_identity' => 'x']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_components')->where('id', $component->id)->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_evidence')->where('id', $evidence->id)->update(['factual_description' => 'rewritten']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_evidence')->where('id', $evidence->id)->delete(), 'append-only');
        foreach (['customer_decision_components', 'customer_decision_evidence', 'customer_decision_cases'] as $table) {
            $this->assertDbRefuses(fn () => DB::statement("TRUNCATE {$table}"));
            $this->assertDbRefuses(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
        }
        $this->assertSame('A purchase order arrived by post.', $evidence->refresh()->factual_description);
    }

    public function test_an_evidence_chain_has_one_successor_per_predecessor_within_one_case(): void
    {
        $case = $this->newCase();
        $other = $this->newCase();
        $first = $this->newEvidence($case);
        $second = $this->newEvidence($case, ['predecessor_id' => $first->id, 'source_version' => '2']);

        $this->assertSame($first->id, $second->predecessor_id);
        $this->assertDbRefuses(fn () => $this->newEvidence($case, ['predecessor_id' => $first->id]), 'customer_decision_evidence_successor_unique');
        $unextended = $this->newEvidence($case);
        $this->assertDbRefuses(fn () => $this->newEvidence($other, ['predecessor_id' => $unextended->id]), 'customer_decision_evidence_predecessor_fk');
        $this->assertDbRefuses(fn () => DB::table('customer_decision_evidence')->insert($this->rawEvidence($case->id, $this->otherCompany, $this->otherUser, ['predecessor_id' => $first->id])));
        $this->assertSame(3, CustomerDecisionEvidence::query()->where('case_id', $case->id)->count(), 'a chain of two and one more independent root; the refused inserts left nothing');
        $this->assertNotNull($this->newEvidence($case)->id, 'another independent root in the same case is allowed');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badEvidence(): array
    {
        return [
            'unknown source kind' => [['source_kind' => 'TELEPATHY'], 'shape'],
            'unknown verification basis' => [['authority_verification_basis' => 'TRUST_ME'], 'shape'],
            'an observation state other than observed' => [['observation_state' => 'ACCEPTED'], 'shape'],
            'unknown precision' => [['occurrence_precision' => 'ROUGHLY'], 'shape'],
            'an unknown occurrence that still carries a time' => [['occurrence_precision' => 'UNKNOWN', 'occurred_at' => '2026-10-20 00:00:00+00'], 'shape'],
            'an unknown occurrence that still carries a zone' => [['occurrence_precision' => 'UNKNOWN', 'source_timezone' => 'Asia/Karachi'], 'shape'],
            'a date without a time zone' => [['occurrence_precision' => 'DATE', 'occurred_at' => '2026-10-20 00:00:00+00'], 'shape'],
            'a date without a date' => [['occurrence_precision' => 'DATE', 'source_timezone' => 'Asia/Karachi'], 'shape'],
            'an instant with an end' => [['occurrence_precision' => 'INSTANT', 'occurred_at' => '2026-10-20 00:00:00+00', 'occurred_until' => '2026-10-21 00:00:00+00'], 'shape'],
            'an interval ending before it starts' => [['occurrence_precision' => 'INTERVAL', 'occurred_at' => '2026-10-21 00:00:00+00', 'occurred_until' => '2026-10-20 00:00:00+00'], 'shape'],
            'an interval with no end' => [['occurrence_precision' => 'INTERVAL', 'occurred_at' => '2026-10-21 00:00:00+00'], 'shape'],
            'an empty description' => [['factual_description' => '   '], 'shape'],
            'an over-long description' => [['factual_description' => 'x'], 'shape'],
            'a blank external reference' => [['external_reference' => '  '], 'shape'],
            'a padded external reference' => [['external_reference' => ' PO-1 '], 'shape'],
            'a blank representative' => [['customer_representative_snapshot' => ' '], 'shape'],
        ];
    }

    /** @param array<string, mixed> $bad */
    #[DataProvider('badEvidence')]
    public function test_malformed_or_dishonest_evidence_is_refused_by_the_database(array $bad, string $needle): void
    {
        $case = $this->newCase();
        if (($bad['factual_description'] ?? null) === 'x') {
            $bad['factual_description'] = str_repeat('x', 4001);
        }

        $this->assertDbRefuses(fn () => DB::table('customer_decision_evidence')->insert($this->rawEvidence($case->id, 1, $this->user->id, $bad)), $needle);
    }

    public function test_truthful_occurrences_are_all_storable(): void
    {
        $case = $this->newCase();

        $unknown = $this->newEvidence($case);
        $instant = $this->newEvidence($case, ['occurrence_precision' => 'INSTANT', 'occurred_at' => '2026-10-20 09:30:00+00']);
        $interval = $this->newEvidence($case, ['occurrence_precision' => 'INTERVAL', 'occurred_at' => '2026-10-20 09:00:00+00', 'occurred_until' => '2026-10-20 17:00:00+00', 'source_timezone' => 'Asia/Karachi']);

        $this->assertNull($unknown->refresh()->occurred_at);
        $this->assertNull($unknown->customer_representative_snapshot, 'an unknown representative stays NULL');
        $this->assertSame('INSTANT', $instant->refresh()->occurrence_precision);
        $this->assertTrue($interval->refresh()->occurred_until->greaterThan($interval->occurred_at));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badCasesAndComponents(): array
    {
        return [
            'an empty intake identity' => [['intake_identity' => ''], 'customer_decision_cases_shape'],
            'a padded intake identity' => [['intake_identity' => ' PO-1'], 'customer_decision_cases_shape'],
            'an over-long intake identity' => [['intake_identity' => 'x'], 'too long'],
            'a negative generation' => [['generation' => -1], 'customer_decision_cases_shape'],
        ];
    }

    /** @param array<string, mixed> $bad */
    #[DataProvider('badCasesAndComponents')]
    public function test_a_malformed_case_is_refused(array $bad, string $needle): void
    {
        if (($bad['intake_identity'] ?? null) === 'x') {
            $bad['intake_identity'] = str_repeat('x', 121);
        }
        $row = $bad + ['company_id' => 1, 'party_id' => $this->party->id, 'intake_identity' => 'ok', 'recorded_by' => $this->user->id, 'recorded_at' => now()];

        $this->assertDbRefuses(fn () => DB::table('customer_decision_cases')->insert($row), $needle);
    }

    public function test_nothing_in_this_slice_can_be_reached_or_confers_anything(): void
    {
        $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = 'public' and table_name like 'customer\\_decision%'"))->pluck('table_name')->sort()->values()->all();
        $this->assertSame(['customer_decision_cases', 'customer_decision_components', 'customer_decision_evidence'], $tables);
        $this->assertSame([], DB::select("select table_name from information_schema.tables where table_schema = 'public' and table_name ~ '^customer_(acceptance|claim|entitlement|decision_receipt)'"), 'no acceptance, claim, entitlement or receipt table exists');
        foreach ($tables as $table) {
            $columns = DB::table('information_schema.columns')->where('table_name', $table)->pluck('column_name')->all();
            $this->assertSame([], array_values(array_filter($columns, fn (string $c): bool => preg_match('/amount|price|cost|quantity|currency|digest|object|attachment|accepted|target|job_id|revision_id/', $c) === 1)), "{$table} carries no money, quantity, attachment, issued-source or target column");
        }
        $this->assertSame([], array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn ($route): bool => str_contains($route->uri(), 'customer-decision') || str_contains($route->uri(), 'customer_decision'))), 'no route');
    }
}
