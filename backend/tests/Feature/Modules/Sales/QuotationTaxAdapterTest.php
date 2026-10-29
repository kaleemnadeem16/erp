<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxFacts;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\UnavailableQuotationTaxFacts;
use App\Modules\Tax\Actions\DetermineQuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxNature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Feature\Modules\Tax\Concerns\BuildsQuotationTax;
use Tests\Support\Sales\FakeQuotationTaxFacts;
use Tests\TestCase;

/**
 * QL3 + FR3 — the Sales adapter over the Tax module's real determination and calculation, with genuine activated declarations built through Tax's own Actions: a determined and
 * calculated tax is issued and frozen with its Tax generation; everything Tax cannot establish is a blocker; and the Sales context authorizer admits only a context Sales would
 * build itself. The jurisdiction / classification source is a test double (the real source is not delivered yet).
 */
class QuotationTaxAdapterTest extends TestCase
{
    use BuildsIssuableQuotations;
    use BuildsQuotationTax;
    use RefreshDatabase;

    private const TAX_DATE = '2026-10-27';

    private int $pkr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->setUpQuotationTax();
        $this->pkr = (int) Currency::query()->where('code', 'PKR')->value('id');
        $this->currency = $this->pkr;
        $this->activateDeclaration($this->declaration([$this->coverage('goods', '10.000000000')]));
        $this->app->instance(QuotationTaxFacts::class, new FakeQuotationTaxFacts);
        $this->app->forgetInstance(QuotationTaxProvider::class);
    }

    private function assess(QuotationRevision $revision): array
    {
        return app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id);
    }

    /** @return list<string> */
    private function codes(array $result): array
    {
        return array_map(fn (array $b): string => $b['code'].($b['line_id'] === null ? '' : '@'.$b['line_id']), $result['blockers']);
    }

    public function test_a_tax_determined_and_calculated_by_fr3_is_issued_and_frozen_with_its_tax_generation(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);

        $this->assertSame([], $this->codes($this->assess($revision)), 'tax and controls are both established, so the draft is ready');
        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $issue = QuotationIssue::query()->findOrFail($outcome->issueId);
        $lines = QuotationIssuedLine::query()->where('issue_id', $issue->id)->orderBy('sort_order')->orderBy('id')->get();
        $this->assertSame(['FR3.1', $this->generation()], [$issue->tax_calculation_version, $issue->tax_generation]);
        $this->assertSame(64, strlen((string) $issue->tax_evidence_fingerprint));
        $this->assertEquals(['20.0000', '220.0000'], [(string) $issue->tax_total, (string) $issue->gross_total]);
        $this->assertEquals(['20.0000', '3.0000'], $lines->pluck('tax')->map(fn ($v): string => (string) $v)->all());
        $this->assertSame(['TAXABLE', 'TAXABLE'], $lines->pluck('tax_disposition')->all());
        $this->assertCount(2, $lines->pluck('tax_evidence_reference')->unique(), 'each line carries its own determination reference');
        foreach ($lines as $line) {
            $this->assertSame(64, strlen($line->tax_evidence_reference));
        }
    }

    public function test_without_a_jurisdiction_or_classification_source_every_line_is_blocked(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $lineIds = $revision->lines()->orderBy('id')->pluck('id')->all();

        $this->app->instance(QuotationTaxFacts::class, new FakeQuotationTaxFacts(jurisdiction: null));
        $this->assertContains('TAX_JURISDICTION_UNKNOWN', $this->codes($this->assess($revision)));

        $this->app->instance(QuotationTaxFacts::class, new FakeQuotationTaxFacts(code: null));
        $this->assertEqualsCanonicalizing(["TAX_CLASSIFICATION_REQUIRED@{$lineIds[0]}", "TAX_CLASSIFICATION_REQUIRED@{$lineIds[1]}"], array_values(array_filter($this->codes($this->assess($revision)), fn ($c) => str_starts_with($c, 'TAX_'))));

        $this->app->forgetInstance(QuotationTaxFacts::class);
        $this->app->bind(QuotationTaxFacts::class, UnavailableQuotationTaxFacts::class);
        $this->assertContains('TAX_JURISDICTION_UNKNOWN', $this->codes($this->assess($revision)), 'the shipped binding knows nothing');
    }

    public function test_a_classification_the_declaration_does_not_cover_is_unknown_never_zero(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $this->app->instance(QuotationTaxFacts::class, new FakeQuotationTaxFacts(code: 'services', nature: TaxNature::Services));

        $codes = $this->codes($this->assess($revision));

        $this->assertCount(2, array_filter($codes, fn ($c) => str_starts_with($c, 'TAX_CONTEXT_UNKNOWN@')));
        try {
            app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
            $this->fail('An uncovered classification must not be issued as zero tax.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('QUOTATION_NOT_ISSUABLE', $refusal->reasonCode);
        }
        $this->assertSame(0, QuotationIssue::query()->count());
    }

    public function test_a_net_that_is_not_known_yet_and_a_missing_tax_date_are_blockers(): void
    {
        $unpriced = $this->preparedDraft(taxDate: self::TAX_DATE);
        $line = $unpriced->lines()->orderBy('id')->first();
        DB::table('quotation_draft_lines')->where('id', $line->id)->update(['unit_price' => null, 'base_unit_price' => null, 'fx_evidence' => null, 'fx_fingerprint' => null, 'price_currency_id' => null, 'base_currency_id' => null]);

        $this->assertContains("TAX_NET_UNKNOWN@{$line->id}", $this->codes($this->assess($unpriced)));

        $noDate = $this->preparedDraft();
        DB::table('quotation_revisions')->where('id', $noDate->id)->update(['tax_estimate_date' => null]);
        $this->assertContains('TAX_ESTIMATE_DATE_REQUIRED', $this->codes($this->assess($noDate)));
    }

    private function context(QuotationRevision $revision, array $overrides = []): QuotationTaxContext
    {
        $line = $revision->lines()->orderBy('id')->firstOrFail();

        return new QuotationTaxContext(
            $overrides['company'] ?? 1, $overrides['party'] ?? $this->customer->id, $overrides['currency'] ?? $this->pkr, $overrides['line'] ?? 'line:'.$line->id,
            $overrides['jurisdiction'] ?? 'illustrative', $overrides['date'] ?? self::TAX_DATE, $overrides['classification'] ?? new TaxClassification(SourceKind::Manual, TaxNature::Goods, 'goods', 'manual_explicit'),
        );
    }

    public function test_the_sales_authorizer_admits_only_a_context_sales_would_build(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $determine = app(DetermineQuotationTaxContext::class);

        $this->assertSame('TAXABLE', $determine($this->maker, $this->context($revision))->disposition->value, 'the right context for a preparer');
        $this->assertSame('TAXABLE', $determine($this->checker, $this->context($revision))->disposition->value, 'and for an issuer');
        $other = Party::create(['code' => 'OTHER', 'name' => 'Someone else']);
        $forged = [
            'another party' => ['party' => $other->id],
            'another currency' => ['currency' => $this->currency + 1000],
            'another date' => ['date' => '2026-10-28'],
            'another jurisdiction' => ['jurisdiction' => 'elsewhere'],
            'another classification' => ['classification' => new TaxClassification(SourceKind::Manual, TaxNature::Goods, 'services', 'manual_explicit')],
            'another provenance' => ['classification' => new TaxClassification(SourceKind::Manual, TaxNature::Goods, 'goods', 'group_default')],
            'not a line reference' => ['line' => 'free-text'],
            'a line that does not exist' => ['line' => 'line:99999999'],
            'another company' => ['company' => 2],
        ];
        foreach ($forged as $name => $override) {
            try {
                $determine($this->maker, $this->context($revision, $override));
                $this->fail("A forged context ({$name}) must be refused.");
            } catch (AuthorizationException $refusal) {
                $this->assertSame('Quotation tax context is not available to this actor.', $refusal->getMessage(), $name);
            }
        }
    }

    public function test_the_authorizer_refuses_unentitled_staged_and_inactive_actors_and_issued_revisions(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $determine = app(DetermineQuotationTaxContext::class);
        $context = $this->context($revision);
        $nobody = User::factory()->create();
        $suspended = User::factory()->create();
        $suspended->givePermissionTo(PermissionVocabulary::QUOTATION_PREPARE);
        DB::table('users')->where('id', $suspended->id)->update(['status' => MemberStatus::Suspended->value]);

        foreach (['nobody' => $nobody, 'suspended' => $suspended->fresh()] as $name => $actor) {
            try {
                $determine($actor, $context);
                $this->fail("{$name} must be refused.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
        try {
            $determine($this->maker, $context);
            $this->fail('An issued revision is frozen; its tax is not re-determined.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        $determine($this->maker, $context);
    }
}
