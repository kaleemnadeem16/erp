<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Modules\Sales\Actions\ApproveSalesPriceReference;
use App\Modules\Sales\Actions\BindQuotationAuthorityReferences;
use App\Modules\Sales\Actions\CreateQuotationRevision;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\ProposeSalesPriceReference;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesPriceReference;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\DiscountAuthority\GovernedReferences;
use App\Modules\Sales\Support\DiscountAuthority\PinnedAuthorityFx;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationMoney;
use App\Support\CompanyContext;
use App\Support\RateType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class GovernedPriceReferenceTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::FINANCIAL_CONFIGURE, Duty::PRICE_REFERENCE_APPROVE]));
        foreach ([Duty::FINANCIAL_CONFIGURE, Duty::PRICE_REFERENCE_APPROVE] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->maker->givePermissionTo(Duty::FINANCIAL_CONFIGURE);
        $this->checker->givePermissionTo(Duty::PRICE_REFERENCE_APPROVE);
    }

    private function generation(): int
    {
        return (int) DB::table('sales_authority_heads')->where('company_id', 1)->value('generation');
    }

    /** @param array<string, mixed> $over */
    private function propose(QuotationRevision $revision, array $over = []): SalesPriceReference
    {
        return app(ProposeSalesPriceReference::class)($this->maker, $this->issueKey(), $this->generation(), array_replace([
            'code' => 'test-reference', 'source_kind' => 'PROJECT_BASELINE', 'source_key' => 'independent-project-survey', 'source_version' => '1',
            'family_id' => $revision->family_id, 'manual_measure' => 'job', 'currency_id' => $revision->currency_id, 'unit_price' => '100.0000',
            'effective_from' => $this->today(), 'priority' => 0, 'reason' => 'Test governed baseline',
        ], $over));
    }

    private function approve(SalesPriceReference $reference): void
    {
        app(ApproveSalesPriceReference::class)($this->checker, $reference->id, $this->issueKey(), $this->generation(), 'Independently verified source');
    }

    public function test_an_unapproved_or_missing_reference_never_resolves(): void
    {
        $revision = $this->preparedDraft();
        $this->propose($revision);
        $this->expectException(DomainInvariantException::class);
        app(GovernedReferences::class)->resolve($revision, $revision->lines()->firstOrFail());
    }

    public function test_activation_requires_an_independent_person_even_with_both_duties(): void
    {
        $revision = $this->preparedDraft();
        $reference = $this->propose($revision);
        $this->maker->givePermissionTo(Duty::PRICE_REFERENCE_APPROVE);
        try {
            app(ApproveSalesPriceReference::class)($this->maker, $reference->id, $this->issueKey(), $this->generation(), 'Self activation');
            $this->fail('Self activation must be refused');
        } catch (DomainInvariantException $e) {
            $this->assertSame('REFERENCE_INDEPENDENT_APPROVAL_REQUIRED', $e->reasonCode);
        }
        $this->assertDatabaseCount('sales_price_reference_activations', 0);
    }

    public function test_binding_survives_price_edits_line_splits_and_new_reference_versions(): void
    {
        $revision = $this->preparedDraft();
        $reference = $this->propose($revision);
        $this->approve($reference);
        $result = app(BindQuotationAuthorityReferences::class)($this->maker, $revision->id, $revision->input_generation, 'bind-once');
        $this->assertCount(2, $result['references']);
        $this->assertDatabaseCount('sales_quotation_reference_bindings', 1);
        $line = $revision->lines()->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), [
            'update_lines' => [['id' => $line->id, 'unit_price' => '10.0000']],
            'add_lines' => [['source_kind' => 'MANUAL', 'description' => 'Split survey', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '10.0000']],
        ]);
        $new = $this->propose($revision, ['unit_price' => '10.0000', 'source_version' => '2']);
        $this->approve($new);
        foreach ($revision->refresh()->lines()->get() as $split) {
            $this->assertSame($reference->id, app(GovernedReferences::class)->resolve($revision, $split)->id);
        }
        $this->assertSame('100.0000', $reference->unit_price);
    }

    public function test_equal_priority_independent_sources_are_ambiguous(): void
    {
        $revision = $this->preparedDraft();
        $this->approve($this->propose($revision));
        $this->approve($this->propose($revision, ['code' => 'other-reference']));
        try {
            app(GovernedReferences::class)->resolve($revision, $revision->lines()->firstOrFail());
            $this->fail('Ambiguity must not pick the lower id');
        } catch (DomainInvariantException $e) {
            $this->assertSame('REFERENCE_AMBIGUOUS', $e->reasonCode);
        }
    }

    public function test_family_and_effective_date_are_not_optional_filters(): void
    {
        $revision = $this->preparedDraft();
        $other = $this->preparedDraft();
        $this->approve($this->propose($other));
        $this->approve($this->propose($revision, ['code' => 'future', 'effective_from' => now()->addDay()->format('Y-m-d')]));
        $this->expectException(DomainInvariantException::class);
        app(GovernedReferences::class)->resolve($revision, $revision->lines()->firstOrFail());
    }

    public function test_direct_actions_guard_staged_duties_and_replays_check_current_access(): void
    {
        $revision = $this->preparedDraft();
        $reference = $this->propose($revision);
        $generation = $this->generation();
        $first = app(ApproveSalesPriceReference::class)($this->checker, $reference->id, 'activation-replay', $generation, 'Verified');
        $again = app(ApproveSalesPriceReference::class)($this->checker, $reference->id, 'activation-replay', $generation, 'Verified');
        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('sales_price_reference_activations', 1);
        $this->checker->revokePermissionTo(Duty::PRICE_REFERENCE_APPROVE);
        $this->expectException(AuthorizationException::class);
        app(ApproveSalesPriceReference::class)($this->checker, $reference->id, 'activation-replay', $generation, 'Verified');
    }

    public function test_stale_configuration_and_changed_retry_intent_are_refused(): void
    {
        $revision = $this->preparedDraft();
        $reference = $this->propose($revision);
        $generation = $this->generation();
        app(ApproveSalesPriceReference::class)($this->checker, $reference->id, 'activation-key', $generation, 'Verified');
        foreach ([['activation-key', $generation, 'Changed'], ['new-key', $generation, 'Verified']] as [$key, $expected, $reason]) {
            try {
                app(ApproveSalesPriceReference::class)($this->checker, $reference->id, $key, $expected, $reason);
                $this->fail('Changed intent or generation must refuse');
            } catch (DomainInvariantException $e) {
                $this->assertContains($e->reasonCode, ['REQUEST_KEY_REUSED', 'AUTHORITY_STALE']);
            }
        }
    }

    public function test_raw_database_mutation_and_self_activation_are_refused(): void
    {
        $reference = $this->propose($this->preparedDraft());
        foreach (['update', 'delete', 'self-approve'] as $operation) {
            try {
                DB::transaction(function () use ($reference, $operation): void {
                    if ($operation === 'update') {
                        DB::table('sales_price_reference_versions')->where('id', $reference->id)->update(['unit_price' => '1.0000']);
                    } elseif ($operation === 'delete') {
                        DB::table('sales_price_reference_versions')->where('id', $reference->id)->delete();
                    } else {
                        DB::table('sales_price_reference_activations')->insert(['company_id' => 1, 'reference_id' => $reference->id, 'approved_by' => $this->maker->id, 'reason' => 'Bypass']);
                    }
                });
                $this->fail('The database must reject '.$operation);
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_pinned_identity_fx_is_verified_and_missing_evidence_is_not_one(): void
    {
        $revision = $this->preparedDraft();
        $line = $revision->lines()->firstOrFail();
        $base = (int) Company::findOrFail(1)->base_currency_id;
        $this->assertTrue(app(PinnedAuthorityFx::class)->ratio($revision, $line, $base)->isEqualTo(1));
        $line->fx_evidence = null;
        $this->expectException(DomainInvariantException::class);
        app(PinnedAuthorityFx::class)->ratio($revision, $line, $base);
    }

    public function test_no_live_manifest_or_grant_is_enabled_by_the_foundation(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        app(ApproveSalesPriceReference::class)(User::factory()->create(), 99999, 'denied', 0, 'Denied');
    }

    public function test_a_successor_revision_inherits_the_family_reference_without_copying_entered_prices(): void
    {
        $revision = $this->preparedDraft();
        $reference = $this->propose($revision);
        $this->approve($reference);
        app(BindQuotationAuthorityReferences::class)($this->maker, $revision->id, $revision->input_generation, 'bind-predecessor');
        app(IssueQuotation::class)($this->checker, $revision->id, $revision->input_generation, 'issue-predecessor');
        $successor = app(CreateQuotationRevision::class)($this->maker, $revision->id, 'new-revision');
        app(UpdateQuotationDraft::class)($this->maker, $successor->revisionId, $successor->inputGeneration, 'date-successor', ['pricing_date' => $this->today()]);
        $draft = QuotationRevision::findOrFail($successor->revisionId);
        $line = $draft->lines()->firstOrFail();
        $this->assertNull($line->unit_price);
        $this->assertSame($reference->id, app(GovernedReferences::class)->resolve($draft, $line)->id);
    }

    public function test_pinned_foreign_fx_survives_a_rate_master_change_and_rejects_tampering(): void
    {
        $revision = $this->preparedDraft();
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        $base = (int) Company::findOrFail(1)->base_currency_id;
        $rate = ExchangeRate::create(['from_currency_id' => $usd->id, 'to_currency_id' => $base, 'rate' => '280.000000000', 'rate_date' => $this->today(), 'rate_type' => RateType::Spot, 'source' => 'manual']);
        $money = app(QuotationMoney::class)->enter($usd->id, $this->today(), '100.0000');
        $line = new QuotationDraftLine($money);
        $revision->currency_id = $usd->id;
        $rate->update(['rate' => '300.000000000']);
        $this->assertTrue(app(PinnedAuthorityFx::class)->ratio($revision, $line, $base)->isEqualTo(280));
        $evidence = $line->fx_evidence;
        $evidence['ratio']['numerator'] = '1';
        $line->fx_evidence = $evidence;
        $this->expectException(DomainInvariantException::class);
        app(PinnedAuthorityFx::class)->ratio($revision, $line, $base);
    }

    public function test_reference_precision_is_refused_instead_of_rounded(): void
    {
        $this->expectException(ValidationException::class);
        $this->propose($this->preparedDraft(), ['unit_price' => '100.00001']);
    }

    public function test_a_foreign_company_actor_cannot_configure_or_replay_company_one_evidence(): void
    {
        $foreign = Company::create(['name' => 'Another company', 'timezone' => 'UTC']);
        $this->app->instance(CompanyContext::class, new class($foreign->id) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $actor = User::factory()->create();
        $this->app->instance(CompanyContext::class, new CompanyContext);
        $this->expectException(AuthorizationException::class);
        app(ApproveSalesPriceReference::class)($actor, 99999, 'foreign', 0, 'Not ours');
    }
}
