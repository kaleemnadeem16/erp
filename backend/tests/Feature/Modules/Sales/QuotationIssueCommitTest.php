<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\QuotationDraftLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * QL3 — the real COMMIT and a second real session. A test transaction never commits, so the database's commit-time agreement (number, allocation, frozen lines, totals) is proven
 * here by an issue that really commits, and one number per revision is proven across two PostgreSQL connections.
 */
class QuotationIssueCommitTest extends TestCase
{
    use BuildsIssuableQuotations;
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // A schema downgrade deliberately refuses issued quotations; this isolated test database is disposable, so wipe and rebuild it.
            DB::setDefaultConnection(config('database.default'));
            DB::purge('session_b');
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.session_b' => config('database.connections.testing')]);
        $this->setUpIssuing();
    }

    public function test_an_issue_commits_for_real_and_a_second_session_cannot_issue_it_again_or_alter_it(): void
    {
        $revision = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;

        $first = app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'commit-key');

        $this->assertSame('ISSUED', DB::table('quotation_revisions')->where('id', $revision->id)->value('state'), 'committed');
        $this->assertSame(1, DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count());

        DB::setDefaultConnection('session_b');
        try {
            $b = DB::connection('session_b');
            $this->assertSame($first->officialNumber, $b->table('quotation_revisions')->where('id', $revision->id)->value('official_number'), 'the second session sees the committed number');
            try {
                app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'other-session-key');
                $this->fail('A second session must not issue an issued revision.');
            } catch (DomainInvariantException $refusal) {
                $this->assertSame('QUOTATION_ALREADY_ISSUED', $refusal->reasonCode);
            }
            $replay = app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'commit-key');
            $this->assertTrue($replay->replayed);
            $this->assertSame($first->officialNumber, $replay->officialNumber);
            foreach ([
                fn () => $b->table('quotation_revisions')->where('id', $revision->id)->update(['valid_through_local_date' => '2099-01-01']),
                fn () => $b->table('quotation_issues')->where('revision_id', $revision->id)->delete(),
                fn () => $b->table('quotation_issued_lines')->where('revision_id', $revision->id)->update(['net' => '1.0000']),
                fn () => $b->statement('TRUNCATE quotation_issues CASCADE'),
            ] as $attack) {
                try {
                    $attack();
                    $this->fail('An issued record was altered from a second session.');
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            DB::setDefaultConnection(config('database.default'));
        }

        $this->assertSame(1, DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count(), 'still exactly one number');
        $this->assertSame(1, DB::table('quotation_issues')->count());
    }

    public function test_the_commit_time_agreement_refuses_a_hand_made_half_issue_that_really_commits(): void
    {
        $revision = $this->preparedDraft();

        // The agreement is checked by the COMMIT itself, so the driver raises it rather than a query.
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('has no issue record');
        DB::transaction(function () use ($revision): void {
            $allocation = app(NumberingService::class)->allocate(new NumberingContext('QUOTATION', CarbonImmutable::now(), documentId: $revision->id));
            DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'ISSUED', 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId,
                'issued_by' => $this->checker->id, 'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()->addMonth()]);
        });
    }
    public function test_a_frozen_term_that_differs_from_its_draft_line_is_refused_by_the_real_commit_though_ids_and_totals_agree(): void
    {
        $revision = $this->preparedDraft();
        $lines = QuotationDraftLine::query()->where('revision_id', $revision->id)->orderBy('id')->get();

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('does not state what its draft line offered');
        DB::transaction(function () use ($revision, $lines): void {
            $allocation = app(NumberingService::class)->allocate(new NumberingContext('QUOTATION', CarbonImmutable::now(), documentId: $revision->id));
            $issueId = DB::table('quotation_issues')->insertGetId(['company_id' => 1, 'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'reviewed_generation' => $revision->input_generation,
                'revision_created_by' => $revision->created_by, 'issued_by' => $this->checker->id, 'issued_at' => now(), 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId,
                'policy_version' => 'ISSUE.1', 'unknown_payment_policy' => 'BLOCK', 'currency_id' => $revision->currency_id, 'pricing_date' => $revision->pricing_date->format('Y-m-d'), 'tax_estimate_date' => $revision->tax_estimate_date?->format('Y-m-d'),
                'valid_through_local_date' => $revision->valid_through_local_date->format('Y-m-d'), 'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()->addMonth(), 'net_total' => '200.0000',
                'optional_net_total' => '30.0000', 'controls_outcome' => 'PASS', 'financial_fingerprint' => str_repeat('c', 64)]);
            foreach ($lines as $i => $line) {
                $base = $line->membership === 'BASE';
                DB::table('quotation_issued_lines')->insert(['company_id' => 1, 'issue_id' => $issueId, 'revision_id' => $revision->id, 'family_id' => $revision->family_id, 'source_line_id' => $line->id,
                    'logical_scope_id' => $line->logical_scope_id, 'sort_order' => $line->sort_order, 'section_title' => null, 'description' => $i === 0 ? 'A different offer' : $line->description,
                    'membership' => $line->membership, 'choice_group_id' => null, 'is_baseline' => false, 'counts_in_total' => $base, 'source_kind' => $line->source_kind, 'item_id' => null,
                    'manual_measure' => $line->manual_measure, 'offered_quantity' => $line->offered_quantity, 'offered_uom_id' => null, 'unit_price' => $line->unit_price,
                    'price_currency_id' => $revision->currency_id, 'fx_fingerprint' => $line->fx_fingerprint, 'net' => $base ? '200.0000' : '30.0000']);
            }
            DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'ISSUED', 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId,
                'issued_by' => $this->checker->id, 'issued_timezone' => 'Asia/Karachi', 'expires_at' => now()->addMonth()]);
        });
    }
}
