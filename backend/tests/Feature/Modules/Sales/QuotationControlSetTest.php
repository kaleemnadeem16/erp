<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\ConfiguredQuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * D3 — the company's published, versioned, immutable control set and the real controls provider over it: nothing configured, empty of mandatory rules, failing, unevaluable (any cost
 * dependent rule, because no cost evidence exists for these lines) or stale against the rounding policy blocks issue; a satisfied set passes and is named on the issue.
 */
class QuotationControlSetTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $admin;

    private int $pkr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->app->forgetInstance(QuotationControlsProvider::class);
        $this->app->bind(QuotationControlsProvider::class, ConfiguredQuotationControlsProvider::class);
        Permission::findOrCreate(PermissionVocabulary::FINANCIAL_CONFIGURE, 'web');
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(PermissionVocabulary::FINANCIAL_CONFIGURE);
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::FINANCIAL_CONFIGURE], true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
        $this->pkr = (int) Currency::query()->where('code', 'PKR')->value('id');
    }

    /** @return list<array<string, mixed>> */
    private function floor(string $limit, string $mode = 'MANDATORY', string $ref = 'ctl-a'): array
    {
        return [['ref' => $ref, 'kind' => 'FLOOR', 'mode' => $mode, 'limit' => $limit, 'currency_id' => $this->pkr]];
    }

    private function publish(array $controls, ?User $by = null): QuotationControlSet
    {
        return app(PublishQuotationControlSet::class)($by ?? $this->admin, $controls, 'Quarterly review');
    }

    /** @return list<string> */
    private function codes(QuotationRevision $revision): array
    {
        return array_map(fn (array $b): string => $b['code'].($b['line_id'] === null ? '' : '@'.$b['line_id']), app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id)['blockers']);
    }

    public function test_with_no_published_set_issue_is_blocked_not_passed(): void
    {
        $this->assertSame(['CONTROLS_NOT_CONFIGURED'], $this->codes($this->preparedDraft()));
    }

    public function test_a_set_is_published_versioned_and_only_by_an_active_holder_of_the_configure_duty(): void
    {
        $first = $this->publish($this->floor('50'));
        $second = $this->publish($this->floor('60'));

        $this->assertSame([1, 2], [$first->version_no, $second->version_no]);
        $this->assertSame(64, strlen($first->rounding_policy_fingerprint));
        $this->assertSame('60.0000', (string) $second->controls()->sole()->limit_value);
        foreach (['an unentitled user' => $this->maker, 'the checker' => $this->checker] as $name => $by) {
            try {
                $this->publish($this->floor('1'), $by);
                $this->fail("{$name} must not publish.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('users')->where('id', $this->admin->id)->update(['status' => 'SUSPENDED']);
        $this->expectException(AuthorizationException::class);
        $this->publish($this->floor('1'), $this->admin->fresh());
    }

    public function test_malformed_empty_or_unsupported_controls_are_refused_and_nothing_is_saved(): void
    {
        $bad = [
            'empty' => [],
            'unsupported kind' => [['ref' => 'r', 'kind' => 'INPUT_REDUCTION_PERCENT', 'mode' => 'MANDATORY', 'limit' => '5']],
            'amount without currency' => [['ref' => 'r', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '5']],
            'percent with currency' => [['ref' => 'r', 'kind' => 'MIN_MARGIN_PERCENT', 'mode' => 'MANDATORY', 'limit' => '5', 'currency_id' => $this->pkr]],
            'percent over 100' => [['ref' => 'r', 'kind' => 'MIN_MARGIN_PERCENT', 'mode' => 'MANDATORY', 'limit' => '101']],
            'negative limit' => [['ref' => 'r', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '-1', 'currency_id' => $this->pkr]],
            'duplicate reference' => [...$this->floor('1'), ...$this->floor('2')],
            'no currency row' => [['ref' => 'r', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '1', 'currency_id' => 999999]],
        ];
        foreach ($bad as $name => $controls) {
            try {
                $this->publish($controls);
                $this->fail("{$name} must be refused.");
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, QuotationControlSet::query()->count());
    }

    public function test_a_published_set_is_append_only_and_controls_cannot_be_added_later(): void
    {
        $set = $this->publish($this->floor('50'));
        $refuses = function (string $sql, array $bindings = []): void {
            try {
                DB::transaction(fn () => DB::statement($sql, $bindings));
                $this->fail("Expected the database to refuse: {$sql}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $refuses('UPDATE quotation_control_sets SET reason = ? WHERE id = ?', ['x', $set->id]);
        $refuses('DELETE FROM quotation_control_sets WHERE id = ?', [$set->id]);
        $refuses('UPDATE quotation_net_controls SET limit_value = 0 WHERE control_set_id = ?', [$set->id]);
        $refuses('DELETE FROM quotation_net_controls WHERE control_set_id = ?', [$set->id]);
        $refuses('TRUNCATE quotation_net_controls');
        $refuses('TRUNCATE quotation_control_sets CASCADE');
        // A control cannot join a set published in an earlier transaction (here: a set whose publication instant is in the past).
        $old = DB::table('quotation_control_sets')->insertGetId(['company_id' => 1, 'version_no' => 9, 'reason' => 'earlier', 'published_by' => $this->admin->id, 'published_at' => now()->subDay()]);
        $refuses("INSERT INTO quotation_net_controls (company_id, control_set_id, control_ref, control_version, kind, mode, limit_value, currency_id, basis) VALUES (1, {$old}, 'late', 1, 'FLOOR', 'MANDATORY', 1, {$this->pkr}, 'LINE')");
    }

    public function test_a_satisfied_set_passes_and_the_issue_names_the_set_that_judged_it(): void
    {
        $revision = $this->preparedDraft();
        $this->publish($this->floor('30'));

        $this->assertSame([], $this->codes($revision));
        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
        $issue = QuotationIssue::query()->findOrFail($outcome->issueId);
        $this->assertSame('PASS', $issue->controls_outcome);
        $this->assertSame(64, strlen($issue->controls_fingerprint));

        // The same draft judged against a different published version carries a different fingerprint.
        $other = $this->preparedDraft();
        $this->publish($this->floor('29', ref: 'ctl-z'));
        $second = QuotationIssue::query()->findOrFail(app(IssueQuotation::class)($this->checker, $other->id, QuotationRevision::findOrFail($other->id)->input_generation, $this->issueKey())->issueId);
        $this->assertNotSame($issue->controls_fingerprint, $second->controls_fingerprint);
    }

    public function test_a_mandatory_floor_that_is_not_met_blocks_by_line_and_an_advisory_one_only_warns(): void
    {
        $revision = $this->preparedDraft();
        $lineIds = $revision->lines()->orderBy('id')->pluck('id')->all();

        $this->publish($this->floor('250'));
        $this->assertEqualsCanonicalizing(["CONTROL_FAILED@{$lineIds[0]}", "CONTROL_FAILED@{$lineIds[1]}"], $this->codes($revision));

        $this->publish($this->floor('250', 'ADVISORY'));
        $this->assertSame(['CONTROLS_NO_MANDATORY_CONTROL'], $this->codes($revision), 'advisory rules alone are not an issue gate');

        $this->publish([...$this->floor('1'), ...$this->floor('250', 'ADVISORY', 'ctl-b')]);
        $this->assertSame([], $this->codes($revision), 'a mandatory rule that is met and an advisory one that is not: issue may proceed');
        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
        $this->assertSame('WARN', QuotationIssue::query()->findOrFail($outcome->issueId)->controls_outcome);
    }

    public function test_a_cost_dependent_mandatory_control_cannot_be_evaluated_here_so_it_blocks(): void
    {
        $revision = $this->preparedDraft();
        $this->publish([['ref' => 'm', 'kind' => 'MIN_MARGIN_PERCENT', 'mode' => 'MANDATORY', 'limit' => '10']]);

        $codes = $this->codes($revision);

        $this->assertCount(2, array_filter($codes, fn ($c) => str_starts_with($c, 'CONTROL_UNEVALUABLE@')), 'no cost evidence exists for manual lines; never a pass, never zero cost');
        $this->assertSame([], array_filter($codes, fn ($c) => str_contains($c, 'margin') || preg_match('/\d{3,}\.\d/', $c)), 'codes only');
    }

    public function test_a_rounding_policy_the_set_was_not_written_for_makes_every_control_unevaluable(): void
    {
        $revision = $this->preparedDraft();
        $this->publish($this->floor('1'));
        $this->assertSame([], $this->codes($revision));

        // A set pinned to another policy (published when a different rounding fingerprint was in force) cannot judge today's nets.
        DB::statement('ALTER TABLE quotation_control_sets DISABLE TRIGGER quotation_control_sets_guard');
        DB::table('quotation_control_sets')->update(['rounding_policy_fingerprint' => str_repeat('0', 64)]);
        DB::statement('ALTER TABLE quotation_control_sets ENABLE TRIGGER quotation_control_sets_guard');

        $this->assertCount(2, array_filter($this->codes($revision), fn ($c) => str_starts_with($c, 'CONTROL_UNEVALUABLE@')));
    }
}
