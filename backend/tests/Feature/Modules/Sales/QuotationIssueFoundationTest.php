<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QL3 storage — an issued quotation revision, attacked in raw SQL: a whole issue passes the commit-time agreement, every incomplete or inconsistent issue is refused, an issued
 * revision and its record are immutable, one number per revision and company, tenancy by composite keys. Deferred checks are forced with SET CONSTRAINTS ... IMMEDIATE.
 */
class QuotationIssueFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Party $party;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate(PermissionVocabulary::QUOTATION_PREPARE, 'web');
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(PermissionVocabulary::QUOTATION_PREPARE);
        $this->party = Party::create(['code' => 'CUSTOMER', 'name' => 'Customer Ltd']);
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => $duty->name === PermissionVocabulary::QUOTATION_PREPARE
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    private function today(): string
    {
        return now(Company::findOrFail(1)->timezone)->format('Y-m-d');
    }

    private function draft(): QuotationRevision
    {
        $created = app(CreateQuotationDraft::class)($this->user, $this->key(), ['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(),
            'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        app(UpdateQuotationDraft::class)($this->user, $created->revisionId, $created->inputGeneration, $this->key(), ['add_lines' => [
            ['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '2', 'unit_price' => '100.0000'],
            ['source_kind' => 'MANUAL', 'description' => 'Optional extra', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '30.0000', 'membership' => 'OPTIONAL'],
        ]]);

        return QuotationRevision::findOrFail($created->revisionId);
    }

    /**
     * Writes a whole, valid issue of `$revision` in the legal order, with `$break` free to corrupt any part, then forces the commit-time agreement.
     *
     * @param  array<string, mixed>  $issue  overrides for the quotation_issues row
     * @param  array<string, mixed>  $revisionSet  overrides for the revision update
     * @param  Closure(array<int, array<string, mixed>>): array<int, array<string, mixed>>|null  $lines
     */
    private function writeIssue(QuotationRevision $revision, array $issue = [], array $revisionSet = [], ?Closure $lines = null, ?string $number = null, bool $taxed = true): int
    {
        $allocation = app(NumberingService::class)->allocate(new NumberingContext('QUOTATION', CarbonImmutable::now(), documentId: $revision->id, allocatedBy: $this->user->id));
        $number ??= $allocation->number;
        $draftLines = QuotationDraftLine::query()->where('revision_id', $revision->id)->orderBy('id')->get();
        $rows = [];
        foreach ($draftLines as $line) {
            $net = (string) $line->offered_quantity === '2.0000' ? '200.0000' : '30.0000';
            $counts = $line->membership === 'BASE';
            $rows[] = ['company_id' => 1, 'revision_id' => $revision->id, 'family_id' => $revision->family_id, 'source_line_id' => $line->id, 'logical_scope_id' => $line->logical_scope_id, 'sort_order' => $line->sort_order,
                'section_title' => null, 'description' => $line->description, 'membership' => $line->membership, 'choice_group_id' => null, 'is_baseline' => false, 'counts_in_total' => $counts, 'source_kind' => 'MANUAL',
                'item_id' => null, 'manual_measure' => 'job', 'offered_quantity' => $line->offered_quantity, 'offered_uom_id' => null, 'unit_price' => $line->unit_price, 'price_currency_id' => $revision->currency_id,
                'fx_fingerprint' => null, 'net' => $net, 'tax' => $taxed ? bcmul($net, '0.10', 4) : null, 'gross' => $taxed ? bcadd($net, bcmul($net, '0.10', 4), 4) : null,
                'tax_disposition' => $taxed ? 'TAXABLE' : null, 'tax_evidence_reference' => $taxed ? str_repeat('a', 64) : null];
        }
        if ($lines !== null) {
            $rows = $lines($rows);
        }
        $issueRow = $issue + ['company_id' => 1, 'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'reviewed_generation' => $revision->input_generation, 'revision_created_by' => $revision->created_by,
            'issued_by' => $this->user->id, 'issued_at' => now(), 'official_number' => $number, 'numbering_allocation_id' => $allocation->allocationId, 'policy_version' => 'ISSUE.1', 'unknown_payment_policy' => 'BLOCK',
            'currency_id' => $revision->currency_id, 'pricing_date' => $revision->pricing_date->format('Y-m-d'), 'tax_estimate_date' => $revision->tax_estimate_date?->format('Y-m-d'),
            'valid_through_local_date' => $revision->valid_through_local_date->format('Y-m-d'), 'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()->addMonth(),
            'net_total' => '200.0000', 'optional_net_total' => '30.0000', 'tax_total' => $taxed ? '20.0000' : null, 'gross_total' => $taxed ? '220.0000' : null, 'rounding_policy_fingerprint' => null,
            'rounding_policy_version_no' => null, 'tax_calculation_version' => $taxed ? 'FR3.1' : null, 'tax_generation' => $taxed ? 1 : null, 'tax_evidence_fingerprint' => $taxed ? str_repeat('b', 64) : null,
            'controls_outcome' => 'PASS', 'controls_fingerprint' => null, 'financial_fingerprint' => str_repeat('c', 64)];
        $issueId = DB::table('quotation_issues')->insertGetId($issueRow);
        foreach ($rows as $row) {
            DB::table('quotation_issued_lines')->insert($row + ['issue_id' => $issueId]);
        }
        DB::table('quotation_revisions')->where('id', $revision->id)->update($revisionSet + ['state' => 'ISSUED', 'official_number' => $number, 'numbering_allocation_id' => $allocation->allocationId,
            'issued_by' => $this->user->id, 'issued_timezone' => 'Asia/Karachi', 'expires_at' => $issueRow['expires_at']]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return $issueId;
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

    public function test_a_whole_issue_passes_the_commit_time_agreement_and_stamps_the_database_clock(): void
    {
        $revision = $this->draft();

        $issueId = $this->writeIssue($revision);

        $row = DB::table('quotation_revisions')->find($revision->id);
        $issue = DB::table('quotation_issues')->find($issueId);
        $this->assertSame('ISSUED', $row->state);
        $this->assertSame($issue->official_number, $row->official_number);
        $this->assertSame($issue->issued_at, $row->issued_at, 'one transaction instant for the revision and its issue');
        $this->assertSame(2, DB::table('quotation_issued_lines')->where('issue_id', $issueId)->count());
        $this->assertSame($this->user->id, (int) $issue->issued_by);
    }

    public function test_an_issue_without_tax_is_whole_when_no_line_carries_tax(): void
    {
        $this->assertGreaterThan(0, $this->writeIssue($this->draft(), taxed: false));
    }

    /** @return array<string, array{0: string}> */
    public static function brokenIssues(): array
    {
        return [
            'an issue row for a revision that is still a draft' => ['issue-only'],
            'an issued revision with no issue record' => ['revision-only'],
            'a number that is not its Numbering allocation' => ['wrong-number'],
            'a frozen line missing from the draft lines' => ['missing-line'],
            'a net total that is not the sum of the counted lines' => ['wrong-net'],
            'an optional total that is not the sum of the optional lines' => ['wrong-optional'],
            'a tax total that is not the sum of the counted line taxes' => ['wrong-tax'],
            'a reviewed generation that is not the revision generation' => ['wrong-generation'],
            'an issue instant that differs from the revision' => ['wrong-instant'],
        ];
    }

    #[DataProvider('brokenIssues')]
    public function test_an_incomplete_or_inconsistent_issue_is_refused_at_commit(string $case): void
    {
        $revision = $this->draft();
        $this->expectException(QueryException::class);

        match ($case) {
            'issue-only' => $this->issueRowOnly($revision),
            'revision-only' => $this->revisionOnly($revision),
            'wrong-number' => $this->writeIssue($revision, number: 'NOT-THE-NUMBER'),
            'missing-line' => $this->writeIssue($revision, lines: fn (array $rows): array => [$rows[0]]),
            'wrong-net' => $this->writeIssue($revision, ['net_total' => '999.0000', 'gross_total' => '1019.0000']),
            'wrong-optional' => $this->writeIssue($revision, ['optional_net_total' => '31.0000']),
            'wrong-tax' => $this->writeIssue($revision, ['tax_total' => '21.0000', 'gross_total' => '221.0000']),
            'wrong-generation' => $this->writeIssue($revision, ['reviewed_generation' => 77]),
            'wrong-instant' => $this->writeIssue($revision, ['expires_at' => now()->addYear()], ['expires_at' => now()->addDay()]),
        };
    }

    private function issueRowOnly(QuotationRevision $revision): void
    {
        $allocation = app(NumberingService::class)->allocate(new NumberingContext('QUOTATION', CarbonImmutable::now(), documentId: $revision->id));
        DB::table('quotation_issues')->insert(['company_id' => 1, 'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'reviewed_generation' => 1, 'revision_created_by' => $this->user->id, 'issued_by' => $this->user->id,
            'issued_at' => now(), 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId, 'policy_version' => 'ISSUE.1', 'unknown_payment_policy' => 'BLOCK', 'currency_id' => $revision->currency_id,
            'pricing_date' => $this->today(), 'valid_through_local_date' => now()->addMonth()->format('Y-m-d'), 'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()->addMonth(), 'net_total' => '0', 'controls_outcome' => 'PASS',
            'financial_fingerprint' => str_repeat('c', 64)]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function revisionOnly(QuotationRevision $revision): void
    {
        $allocation = app(NumberingService::class)->allocate(new NumberingContext('QUOTATION', CarbonImmutable::now(), documentId: $revision->id));
        DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'ISSUED', 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId,
            'issued_by' => $this->user->id, 'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()->addMonth()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_an_issued_revision_and_its_record_are_immutable(): void
    {
        $revision = $this->draft();
        $issueId = $this->writeIssue($revision);
        $lineId = (int) DB::table('quotation_issued_lines')->where('issue_id', $issueId)->value('id');
        $draftLineId = (int) DB::table('quotation_draft_lines')->where('revision_id', $revision->id)->value('id');

        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->update(['valid_through_local_date' => '2099-01-01']), 'issued quotation revision is immutable');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'DRAFT']), 'issued quotation revision is immutable');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->delete(), 'cannot be deleted');
        $this->assertDbRefuses(fn () => DB::table('quotation_draft_lines')->where('id', $draftLineId)->update(['unit_price' => '1.0000']), 'draft revision');
        $this->assertDbRefuses(fn () => DB::table('quotation_draft_lines')->where('id', $draftLineId)->delete(), 'draft revision');
        $this->assertDbRefuses(fn () => DB::table('quotation_issues')->where('id', $issueId)->update(['net_total' => '1.0000']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_issues')->where('id', $issueId)->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_issued_lines')->where('id', $lineId)->update(['net' => '1.0000']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_issued_lines')->where('id', $lineId)->delete(), 'append-only');
        foreach (['quotation_issues', 'quotation_issued_lines'] as $table) {
            $this->assertDbRefuses(fn () => DB::statement("TRUNCATE {$table}"));
            $this->assertDbRefuses(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
        }
        $this->assertSame('ISSUED', DB::table('quotation_revisions')->where('id', $revision->id)->value('state'));
    }

    public function test_issuing_cannot_change_the_reviewed_draft_in_the_same_step(): void
    {
        $revision = $this->draft();
        $other = Party::create(['code' => 'OTHER', 'name' => 'Other Ltd']);

        $this->assertDbRefuses(fn () => $this->writeIssue($revision, revisionSet: ['recipient_party_id' => $other->id]), 'does not change the reviewed draft');
    }

    public function test_a_revision_is_issued_once_and_its_number_is_never_shared(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $this->writeIssue($first);

        $this->assertDbRefuses(fn () => DB::table('quotation_issues')->insert(['company_id' => 1, 'family_id' => $first->family_id, 'revision_id' => $first->id] + (array) DB::table('quotation_issues')->where('revision_id', $first->id)->first()), 'quotation_issues');
        $existing = DB::table('quotation_revisions')->where('id', $first->id)->value('official_number');
        $this->assertDbRefuses(fn () => $this->writeIssue($second, number: $existing), 'duplicate key value');
    }

    public function test_an_issued_revision_needs_its_whole_set_of_issue_facts(): void
    {
        $revision = $this->draft();

        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'ISSUED']), 'quotation_revisions_shape');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->update(['official_number' => 'X-1']), 'quotation_revisions_shape');
        $bare = QuotationRevision::query()->create(['family_id' => $revision->family_id, 'currency_id' => $revision->currency_id, 'created_by' => $this->user->id]);
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $bare->id)->update(['state' => 'ISSUED', 'official_number' => 'X-2', 'numbering_allocation_id' => 1, 'issued_by' => $this->user->id,
            'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()]), 'quotation_revisions_shape');
    }

    public function test_the_frozen_line_arithmetic_and_counting_rules_are_checked(): void
    {
        $revision = $this->draft();

        $this->assertDbRefuses(fn () => $this->writeIssue($revision, lines: function (array $rows): array {
            $rows[0]['counts_in_total'] = ! $rows[0]['counts_in_total'];

            return $rows;
        }), 'quotation_issued_lines_shape');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, lines: function (array $rows): array {
            $rows[0]['gross'] = '1.0000';

            return $rows;
        }), 'quotation_issued_lines_shape');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, lines: function (array $rows): array {
            $rows[0]['tax'] = null;

            return $rows;
        }), 'quotation_issued_lines_shape');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, ['unknown_payment_policy' => 'ASSUME_ZERO']), 'quotation_issues_shape');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, ['controls_outcome' => 'FAIL']), 'quotation_issues_shape');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, ['gross_total' => '1.0000']), 'quotation_issues_shape');
    }

    public function test_an_issued_line_is_tied_to_its_own_source_line_revision_scope_and_family(): void
    {
        $revision = $this->draft();
        $other = $this->draft();
        $foreign = QuotationDraftLine::query()->where('revision_id', $other->id)->orderBy('id')->first();
        $mine = QuotationDraftLine::query()->where('revision_id', $revision->id)->orderBy('id')->get();

        // A line of another revision cannot be named as the source, even with this revision's own scope/family written beside it.
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, lines: fn (array $rows) => [['source_line_id' => $foreign->id] + $rows[0]] + $rows), 'quotation_issued_lines_source_fk');
        // Nor can the source's own line carry another scope than the one it belongs to.
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, lines: fn (array $rows) => [['logical_scope_id' => $mine[1]->logical_scope_id] + $rows[0]] + $rows), 'quotation_issued_lines_source_fk');
        // Nor can the frozen line claim another family than the issue it belongs to.
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, lines: fn (array $rows) => [['family_id' => $other->family_id] + $rows[0]] + $rows), 'quotation_issued_lines_');
    }

    public function test_every_cross_company_reference_is_refused(): void
    {
        $revision = $this->draft();
        $company = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $outsider = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertDbRefuses(fn () => $this->writeIssue($revision, ['issued_by' => $outsider]), 'quotation_issues_issuer_fk');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, ['revision_created_by' => $outsider]), 'quotation_issues_maker_fk');
        $this->assertDbRefuses(fn () => $this->writeIssue($revision, ['company_id' => $company]), 'quotation_issues_');
    }

    public function test_the_rollback_refuses_once_a_quotation_has_been_issued(): void
    {
        $this->writeIssue($this->draft());
        $migration = require base_path('database/migrations/2026_10_29_120000_create_quotation_issue_foundation.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('would destroy the official record');
        $migration->down();
    }
}
