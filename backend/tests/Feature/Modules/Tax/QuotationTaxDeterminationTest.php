<?php

namespace Tests\Feature\Modules\Tax;

use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Tax\Actions\ActivateTaxPartyProfile;
use App\Modules\Tax\Actions\CalculateQuotationTax;
use App\Modules\Tax\Actions\CreateTaxPartyProfileSuccessor;
use App\Modules\Tax\Actions\DetermineQuotationTaxContext;
use App\Modules\Tax\Actions\RequireCurrentQuotationTaxEvidence;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\Quotation\QuotationTaxCalculation;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxRefusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Tax\Concerns\BuildsQuotationTax;
use Tests\TestCase;

class QuotationTaxDeterminationTest extends TestCase
{
    use BuildsQuotationTax, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationTax();
    }

    private function profile(int $component, string $disposition = 'EXEMPT'): int
    {
        $id = app(CreateTaxPartyProfileSuccessor::class)($this->actor, $this->party->id, 'illustrative', null, new EffectivePeriod('2026-01-01', '2027-01-01'),
            'secret jurisdiction', 'secret registration', [['component_id' => $component, 'disposition' => $disposition, 'grounds' => 'secret grounds', 'evidence_reference' => 'secret certificate', 'issuer' => 'secret issuer',
                'issued_on' => '2025-01-01', 'expires_on' => '2027-01-01']], 'secret reason', 'profile', $this->generation());
        app(ActivateTaxPartyProfile::class)($this->actor, $id, 'secret activation', 'profile-activate', $this->generation());

        return $id;
    }

    public function test_default_consumer_is_denied_even_with_tax_management_and_no_projection_can_be_reached(): void
    {
        $this->expectException(AuthorizationException::class);
        app(DetermineQuotationTaxContext::class)($this->actor, $this->context());
    }

    public function test_context_seam_does_not_require_tax_management_and_does_not_authorise_other_party_or_source_facts(): void
    {
        $rule = $this->coverage();
        $id = $this->declaration([$rule]);
        $this->activateDeclaration($id);
        $context = $this->context();
        $this->allowContext($context);
        $this->actor->revokePermissionTo(TaxDutyManifest::CONFIGURE, TaxDutyManifest::PROFILE);
        $this->assertSame(TaxDisposition::Taxable, app(DetermineQuotationTaxContext::class)($this->actor, $context)->disposition);
        $this->expectException(AuthorizationException::class);
        app(DetermineQuotationTaxContext::class)($this->actor, $this->context('unentitled-source'));
    }

    public function test_inactive_fresh_actor_is_denied_even_if_the_hydrated_actor_is_still_active(): void
    {
        $context = $this->context();
        $this->allowContext($context);
        DB::table('users')->where('id', $this->actor->id)->update(['status' => MemberStatus::Suspended->value]);
        $this->assertSame(MemberStatus::Active, $this->actor->status);
        $this->expectException(AuthorizationException::class);
        app(DetermineQuotationTaxContext::class)($this->actor, $context);
    }

    public function test_foreign_company_context_is_refused_before_the_consumer_or_evidence_read(): void
    {
        $context = new QuotationTaxContext(2, $this->party->id, $this->currency, 'line', 'illustrative', '2026-10-27', new TaxClassification(SourceKind::Manual, TaxNature::Goods, 'goods', 'manual_explicit'));
        $this->allowContext($context);
        $this->expectException(AuthorizationException::class);
        app(DetermineQuotationTaxContext::class)($this->actor, $context);
    }

    public function test_declaration_activation_classification_and_half_open_dates_never_default_to_zero(): void
    {
        $rule = $this->coverage();
        $id = $this->declaration([$rule]);
        $contexts = [$this->context(), $this->context('gap', 'goods', '2027-01-01'), $this->context('missing-class', 'other')];
        $this->allowContext(...$contexts);
        $read = app(DetermineQuotationTaxContext::class);
        $this->assertSame('TAX_DECLARATION_UNAVAILABLE', $read($this->actor, $contexts[0])->reason);
        $this->activateDeclaration($id);
        $this->assertSame(TaxDisposition::Taxable, $read($this->actor, $contexts[0])->disposition);
        foreach ([$contexts[1], $contexts[2]] as $context) {
            $result = $read($this->actor, $context);
            $this->assertSame(TaxDisposition::Unknown, $result->disposition);
            $calculation = app(CalculateQuotationTax::class)($result, '100.0000', 'NET_FIXED')->jsonSerialize();
            $this->assertSame(['certainty' => 'UNKNOWN', 'amount' => null], $calculation['tax']);
            $this->assertSame(['certainty' => 'UNKNOWN', 'amount' => null], $calculation['gross']);
        }
    }

    public function test_company_tax_free_declarations_and_taxable_zero_are_distinct(): void
    {
        $rules = [$this->coverage('taxable-zero', '0.000000000'), $this->coverage('zero-rated', '10.000000000', 'ZERO_RATED'), $this->coverage('not-applicable', '10.000000000', 'NOT_APPLICABLE')];
        $this->activateDeclaration($this->declaration($rules));
        $contexts = array_map(fn (array $rule): QuotationTaxContext => $this->context('line-'.$rule['classification'], $rule['classification']), $rules);
        $this->allowContext(...$contexts);
        $dispositions = [];
        foreach ($contexts as $context) {
            $determination = app(DetermineQuotationTaxContext::class)($this->actor, $context);
            $dispositions[] = $determination->disposition->value;
            $calculation = app(CalculateQuotationTax::class)($determination, null, 'NET_FIXED')->jsonSerialize();
            $this->assertSame(['certainty' => 'COMPLETE', 'amount' => '0.0000'], $calculation['tax']);
            $this->assertSame(['certainty' => 'UNKNOWN', 'amount' => null], $calculation['gross']);
        }
        $this->assertSame(['TAXABLE', 'ZERO_RATED', 'NOT_APPLICABLE'], $dispositions);
    }

    public function test_exemption_is_selected_only_from_activated_component_specific_profile_evidence_and_is_redacted(): void
    {
        $rule = $this->coverage('goods', '10.000000000', 'UNKNOWN', true);
        $this->activateDeclaration($this->declaration([$rule]));
        $context = $this->context();
        $this->allowContext($context);
        $read = app(DetermineQuotationTaxContext::class);
        $this->assertSame(TaxDisposition::Unknown, $read($this->actor, $context)->disposition);
        $profile = $this->profile($rule['component_id']);
        $result = $read($this->actor, $context);
        $this->assertSame(TaxDisposition::Exempt, $result->disposition);
        $this->assertSame($profile, $result->internalEvidence()['profile_version_id']);
        $json = json_encode($result);
        $debug = print_r($result, true);
        foreach (['secret', 'profile_version_id', 'profile_evidence_id', 'percentage', 'grounds', 'registration'] as $private) {
            $this->assertStringNotContainsString($private, $json);
            $this->assertStringNotContainsString($private, $debug);
        }
        $this->assertSame('0.0000', app(CalculateQuotationTax::class)($result, '100.0000', 'NET_FIXED')->jsonSerialize()['tax']['amount']);
    }

    public function test_explicit_unknown_profile_cannot_be_suppressed_by_a_company_tax_free_declaration(): void
    {
        $rule = $this->coverage('goods', '10.000000000', 'NOT_APPLICABLE');
        $this->activateDeclaration($this->declaration([$rule]));
        $this->profile($rule['component_id'], 'UNKNOWN');
        $context = $this->context();
        $this->allowContext($context);
        $result = app(DetermineQuotationTaxContext::class)($this->actor, $context);
        $this->assertSame(TaxDisposition::Unknown, $result->disposition);
        $this->assertSame('TAX_DECLARATION_CONFLICT', $result->reason);
        $this->assertSame('TAX_CONTEXT_UNKNOWN', $result->jsonSerialize()['reason']);
    }

    public function test_exact_percentage_and_line_component_rounding_reconcile_without_aggregate_rerounding(): void
    {
        $this->activateDeclaration($this->declaration([$this->coverage()]));
        $contexts = [$this->context('c'), $this->context('a'), $this->context('b')];
        $this->allowContext(...$contexts);
        $calculate = app(CalculateQuotationTax::class);
        $read = app(DetermineQuotationTaxContext::class);
        $large = $calculate($read($this->actor, $contexts[0]), '1000.0000', 'NET_FIXED');
        $this->assertSame('100.0000', $large->jsonSerialize()['tax']['amount']);
        $this->assertSame('1100.0000', $large->jsonSerialize()['gross']['amount']);
        $lines = array_map(fn (QuotationTaxContext $c) => $calculate($read($this->actor, $c), '0.0500', 'NET_FIXED'), $contexts);
        $sum = QuotationTaxCalculation::sum($lines);
        $json = $sum->jsonSerialize();
        $this->assertSame('0.0300', $json['tax']['amount']);
        $this->assertSame('0.1800', $json['gross']['amount']);
        $this->assertSame(['a', 'b', 'c'], array_column($json['lines'], 'line_reference'));
        $this->assertSame(['numerator' => '1', 'denominator' => '200'], $lines[0]->internalEvidence()['raw_tax']);
        $this->assertSame(['numerator' => '1', 'denominator' => '200'], $lines[0]->internalEvidence()['residual']);
        $this->assertFalse($json['issue_ready']);
        $this->assertSame('UNKNOWN', $json['payment']['certainty']);
    }

    public function test_different_effective_components_and_rates_in_one_declaration_sum_as_settled(): void
    {
        $this->activateDeclaration($this->declaration([$this->coverage('goods', '10.000000000'), $this->coverage('services', '5.000000000', 'TAXABLE', false, 'SERVICES')]));
        $contexts = [$this->context('a'), $this->context('b', 'services', '2026-10-27', TaxNature::Services)];
        $this->allowContext(...$contexts);
        $lines = array_map(fn (QuotationTaxContext $c) => app(CalculateQuotationTax::class)(app(DetermineQuotationTaxContext::class)($this->actor, $c), '100.0000', 'NET_FIXED'), $contexts);
        $sum = QuotationTaxCalculation::sum($lines)->jsonSerialize();
        $this->assertSame('15.0000', $sum['tax']['amount']);
        $this->assertSame('215.0000', $sum['gross']['amount']);
    }

    public function test_freshness_rechecks_context_tax_generation_and_currency_quantum_without_mutating_old_evidence(): void
    {
        $this->activateDeclaration($this->declaration([$this->coverage()]));
        $context = $this->context();
        $this->allowContext($context);
        $result = app(DetermineQuotationTaxContext::class)($this->actor, $context);
        $before = $result->fingerprint();
        app(RequireCurrentQuotationTaxEvidence::class)($this->actor, $context, $result);
        $this->profile($result->internalEvidence()['component_id'], 'TAXABLE');
        try {
            app(RequireCurrentQuotationTaxEvidence::class)($this->actor, $context, $result);
            $this->fail('Stale Tax evidence accepted.');
        } catch (TaxRefusal $e) {
            $this->assertSame('TAX_CONTEXT_STALE', $e->reasonCode);
        }
        $this->assertSame($before, $result->fingerprint());
    }
}
