<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
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
                'issued_by' => $this->checker->id, 'issued_timezone' => 'Asia/Karachi', 'expires_at' => $this->expiryOf($revision->valid_through_local_date->format('Y-m-d'))]);
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
                'valid_through_local_date' => $revision->valid_through_local_date->format('Y-m-d'), 'issued_timezone' => 'Asia/Karachi', 'expires_at' => $this->expiryOf($revision->valid_through_local_date->format('Y-m-d')), 'net_total' => '200.0000',
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
                'issued_by' => $this->checker->id, 'issued_timezone' => 'Asia/Karachi', 'expires_at' => $this->expiryOf($revision->valid_through_local_date->format('Y-m-d'))]);
        });
    }

    /**
     * Two genuinely separate OS processes (pcntl_fork, as the Jobs and Documents slices do) each run the COMPLETE IssueQuotation, released together, each on its own connection.
     *
     * @return array{child: array<string, mixed>, parent: array<string, mixed>}
     */
    private function race(Closure $childWork, Closure $parentWork): array
    {
        $resultPath = sys_get_temp_dir().'/quotation_issue_race_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/quotation_issue_race_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);
        $outcome = static function (Closure $work): array {
            try {
                $result = $work();

                return ['ok' => true, 'issue' => $result->issueId, 'number' => $result->officialNumber, 'replayed' => $result->replayed];
            } catch (DomainInvariantException $e) {
                return ['ok' => false, 'reason' => $e->reasonCode];
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => 'ERROR: '.$e->getMessage()];
            }
        };
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'unable to fork');
            if ($pid === 0) {
                config(['database.connections.issue_race_child' => config('database.connections.testing'), 'database.default' => 'issue_race_child']);
                for ($waited = 0; ! file_exists($startPath) && $waited < 3_000_000; $waited += 1000) {
                    usleep(1000);
                }
                file_put_contents($resultPath, json_encode($outcome($childWork)));
                posix_kill(getmypid(), SIGKILL);
            }
            $default = config('database.default');
            config(['database.connections.issue_race_parent' => config('database.connections.testing'), 'database.default' => 'issue_race_parent']);
            file_put_contents($startPath, '1');
            try {
                $parent = $outcome($parentWork);
            } finally {
                config(['database.default' => $default]);
                DB::purge('issue_race_parent');
            }
            pcntl_waitpid($pid, $status);
            $child = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($child, 'the forked child did not report an outcome');

            return ['child' => $child, 'parent' => $parent];
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
        }
    }

    public function test_two_processes_issuing_the_same_revision_produce_exactly_one_number_and_one_issue(): void
    {
        $revision = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;
        // The actor is loaded inside each process so that its relations use that process's own connection, not the one it was created on before the fork.
        $checkerId = $this->checker->id;
        $issue = fn (string $key) => fn () => app(IssueQuotation::class)(User::query()->findOrFail($checkerId), $revision->id, $generation, $key);

        $result = $this->race($issue('child-key'), $issue('parent-key'));

        $outcomes = [$result['child'], $result['parent']];
        $winners = array_values(array_filter($outcomes, fn (array $o): bool => $o['ok']));
        $losers = array_values(array_filter($outcomes, fn (array $o): bool => ! $o['ok']));
        $this->assertCount(1, $winners, json_encode($result));
        $this->assertSame('QUOTATION_ALREADY_ISSUED', $losers[0]['reason'] ?? null, 'the loser is refused cleanly, never an error: '.json_encode($result));
        $this->assertSame([1, 1, 1], [DB::table('quotation_issues')->count(), DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count(), DB::table('document_manifests')->count()]);
        $this->assertSame($winners[0]['number'], DB::table('quotation_revisions')->where('id', $revision->id)->value('official_number'));
    }

    public function test_two_processes_issuing_different_revisions_get_two_distinct_numbers_without_a_deadlock(): void
    {
        $first = $this->preparedDraft();
        $second = $this->preparedDraft();
        $checkerId = $this->checker->id;
        $issue = fn (QuotationRevision $r, string $key) => fn () => app(IssueQuotation::class)(User::query()->findOrFail($checkerId), $r->id, QuotationRevision::findOrFail($r->id)->input_generation, $key);

        $result = $this->race($issue($first, 'child-key'), $issue($second, 'parent-key'));

        $this->assertTrue($result['child']['ok'] && $result['parent']['ok'], json_encode($result));
        $numbers = [$result['child']['number'], $result['parent']['number']];
        $this->assertCount(2, array_unique($numbers), 'two different numbers');
        $this->assertSame(2, DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count());
        $this->assertSame(2, DB::table('quotation_issues')->count());
        sort($numbers);
        $this->assertSame(['QTN-00001', 'QTN-00002'], $numbers, 'consecutive');
    }

    public function test_issue_takes_the_tax_generation_fence_so_a_competing_tax_writer_blocks_it(): void
    {
        $revision = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;
        DB::table('tax_generations')->insertOrIgnore(['company_id' => 1, 'generation' => 0]);

        // A second real session holds Tax's generation row, as any evidence writer does until it commits.
        $other = DB::connection('session_b');
        $other->statement('BEGIN');
        $other->select('select * from tax_generations where company_id = 1 for update');
        DB::statement("SET lock_timeout = '300ms'");
        try {
            app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'fenced-key');
            $this->fail('Issue must wait on the Tax fence.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0], 'lock_not_available: the issue was held at the fence');
        } finally {
            $other->statement('ROLLBACK');
            DB::statement('RESET lock_timeout');
        }

        $this->assertSame([0, 'DRAFT'], [DB::table('quotation_issues')->count(), DB::table('quotation_revisions')->where('id', $revision->id)->value('state')]);
        // With the writer gone, the same request issues.
        $this->assertSame('QTN-00001', app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'fenced-key')->officialNumber);
    }

    public function test_issue_holds_the_financial_policy_fence_so_a_control_set_or_rounding_writer_cannot_slip_in(): void
    {
        $revision = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;

        // A policy writer (it takes the company row exclusively) holds it in a second real session: the issue waits at the fence and writes nothing.
        $other = DB::connection('session_b');
        $other->statement('BEGIN');
        $other->select('select id from companies where id = 1 for update');
        DB::statement("SET lock_timeout = '300ms'");
        try {
            app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'policy-key');
            $this->fail('Issue must wait on the financial policy fence.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0]);
        } finally {
            $other->statement('ROLLBACK');
            DB::statement('RESET lock_timeout');
        }
        $this->assertSame(0, DB::table('quotation_issues')->count());

        // And the other way: while an issue holds the fence (shared) a real control-set publication cannot commit underneath it.
        $other->statement('BEGIN');
        $other->select('select id from companies where id = 1 for share');
        DB::statement("SET lock_timeout = '300ms'");
        try {
            app(PublishQuotationControlSet::class)($this->admin(), [['ref' => 'c', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '1', 'currency_id' => $revision->currency_id]], 'Changed during issue');
            $this->fail('A control-set publication must wait for an issue in progress.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0]);
        } finally {
            $other->statement('ROLLBACK');
            DB::statement('RESET lock_timeout');
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        Permission::findOrCreate(PermissionVocabulary::FINANCIAL_CONFIGURE, 'web');
        $admin->givePermissionTo(PermissionVocabulary::FINANCIAL_CONFIGURE);
        $duties = array_map(fn (SalesDuty $d): SalesDuty => $d->name === PermissionVocabulary::FINANCIAL_CONFIGURE
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate) : $d,
            app(SalesDutyManifest::class)->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));

        return $admin;
    }

    /** Runs one statement on the second real session with a short lock timeout and reports whether the database made it wait (lock_not_available) or let it through. */
    private function otherSessionWaits(string $sql, array $bindings = []): bool
    {
        $other = DB::connection('session_b');
        $other->statement("SET lock_timeout = '300ms'");
        try {
            $other->statement($sql, $bindings);

            return false;
        } catch (QueryException $e) {
            return $e->errorInfo[0] === '55P03';
        } finally {
            $other->statement('RESET lock_timeout');
        }
    }

    public function test_a_revocation_or_an_ownership_change_cannot_land_while_an_issue_is_in_flight_and_a_replay_after_revocation_is_refused(): void
    {
        $revision = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;
        $permissionId = (int) DB::table('permissions')->where('name', PermissionVocabulary::QUOTATION_ISSUE)->value('id');
        $family = $revision->family_id;
        $checkerId = $this->checker->id;
        $inFlight = null;
        // The issue's own timing query runs after its fences are taken and its assessment is done, i.e. while it is in flight.
        DB::listen(function ($query) use (&$inFlight, $permissionId, $family, $checkerId): void {
            if ($inFlight === null && str_contains($query->sql, 'select now() as now')) {
                $inFlight = [
                    'revocation' => $this->otherSessionWaits('delete from model_has_permissions where model_id = ? and permission_id = ?', [$checkerId, $permissionId]),
                    'ownership' => $this->otherSessionWaits('update quotation_families set responsible_owner_id = responsible_owner_id where id = ?', [$family]),
                ];
            }
        });

        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, $generation, 'fence-key');

        $this->assertSame(['revocation' => true, 'ownership' => true], $inFlight, 'both writers were held at the company access fence while the issue was in flight');
        $this->assertSame('QTN-00001', $outcome->officialNumber);

        // Once the issue has committed the revocation goes through, and the issuer can no longer retrieve the protected replay result.
        $this->checker->revokePermissionTo(PermissionVocabulary::QUOTATION_ISSUE);
        $this->expectException(AuthorizationException::class);
        app(IssueQuotation::class)(User::query()->findOrFail($checkerId), $revision->id, $generation, 'fence-key');
    }
}
