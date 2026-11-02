<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Sales\Actions\CancelQuotationDraft;
use App\Modules\Sales\Actions\CreateQuotationRevision;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\IssueQuotationReplacement;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Actions\WithdrawQuotationOffer;
use App\Modules\Sales\Models\QuotationDisposition;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\NoAcceptanceYet;
use App\Modules\Sales\Support\Quotation\QuotationAcceptanceState;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * QL4/QL5 — cancelling a draft, withdrawing an issued offer, starting a successor draft and replacing the predecessor as a whole: each leaves the issued record exactly as issued,
 * appends one disposition, and is atomic.
 */
class QuotationLifecycleTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        Permission::findOrCreate(PermissionVocabulary::QUOTATION_WITHDRAW, 'web');
        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo([PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_WITHDRAW, PermissionVocabulary::QUOTATION_VIEW]);
        $names = [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_WITHDRAW];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $names, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate) : $d, SalesDutyManifest::shipped()->all())));
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $e) {
            return (string) $e->reasonCode;
        }

        return 'NO_REFUSAL';
    }

    private function issued(?User $by = null): QuotationRevision
    {
        $draft = $this->preparedDraft();
        app(IssueQuotation::class)($by ?? $this->manager, $draft->id, QuotationRevision::findOrFail($draft->id)->input_generation, $this->issueKey());

        return QuotationRevision::findOrFail($draft->id);
    }

    private function availability(QuotationRevision $r): string
    {
        return QuotationAvailability::of(QuotationRevision::findOrFail($r->id));
    }

    /** A successor draft, priced and dated again as the seller must. */
    private function pricedSuccessor(QuotationRevision $predecessor): QuotationRevision
    {
        $created = app(CreateQuotationRevision::class)($this->maker, $predecessor->id, $this->issueKey());
        $lines = QuotationDraftLine::query()->where('revision_id', $created->revisionId)->orderBy('id')->get();
        app(UpdateQuotationDraft::class)($this->maker, $created->revisionId, $created->inputGeneration, $this->issueKey(), [
            'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(), 'valid_through_local_date' => now()->addMonths(2)->format('Y-m-d'),
            'update_lines' => [['id' => $lines[0]->id, 'unit_price' => '110.0000'], ['id' => $lines[1]->id, 'unit_price' => '30.0000']],
        ]);

        return QuotationRevision::findOrFail($created->revisionId);
    }

    public function test_a_draft_is_cancelled_with_a_reason_and_only_a_draft(): void
    {
        $draft = $this->preparedDraft();
        $generation = $draft->input_generation;

        $this->assertSame('QUOTATION_DRAFT_STALE', $this->refusal(fn () => app(CancelQuotationDraft::class)($this->maker, $draft->id, $generation + 5, 'c0', 'No longer needed')));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(CancelQuotationDraft::class)($this->maker, $draft->id, QuotationRevision::findOrFail($draft->id)->input_generation, 'c1', '  ')));
        $generation = QuotationRevision::findOrFail($draft->id)->input_generation;
        $first = app(CancelQuotationDraft::class)($this->maker, $draft->id, $generation, 'c2', 'No longer needed');
        $again = app(CancelQuotationDraft::class)($this->maker, $draft->id, $generation, 'c2', 'No longer needed');

        $this->assertTrue($again->replayed);
        $this->assertSame($first->revisionId, $again->revisionId);
        $cancelled = QuotationRevision::findOrFail($draft->id);
        $this->assertSame(['CANCELLED_DRAFT', 'No longer needed', $this->maker->id], [$cancelled->state, $cancelled->cancellation_reason, $cancelled->cancelled_by]);
        $this->assertSame(QuotationAvailability::CANCELLED_DRAFT, QuotationAvailability::of($cancelled));
        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusal(fn () => app(CancelQuotationDraft::class)($this->maker, $draft->id, $generation, 'c3', 'again')));
        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusal(fn () => app(CancelQuotationDraft::class)($this->maker, $this->issued()->id, 1, 'c4', 'issued is not a draft')));
        $this->expectException(AuthorizationException::class);
        app(CancelQuotationDraft::class)($this->checker, $draft->id, $generation, 'c5', 'not a preparer');
    }

    public function test_a_successor_draft_copies_the_offered_scope_but_nothing_that_was_true_at_issue(): void
    {
        $issued = $this->issued();
        $created = app(CreateQuotationRevision::class)($this->maker, $issued->id, 'r1');

        $successor = QuotationRevision::findOrFail($created->revisionId);
        $this->assertSame([$issued->family_id, $issued->id, 'DRAFT', 2], [$successor->family_id, $successor->predecessor_revision_id, $successor->state, $successor->revision_ordinal]);
        $this->assertNull($successor->valid_through_local_date, 'dates are not carried over');
        $this->assertNull($successor->official_number);
        $old = QuotationDraftLine::query()->where('revision_id', $issued->id)->orderBy('id')->get();
        $new = QuotationDraftLine::query()->where('revision_id', $successor->id)->orderBy('id')->get();
        $this->assertCount(2, $new);
        foreach ($new as $i => $line) {
            $this->assertSame([$old[$i]->logical_scope_id, (string) $old[$i]->offered_quantity, $old[$i]->description, $old[$i]->membership], [$line->logical_scope_id, (string) $line->offered_quantity, $line->description, $line->membership]);
            $this->assertNull($line->unit_price, 'no entered price or FX evidence is carried');
            $this->assertNull($line->fx_fingerprint);
        }
        $this->assertSame(QuotationAvailability::AVAILABLE, $this->availability($issued), 'the predecessor stays available');
        $this->assertTrue(app(CreateQuotationRevision::class)($this->maker, $issued->id, 'r1')->replayed);
        $this->assertSame('QUOTATION_SUCCESSOR_EXISTS', $this->refusal(fn () => app(CreateQuotationRevision::class)($this->maker, $issued->id, 'r2')));
        $this->assertSame('QUOTATION_PREDECESSOR_INVALID', $this->refusal(fn () => app(CreateQuotationRevision::class)($this->maker, $successor->id, 'r3')), 'a draft is not a predecessor');

        // Cancelling the successor leaves the original exactly as available, and a new successor may follow.
        app(CancelQuotationDraft::class)($this->maker, $successor->id, $successor->input_generation, 'r4', 'Customer changed their mind');
        $this->assertSame(QuotationAvailability::AVAILABLE, $this->availability($issued));
        $this->assertSame(3, QuotationRevision::findOrFail(app(CreateQuotationRevision::class)($this->maker, $issued->id, 'r5')->revisionId)->revision_ordinal);
    }

    public function test_the_database_refuses_a_successor_of_a_draft_or_of_another_family_and_a_second_open_successor(): void
    {
        $issued = $this->issued();
        $other = $this->issued();
        $insert = fn (array $over) => DB::table('quotation_revisions')->insert($over + ['company_id' => 1, 'family_id' => $issued->family_id, 'state' => 'DRAFT', 'currency_id' => $issued->currency_id,
            'created_by' => $this->maker->id, 'created_at' => now(), 'updated_at' => now()]);
        $refuses = function (callable $call): void {
            try {
                DB::transaction($call);
                $this->fail('Expected the database to refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $refuses(fn () => $insert(['predecessor_revision_id' => $other->id]));
        $draft = $this->preparedDraft();
        $refuses(fn () => $insert(['family_id' => $draft->family_id, 'predecessor_revision_id' => $draft->id]));
        $insert(['predecessor_revision_id' => $issued->id]);
        $refuses(fn () => $insert(['predecessor_revision_id' => $issued->id]));
    }

    public function test_withdrawing_appends_one_disposition_and_edits_nothing_else(): void
    {
        $issued = $this->issued();
        $issue = QuotationIssue::query()->where('revision_id', $issued->id)->sole();
        $before = [$issued->expires_at->toIso8601String(), $issue->financial_fingerprint, $issued->official_number];

        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'w0', '')));
        $first = app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'w1', 'Priced in error');
        $again = app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'w1', 'Priced in error');

        $this->assertTrue($again['replayed']);
        $this->assertSame(QuotationAvailability::WITHDRAWN, $this->availability($issued));
        $disposition = QuotationDisposition::query()->sole();
        $this->assertSame([$issued->id, 'WITHDRAWN', 'Priced in error', $this->manager->id, null], [$disposition->revision_id, $disposition->kind, $disposition->reason, $disposition->actor_id, $disposition->replacement_revision_id]);
        $again = QuotationRevision::findOrFail($issued->id);
        $this->assertSame($before, [$again->expires_at->toIso8601String(), QuotationIssue::query()->where('revision_id', $issued->id)->value('financial_fingerprint'), $again->official_number]);
        $this->assertSame('QUOTATION_NOT_AVAILABLE', $this->refusal(fn () => app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'w2', 'twice')));
        $this->assertSame($first['revision_id'], $issued->id);
    }

    public function test_only_an_issued_available_unaccepted_offer_can_be_withdrawn_and_only_by_an_entitled_active_member(): void
    {
        $draft = $this->preparedDraft();
        $this->assertSame('QUOTATION_NOT_AVAILABLE', $this->refusal(fn () => app(WithdrawQuotationOffer::class)($this->manager, $draft->id, 'a1', 'a draft is not an offer')));

        $issued = $this->issued();
        $this->app->instance(QuotationAcceptanceState::class, new class implements QuotationAcceptanceState
        {
            public function stands(QuotationRevision $revision): bool
            {
                return true;
            }
        });
        $this->assertSame('QUOTATION_NOT_AVAILABLE', $this->refusal(fn () => app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'a2', 'a decision stands on it')));
        $this->assertSame(0, QuotationDisposition::query()->count());
        $this->app->bind(QuotationAcceptanceState::class, NoAcceptanceYet::class);

        foreach (['the checker without withdraw' => $this->checker, 'the preparer' => $this->maker] as $name => $by) {
            try {
                app(WithdrawQuotationOffer::class)($by, $issued->id, 'a3-'.$by->id, 'not entitled');
                $this->fail("{$name} must be refused.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('users')->where('id', $this->manager->id)->update(['status' => MemberStatus::Suspended->value]);
        $this->expectException(AuthorizationException::class);
        app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'a4', 'suspended');
    }

    public function test_an_offer_past_its_expiry_instant_is_expired_without_a_scheduler_and_cannot_be_withdrawn(): void
    {
        $issued = $this->issued();
        $this->assertSame(QuotationAvailability::AVAILABLE, QuotationAvailability::of($issued));
        $lapsed = clone $issued;
        $lapsed->expires_at = now()->subMinute();

        $this->assertSame(QuotationAvailability::EXPIRED, QuotationAvailability::of($lapsed));
        $this->assertFalse(QuotationAvailability::isOffer($lapsed));
    }

    public function test_a_whole_replacement_issues_the_successor_and_removes_only_the_predecessors_availability(): void
    {
        $issued = $this->issued();
        $successor = $this->pricedSuccessor($issued);
        $generation = $successor->input_generation;

        $outcome = app(IssueQuotationReplacement::class)($this->manager, $issued->id, $successor->id, $generation, 'p1', 'Revised pricing');
        $replay = app(IssueQuotationReplacement::class)($this->manager, $issued->id, $successor->id, $generation, 'p1', 'Revised pricing');

        $this->assertTrue($replay->replayed);
        $this->assertSame($outcome->officialNumber, $replay->officialNumber);
        $this->assertSame([QuotationAvailability::REPLACED, QuotationAvailability::AVAILABLE], [$this->availability($issued), $this->availability($successor)]);
        $this->assertNotSame($issued->official_number, $outcome->officialNumber);
        $disposition = QuotationDisposition::query()->sole();
        $this->assertSame([$issued->id, 'REPLACED', $successor->id], [$disposition->revision_id, $disposition->kind, $disposition->replacement_revision_id]);
        $this->assertSame(2, QuotationIssue::query()->count(), 'both issues are retained');
        $this->assertSame(2, DocumentManifest::query()->where('purpose', DocumentManifest::ORIGINAL)->count());
        $this->assertSame($issued->official_number, QuotationRevision::findOrFail($issued->id)->official_number);
        $this->assertSame('QUOTATION_NOT_AVAILABLE', $this->refusal(fn () => app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'p2', 'already replaced')));
        $this->assertSame('QUOTATION_PREDECESSOR_INVALID', $this->refusal(fn () => app(CreateQuotationRevision::class)($this->maker, $issued->id, 'p3')), 'a replaced revision is not revised again');
    }

    public function test_a_failing_replacement_leaves_the_predecessor_available_and_issues_nothing(): void
    {
        $issued = $this->issued();
        $successor = $this->pricedSuccessor($issued);
        $allocations = DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count();
        DB::unprepared("CREATE FUNCTION test_refuse_disposition() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'store down' USING ERRCODE = '23514'; END \$\$;
            CREATE TRIGGER test_refuse_disposition BEFORE INSERT ON quotation_dispositions FOR EACH ROW EXECUTE FUNCTION test_refuse_disposition();");

        try {
            app(IssueQuotationReplacement::class)($this->manager, $issued->id, $successor->id, $successor->input_generation, 'f1', 'Revised pricing');
            $this->fail('The replacement must fail with its disposition.');
        } catch (QueryException) {
            $this->assertSame([QuotationAvailability::AVAILABLE, 'DRAFT', 1, $allocations], [$this->availability($issued), QuotationRevision::findOrFail($successor->id)->state,
                QuotationIssue::query()->count(), DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count()]);
        }
    }

    public function test_a_replacement_needs_the_right_predecessor_available_and_all_three_duties(): void
    {
        $issued = $this->issued();
        $unrelated = $this->issued();
        $successor = $this->pricedSuccessor($issued);
        $replace = fn (int $predecessor, ?User $by = null) => fn () => app(IssueQuotationReplacement::class)($by ?? $this->manager, $predecessor, $successor->id, $successor->input_generation, 'k'.$predecessor.($by?->id ?? ''), 'Revised pricing');

        $this->assertSame('QUOTATION_PREDECESSOR_INVALID', $this->refusal($replace($unrelated->id)));
        app(WithdrawQuotationOffer::class)($this->manager, $issued->id, 'k0', 'Withdrawn first');
        $this->assertSame('QUOTATION_NOT_AVAILABLE', $this->refusal($replace($issued->id)), 'a withdrawn predecessor cannot be replaced');
        $this->assertSame(0, QuotationDisposition::query()->where('kind', 'REPLACED')->count());
        foreach (['the checker without withdraw' => $this->checker, 'the preparer' => $this->maker] as $name => $by) {
            try {
                $replace($issued->id, $by)();
                $this->fail("{$name} must be refused.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_disposition_table_is_append_only_and_refuses_what_is_not_an_issued_revision(): void
    {
        $issued = $this->issued();
        $draft = $this->preparedDraft();
        $row = fn (array $over) => $over + ['company_id' => 1, 'family_id' => $issued->family_id, 'revision_id' => $issued->id, 'kind' => 'WITHDRAWN', 'reason' => 'x', 'actor_id' => $this->manager->id];
        $refuses = function (callable $call): void {
            try {
                DB::transaction($call);
                $this->fail('Expected the database to refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $refuses(fn () => DB::table('quotation_dispositions')->insert($row(['family_id' => $draft->family_id, 'revision_id' => $draft->id])));
        $refuses(fn () => DB::table('quotation_dispositions')->insert($row(['reason' => '  '])));
        $refuses(fn () => DB::table('quotation_dispositions')->insert($row(['kind' => 'REPLACED', 'replacement_revision_id' => $draft->id])));
        $refuses(fn () => DB::table('quotation_dispositions')->insert($row(['kind' => 'REPLACED', 'replacement_revision_id' => $issued->id])));
        $refuses(fn () => DB::table('quotation_dispositions')->insert($row(['kind' => 'WITHDRAWN', 'replacement_revision_id' => $draft->id])));
        DB::table('quotation_dispositions')->insert($row([]));
        $refuses(fn () => DB::table('quotation_dispositions')->insert($row([])));
        $refuses(fn () => DB::table('quotation_dispositions')->update(['reason' => 'edited']));
        $refuses(fn () => DB::table('quotation_dispositions')->delete());
        $refuses(fn () => DB::statement('TRUNCATE quotation_dispositions'));
    }
}
