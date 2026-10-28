<?php

namespace Tests\Unit\Modules\Tax;

use App\Exceptions\DomainInvariantException;
use App\Modules\Tax\Actions\CalculateQuotationTax;
use App\Modules\Tax\Support\Quotation\QuotationTaxCalculation;
use App\Modules\Tax\Support\Quotation\QuotationTaxDetermination;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic facts test arithmetic only, never statutory entitlement or an issue token. */
class QuotationPercentageTaxTest extends TestCase
{
    private function facts(TaxDisposition $disposition = TaxDisposition::Taxable, ?string $percentage = '10.000000000', int $places = 2, string $rounding = 'HALF_UP', string $line = 'line', array $replace = []): QuotationTaxDetermination
    {
        return new QuotationTaxDetermination($line, $disposition, 'TAX_DETERMINED', array_replace(['company_id' => 1, 'currency_id' => 1, 'currency_places' => $places, 'tax_generation' => 1,
            'tax_date' => '2026-10-27', 'declaration_version_id' => 1, 'declaration_version_no' => 1, 'calculation_version' => 'FR3.1', 'settlement_basis' => 'LINE_COMPONENT', 'rounding_mode' => $rounding], $replace), $percentage);
    }

    private function refuses(callable $work): void
    {
        try {
            $work();
            $this->fail('Unsupported Tax calculation accepted.');
        } catch (TaxRefusal $e) {
            $this->assertNotEmpty($e->reasonCode);
        }
    }

    public static function roundingCases(): array
    {
        return [['HALF_UP', '0.0100'], ['HALF_EVEN', '0.0000'], ['HALF_DOWN', '0.0000'], ['UP', '0.0100'], ['DOWN', '0.0000']];
    }

    #[DataProvider('roundingCases')]
    public function test_rounding_is_explicit_per_line_component(string $mode, string $tax): void
    {
        $result = (new CalculateQuotationTax)($this->facts(rounding: $mode), '0.0500', 'NET_FIXED');
        $this->assertSame($tax, $result->jsonSerialize()['tax']['amount']);
        $this->assertSame(['numerator' => '1', 'denominator' => '200'], $result->internalEvidence()['raw_tax']);
    }

    public static function quantumCases(): array
    {
        return [[0, '1.0000', '1.0000'], [2, '0.0500', '0.0100'], [3, '0.0050', '0.0010'], [4, '0.0005', '0.0001']];
    }

    #[DataProvider('quantumCases')]
    public function test_currency_quantum_is_pinned(int $places, string $net, string $tax): void
    {
        $result = (new CalculateQuotationTax)($this->facts(percentage: $places === 0 ? '100.000000000' : '10.000000000', places: $places), $net, 'NET_FIXED');
        $this->assertSame($tax, $result->jsonSerialize()['tax']['amount']);
    }

    public function test_unknown_and_known_zero_never_conflate(): void
    {
        $calculate = new CalculateQuotationTax;
        $unknown = $calculate($this->facts(TaxDisposition::Unknown, null), '100.0000', 'NET_FIXED')->jsonSerialize();
        $this->assertSame(['certainty' => 'UNKNOWN', 'amount' => null], $unknown['tax']);
        $this->assertNull($unknown['gross']['amount']);
        foreach ([TaxDisposition::Exempt, TaxDisposition::ZeroRated, TaxDisposition::NotApplicable] as $disposition) {
            $result = $calculate($this->facts($disposition), null, 'NET_FIXED')->jsonSerialize();
            $this->assertSame($disposition->value, $result['disposition']);
            $this->assertSame('0.0000', $result['tax']['amount']);
            $this->assertNull($result['gross']['amount']);
        }
        foreach (['0.000000000', '100.000000000'] as $percentage) {
            $result = $calculate($this->facts(percentage: $percentage), '0.0000', 'NET_FIXED')->jsonSerialize();
            $this->assertSame('COMPLETE', $result['tax']['certainty']);
            $this->assertSame('0.0000', $result['gross']['amount']);
        }
    }

    public function test_rate_endpoints_and_settled_gross_are_exact(): void
    {
        $result = (new CalculateQuotationTax)($this->facts(percentage: '100.000000000'), '12.3400', 'NET_FIXED')->jsonSerialize();
        $this->assertSame('12.3400', $result['tax']['amount']);
        $this->assertSame('24.6800', $result['gross']['amount']);
    }

    public function test_missing_rate_unsettled_net_inclusive_and_unversioned_policy_refuse(): void
    {
        $calculate = new CalculateQuotationTax;
        $this->refuses(fn () => $calculate($this->facts(percentage: null), '100.0000', 'NET_FIXED'));
        $this->refuses(fn () => $calculate($this->facts(), '0.3350', 'NET_FIXED'));
        foreach (['GROSS_FIXED', 'INCLUSIVE'] as $intent) {
            $this->refuses(fn () => $calculate($this->facts(), '100.0000', $intent));
        }
        $this->refuses(fn () => $calculate($this->facts(replace: ['settlement_basis' => 'DOCUMENT_AGGREGATE']), '100.0000', 'NET_FIXED'));
        foreach (['-1.000000000', '100.000000001'] as $rate) {
            $this->refuses(fn () => $calculate($this->facts(percentage: $rate), '100.0000', 'NET_FIXED'));
        }
    }

    public function test_negative_and_overflowing_net_or_gross_are_not_clamped(): void
    {
        foreach (['-1.0000', '1000000000000000.0000', '999999999999999.9900'] as $net) {
            try {
                (new CalculateQuotationTax)($this->facts(), $net, 'NET_FIXED');
                $this->fail('Invalid/overflowing net or gross accepted.');
            } catch (DomainInvariantException $e) {
                $this->assertNotEmpty($e->reasonCode);
            }
        }
    }

    public function test_header_rejects_duplicate_lines_and_company_currency_or_generation_mismatch(): void
    {
        $calculate = new CalculateQuotationTax;
        $line = $calculate($this->facts(), '10.0000', 'NET_FIXED');
        $this->refuses(fn () => QuotationTaxCalculation::sum([$line, $line]));
        foreach (['currency_id' => 2, 'company_id' => 2, 'tax_generation' => 2] as $field => $value) {
            $other = $calculate($this->facts(line: 'other', replace: [$field => $value]), '10.0000', 'NET_FIXED');
            $this->refuses(fn () => QuotationTaxCalculation::sum([$line, $other]));
        }
        $this->refuses(fn () => QuotationTaxCalculation::sum([]));
    }

    public function test_partial_header_keeps_only_a_known_lower_bound(): void
    {
        $calculate = new CalculateQuotationTax;
        $known = $calculate($this->facts(line: 'known'), '100.0000', 'NET_FIXED');
        $unknown = $calculate($this->facts(TaxDisposition::Unknown, null, line: 'unknown'), '100.0000', 'NET_FIXED');
        $sum = QuotationTaxCalculation::sum([$unknown, $known])->jsonSerialize();
        $this->assertSame(['certainty' => 'PARTIAL', 'amount' => null, 'known_amount' => '10.0000'],$sum['tax']);
        $this->assertSame('PARTIAL',$sum['gross']['certainty']);
        $this->assertSame('200.0000',$sum['net']['amount']);
    }
}
