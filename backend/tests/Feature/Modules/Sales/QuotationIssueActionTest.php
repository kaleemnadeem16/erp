<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationOperationReceipt;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\IssueBlocker;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;
use Tests\TestCase;

/**
 * QL3 — IssueQuotation: a prepared draft becomes an immutable, numbered, receipted issued revision, atomically and replay-safely, only for an entitled ACTIVE checker, only when the
 * very assessment the readiness Action runs comes back empty under the lock. The database's COMMIT-time agreement is forced with SET CONSTRAINTS ... IMMEDIATE.
 */
class QuotationIssueActionTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    private function issue(QuotationRevision $revision, ?int $generation = null, ?string $key = null, ?User $by = null)
    {
        return app(IssueQuotation::class)($by ?? $this->checker, $revision->id, $generation ?? QuotationRevision::findOrFail($revision->id)->input_generation, $key ?? $this->issueKey());
    }

    private function allocations(): int
    {
        return DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count();
    }

    private function refusalCode(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }

        return 'NO_REFUSAL';
    }

    public function test_a_prepared_draft_is_issued_with_one_number_a_frozen_record_and_a_whole_commit_time_agreement(): void
    {
        $revision = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;

        $outcome = $this->issue($revision, $generation);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $row = QuotationRevision::findOrFail($revision->id);
        $issue = QuotationIssue::query()->where('revision_id', $revision->id)->firstOrFail();
        $this->assertFalse($outcome->replayed);
        $this->assertSame('ISSUED', $row->state);
        $this->assertSame('QTN-00001', $outcome->officialNumber);
        $this->assertSame($outcome->officialNumber, $row->official_number);
        $this->assertSame($issue->id, $outcome->issueId);
        $this->assertSame([$this->maker->id, $this->checker->id], [$issue->revision_created_by, $issue->issued_by], 'the maker and the checker are both on the record');
        $this->assertSame($generation, $issue->reviewed_generation);
        $this->assertSame(['200.0000', '30.0000', '20.0000', '220.0000'], [(string) $issue->net_total, (string) $issue->optional_net_total, (string) $issue->tax_total, (string) $issue->gross_total]);
        $this->assertSame(['ISSUE.1', 'BLOCK', 'FR3.1', 7, 'PASS'], [$issue->policy_version, $issue->unknown_payment_policy, $issue->tax_calculation_version, $issue->tax_generation, $issue->controls_outcome]);
        $this->assertSame(64, strlen($issue->financial_fingerprint));
        $this->assertSame($row->issued_at->toIso8601String(), $issue->issued_at->toIso8601String(), 'one database instant for the revision and its record');
        $this->assertSame(Company::findOrFail(1)->timezone, $row->issued_timezone);
        $this->assertSame(now(Company::findOrFail(1)->timezone)->addMonth()->addDay()->startOfDay()->toIso8601String(), $row->expires_at->setTimezone(Company::findOrFail(1)->timezone)->toIso8601String(), 'expiry is the start of the day after valid-through in the frozen zone');
        $lines = QuotationIssuedLine::query()->where('issue_id', $issue->id)->orderBy('sort_order')->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([true, false], $lines->pluck('counts_in_total')->all());
        $this->assertSame(['200.0000', '30.0000'], $lines->pluck('net')->map(fn ($v): string => (string) $v)->all());
        $this->assertSame(['20.0000', '3.0000'], $lines->pluck('tax')->map(fn ($v): string => (string) $v)->all());
        $this->assertSame(['TAXABLE', 'TAXABLE'], $lines->pluck('tax_disposition')->all());
        $this->assertSame(1, $this->allocations());
        $this->assertSame($revision->id, (int) DB::table('numbering_allocations')->where('number', 'QTN-00001')->value('document_id'));
    }

    public function test_an_exact_retry_replays_the_original_number_and_instant_without_a_second_allocation(): void
    {
        $revision = $this->preparedDraft();
        $first = $this->issue($revision, key: 'same-key');

        $again = $this->issue($revision, key: 'same-key');

        $this->assertTrue($again->replayed);
        $this->assertSame([$first->officialNumber, $first->issuedAt, $first->issueId], [$again->officialNumber, $again->issuedAt, $again->issueId]);
        $this->assertSame(1, $this->allocations());
        $this->assertSame(1, QuotationIssue::query()->count());
    }

    public function test_a_different_key_on_an_issued_revision_never_allocates_a_second_number(): void
    {
        $revision = $this->preparedDraft();
        $this->issue($revision, key: 'one');

        $this->assertSame('QUOTATION_ALREADY_ISSUED', $this->refusalCode(fn () => $this->issue($revision, key: 'two')));
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusalCode(fn () => app(IssueQuotation::class)($this->checker, $revision->id, 999, 'one')));
        $this->assertSame(1, $this->allocations());
        $this->assertSame(1, QuotationIssue::query()->count());
    }

    public function test_a_draft_that_moved_since_it_was_reviewed_is_refused_and_nothing_is_written(): void
    {
        $revision = $this->preparedDraft();
        $reviewed = QuotationRevision::findOrFail($revision->id)->input_generation;
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $reviewed, $this->issueKey(), ['valid_through_local_date' => now()->addMonths(2)->format('Y-m-d')]);

        $this->assertSame('QUOTATION_DRAFT_STALE', $this->refusalCode(fn () => $this->issue($revision, $reviewed)));
        $this->assertSame('DRAFT', QuotationRevision::findOrFail($revision->id)->state);
        $this->assertSame([0, 0], [$this->allocations(), QuotationIssue::query()->count()]);
    }

    public function test_an_unready_draft_is_refused_with_its_blocker_codes_and_allocates_nothing(): void
    {
        $revision = $this->preparedDraft();
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider(blockers: [new IssueBlocker('TAX_NOT_DETERMINED', IssueBlocker::TAX)]));
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider(blockers: [new IssueBlocker('CONTROLS_NOT_CONFIGURED', IssueBlocker::CONTROLS)]));

        try {
            $this->issue($revision);
            $this->fail('An unready draft must not be issued.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('QUOTATION_NOT_ISSUABLE', $refusal->reasonCode);
            $this->assertStringContainsString('TAX_NOT_DETERMINED', $refusal->getMessage());
            $this->assertStringContainsString('CONTROLS_NOT_CONFIGURED', $refusal->getMessage());
            $this->assertStringNotContainsString('200', $refusal->getMessage(), 'a refusal carries codes, never figures');
        }
        $this->assertSame([0, 0, 'DRAFT'], [$this->allocations(), QuotationIssue::query()->count(), QuotationRevision::findOrFail($revision->id)->state]);
    }

    public function test_the_shipped_default_providers_make_issue_impossible(): void
    {
        $revision = $this->preparedDraft();
        $this->app->forgetInstance(QuotationTaxProvider::class);
        $this->app->forgetInstance(QuotationControlsProvider::class);

        try {
            $this->issue($revision);
            $this->fail('Without a real tax and controls provider nothing can be issued.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('QUOTATION_NOT_ISSUABLE', $refusal->reasonCode);
            $this->assertStringContainsString('TAX_NOT_DETERMINED', $refusal->getMessage());
            $this->assertStringContainsString('CONTROLS_NOT_CONFIGURED', $refusal->getMessage());
        }
        $this->assertSame(0, $this->allocations());
    }

    public function test_an_expired_validity_a_missing_price_and_a_cancelled_draft_are_refused(): void
    {
        $expired = $this->preparedDraft(now()->subDays(2)->format('Y-m-d'));
        $this->assertSame('QUOTATION_NOT_ISSUABLE', $this->refusalCode(fn () => $this->issue($expired)));

        $cancelled = $this->preparedDraft();
        DB::table('quotation_revisions')->where('id', $cancelled->id)->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->maker->id, 'cancellation_reason' => 'Withdrawn']);
        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusalCode(fn () => $this->issue($cancelled, 1)));
        $this->assertSame('QUOTATION_NOT_FOUND', $this->refusalCode(fn () => app(IssueQuotation::class)($this->checker, 999999, 1, $this->issueKey())));
        $this->assertSame(0, $this->allocations());
    }

    /** @return array<string, array{0: string}> */
    public static function refusedCheckers(): array
    {
        return ['nobody' => ['nobody'], 'the maker, who holds prepare only' => ['maker'], 'view only' => ['view-only'], 'issue without an independent view' => ['issue-only'],
            'issue limited to my units' => ['limited'], 'suspended' => ['suspended'], 'invited' => ['invited'], 'stale hydrated, suspended since' => ['stale']];
    }

    #[DataProvider('refusedCheckers')]
    public function test_only_an_active_member_holding_issue_and_view_may_issue(string $kind): void
    {
        $revision = $this->preparedDraft();
        $actor = match ($kind) {
            'nobody' => User::factory()->create(),
            'maker' => $this->maker,
            'view-only' => tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo(PermissionVocabulary::QUOTATION_VIEW)),
            'issue-only' => tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo(PermissionVocabulary::QUOTATION_ISSUE)),
            'limited' => tap(User::factory()->create(), function (User $u): void {
                $u->givePermissionTo([PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW]);
                AccessScopeLimit::create(['user_id' => $u->id, 'permission' => PermissionVocabulary::QUOTATION_ISSUE, 'scope' => 'MY_UNITS']);
            }),
            'suspended', 'invited' => tap(User::factory()->create(), function (User $u) use ($kind): void {
                $u->givePermissionTo([PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW]);
                DB::table('users')->where('id', $u->id)->update(['status' => $kind === 'suspended' ? MemberStatus::Suspended->value : MemberStatus::Invited->value]);
            })->fresh(),
            'stale' => (function (): User {
                $u = User::factory()->create();
                $u->givePermissionTo([PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW]);
                $hydrated = User::query()->findOrFail($u->id);
                DB::table('users')->where('id', $u->id)->update(['status' => MemberStatus::Suspended->value]);

                return $hydrated;
            })(),
        };

        try {
            $this->issue($revision, by: $actor);
            $this->fail("'{$kind}' must be refused.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([0, 0, 'DRAFT'], [$this->allocations(), QuotationIssue::query()->count(), QuotationRevision::findOrFail($revision->id)->state]);
    }

    public function test_it_is_refused_while_the_duty_is_staged_and_a_revoked_checker_cannot_replay(): void
    {
        $revision = $this->preparedDraft();
        $this->issue($revision, key: 'k');
        DB::table('users')->where('id', $this->checker->id)->update(['status' => MemberStatus::Suspended->value]);

        $this->expectException(AuthorizationException::class);
        try {
            $this->issue($revision, key: 'k', by: $this->checker->fresh());
        } finally {
            $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        }
    }

    public function test_a_staged_duty_refuses_every_caller(): void
    {
        $revision = $this->preparedDraft();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());

        $this->expectException(AuthorizationException::class);
        $this->issue($revision);
    }

    public function test_one_person_holding_both_duties_may_issue_their_own_draft_and_it_is_on_the_record(): void
    {
        $both = User::factory()->create();
        $both->givePermissionTo([PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW]);
        $created = app(CreateQuotationDraft::class)($both, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        app(UpdateQuotationDraft::class)($both, $created->revisionId, $created->inputGeneration, $this->issueKey(), ['add_lines' => [['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '10.0000']]]);
        $revision = QuotationRevision::findOrFail($created->revisionId);

        $outcome = $this->issue($revision, by: $both);

        $issue = QuotationIssue::query()->findOrFail($outcome->issueId);
        $this->assertSame([$both->id, $both->id], [$issue->revision_created_by, $issue->issued_by], 'separation is by duty; the record shows when maker and checker are one person (open owner decision D8)');
    }

    public function test_a_failure_while_writing_leaves_no_half_issue(): void
    {
        $revision = $this->preparedDraft();
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into "quotation_issued_lines"')) {
                throw new \RuntimeException('injected failure after the number and the issue row');
            }
        });

        try {
            $this->issue($revision);
            $this->fail('The injected failure must surface.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(['DRAFT', 0, 0, 0], [QuotationRevision::findOrFail($revision->id)->state, QuotationIssue::query()->count(), QuotationIssuedLine::query()->count(), $this->allocations()]);
        $this->assertSame(0, QuotationOperationReceipt::query()->where('operation', 'quotation.issue')->count(), 'no receipt either');
    }

    public function test_an_issued_revision_can_no_longer_be_edited_assessed_or_issued_again(): void
    {
        $revision = $this->preparedDraft();
        $this->issue($revision);
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;

        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusalCode(fn () => app(UpdateQuotationDraft::class)($this->maker, $revision->id, $generation, $this->issueKey(), ['valid_through_local_date' => '2099-01-01'])));
        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusalCode(fn () => app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id)));
    }

    public function test_the_outcome_and_the_receipt_carry_no_figure(): void
    {
        $revision = $this->preparedDraft();
        $outcome = $this->issue($revision);

        $this->assertEqualsCanonicalizing(['v', 'family_id', 'revision_id', 'issue_id', 'official_number', 'issued_at'], array_keys(QuotationOperationReceipt::query()->where('operation', 'quotation.issue')->firstOrFail()->result));
        $this->assertSame(['familyId', 'revisionId', 'issueId', 'officialNumber', 'issuedAt', 'replayed'], array_keys(get_object_vars($outcome)));
    }
}
