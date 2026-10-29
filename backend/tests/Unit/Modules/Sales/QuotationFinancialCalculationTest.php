<?php

namespace Tests\Unit\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Financial\Calculation\CalculateQuotationFinancials;
use App\Modules\Sales\Support\Financial\Calculation\QuotationFinancialLine;
use App\Modules\Sales\Support\Financial\Controls\ControlRefusal;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Fx\EffectivePeriod;
use App\Modules\Sales\Support\Pricing\Simple\CalculateSimplePrice;
use App\Modules\Sales\Support\Pricing\Simple\SimpleAdjustment;
use App\Modules\Sales\Support\Pricing\Simple\SimplePriceInput;
use App\Modules\Tax\Support\Calculation\BasicTaxInput;
use App\Modules\Tax\Support\Calculation\CalculateBasicTax;
use App\Modules\Tax\Support\TaxDisposition;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

class QuotationFinancialCalculationTest extends TestCase
{
    private function rule(int $places = 2, int $currency = 1): SettlementRule
    {
        return new SettlementRule($currency, new CurrencyQuantum($places), RoundingMode::HALF_UP);
    }

    private function input(?string $price = '1000', string $quantity = '1', SimpleAdjustment $kind = SimpleAdjustment::None, string $value = '0', string $key = 'line-1'): SimplePriceInput
    {
        return new SimplePriceInput(1, 1, $key, 'source-v1', '2026-10-27', $price, $quantity, $kind, $value);
    }

    private function line(?string $price = '1000', string $quantity = '1', string $key = 'line-1', TaxDisposition $disposition = TaxDisposition::Taxable, ?string $rate = '10'): QuotationFinancialLine
    {
        return new QuotationFinancialLine($this->input($price, $quantity, key: $key), new BasicTaxInput(1, $disposition, $rate, 'protected-component', 'protected-determination'));
    }

    private function calculate(): CalculateQuotationFinancials
    {
        return new CalculateQuotationFinancials(new CalculateSimplePrice, new CalculateBasicTax);
    }

    private function refused(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected '.$code);
        } catch (DomainInvariantException $e) {
            $this->assertSame($code, $e->reasonCode);
        }
    }

    public function test_simple_adjustment_bases_are_distinct_and_apply_exactly_once(): void
    {
        $prices = new CalculateSimplePrice;
        foreach ([[SimpleAdjustment::Percent, '10', '1800.0000'], [SimpleAdjustment::PerUnit, '10', '1980.0000'], [SimpleAdjustment::PerLine, '10', '1990.0000']] as [$kind, $value, $expected]) {
            $result = $prices($this->input('1000', '2', $kind, $value), RoundingPolicy::builtInDefault(1), $this->rule());
            $this->assertSame($expected, $result['net']);
            $this->assertSame($kind->value, $result['adjustment_kind']);
            $input = $this->input('1000', '2', $kind, $value);
            $this->assertSame($kind === SimpleAdjustment::Percent, $input->discretionary->isPercent);
            $this->assertSame($kind === SimpleAdjustment::PerUnit ? 'PER_UNIT' : 'LINE', $input->discretionary->basis->value);
        }
    }

    public function test_adjustment_and_unit_precision_are_refused_instead_of_clamped_or_rounded(): void
    {
        $prices = new CalculateSimplePrice;
        $policy = RoundingPolicy::builtInDefault(1);
        $this->refused(fn () => $prices($this->input('1', '2', SimpleAdjustment::PerLine, '3'), $policy, $this->rule()), 'SIMPLE_ADJUSTMENT_EXCEEDS_BASE');
        $this->refused(fn () => $this->input(kind: SimpleAdjustment::Percent, value: '100.000000001'), ControlRefusal::FACTS_INVALID);
        $this->refused(fn () => $this->input(kind: SimpleAdjustment::Percent, value: '0.00001'), ControlRefusal::FACTS_INVALID);
        $this->refused(fn () => $this->input(value: '1'), 'SIMPLE_ADJUSTMENT_INVALID');
        $this->refused(fn () => $this->input(quantity: '0'), 'SIMPLE_PRICE_INVALID');
        $this->refused(fn () => $prices($this->input('1.00001'), $policy, $this->rule()), 'UNIT_PRICE_PRECISION_EXCEEDED');
        $this->refused(fn () => $prices($this->input('999999999999999.9999', '2'), $policy, $this->rule()), 'AMOUNT_OVERFLOW');
        $this->refused(fn () => $prices($this->input('-1'), $policy, $this->rule()), 'NEGATIVE_AMOUNT');
    }

    public function test_zero_missing_price_and_full_discount_remain_distinct(): void
    {
        $prices = new CalculateSimplePrice;
        $policy = RoundingPolicy::builtInDefault(1);
        $this->assertSame('0.0000', $prices($this->input('0'), $policy, $this->rule())['net']);
        $this->assertNull($prices($this->input(null), $policy, $this->rule())['net']);
        $this->assertSame('0.0000', $prices($this->input('100', '1', SimpleAdjustment::Percent, '100'), $policy, $this->rule())['net']);
    }

    public function test_document_sums_settled_lines_and_line_tax_without_rounding_a_raw_header(): void
    {
        $calculate = $this->calculate();
        $result = $calculate([$this->line('0.3350', '3', 'c'), $this->line('0.3350', '3', 'a'), $this->line('0.3350', '3', 'b')], RoundingPolicy::builtInDefault(1), $this->rule())->toInternalArray();
        $this->assertSame('3.0300', $result['totals']['net']['amount']);
        $this->assertSame('0.3000', $result['totals']['tax']['amount']);
        $this->assertSame('3.3300', $result['totals']['gross']['amount']);
        $this->assertSame(['a', 'b', 'c'], array_column($result['lines'], 'line_key'));
        $this->assertSame(['numerator' => '1', 'denominator' => '200'], $result['lines'][0]['price']['settlement']['residual']);
        $small = $calculate([$this->line('0.05', key: 'a'), $this->line('0.05', key: 'b'), $this->line('0.05', key: 'c')], RoundingPolicy::builtInDefault(1), $this->rule())->consumerSafe();
        $this->assertSame('0.0300', $small['totals']['tax']['amount']);
    }

    public function test_tax_uses_the_discounted_settled_net_with_mixed_line_dispositions(): void
    {
        $discounted = new QuotationFinancialLine($this->input('1000', '1', SimpleAdjustment::Percent, '10'), new BasicTaxInput(1, TaxDisposition::Taxable, '10', 'c', 'd'));
        $result = $this->calculate()([$discounted, $this->line('100', key: 'b', disposition: TaxDisposition::ZeroRated, rate: '0'), $this->line('100', key: 'c', rate: '5')], RoundingPolicy::builtInDefault(1), $this->rule())->consumerSafe();
        $this->assertSame('1100.0000', $result['totals']['net']['amount']);
        $this->assertSame('95.0000', $result['totals']['tax']['amount']);
        $this->assertSame('1195.0000', $result['totals']['gross']['amount']);
    }

    public function test_unknown_tax_retains_known_net_and_partial_document_totals_without_fabricated_payment(): void
    {
        $result = $this->calculate()([$this->line(key: 'a'), $this->line(key: 'b', disposition: TaxDisposition::Unknown, rate: null)], RoundingPolicy::builtInDefault(1), $this->rule())->consumerSafe();
        $this->assertSame('2000.0000', $result['totals']['net']['amount']);
        $this->assertSame(['certainty' => 'PARTIAL', 'amount' => null, 'known_amount' => '100.0000'], $result['totals']['tax']);
        $this->assertSame(['certainty' => 'PARTIAL', 'amount' => null, 'known_amount' => '1100.0000'], $result['totals']['gross']);
        $this->assertSame(['certainty' => 'UNKNOWN', 'amount' => null], $result['estimated_payment']);
        $this->assertNull($result['lines'][1]['tax']);
        $this->assertFalse($result['issue_ready']);
        $allUnknown = $this->calculate()([$this->line(null)], RoundingPolicy::builtInDefault(1), $this->rule())->consumerSafe();
        $this->assertSame(['certainty' => 'UNKNOWN', 'amount' => null, 'known_amount' => null], $allUnknown['totals']['net']);
    }

    public function test_complete_commercial_calculation_never_grants_issue_readiness_or_leaks_evidence(): void
    {
        $result = $this->calculate()([$this->line()], RoundingPolicy::builtInDefault(1), $this->rule());
        $safe = $result->consumerSafe();
        $this->assertSame('1100.0000', $safe['totals']['gross']['amount']);
        $this->assertFalse($safe['issue_ready']);
        $this->assertStringNotContainsString('protected-', json_encode($safe, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('percentage', json_encode($safe, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('protected-determination', json_encode($result->toInternalArray(), JSON_THROW_ON_ERROR));
        $this->assertSame('{}', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_fingerprints_use_stable_identity_order_and_bind_inputs_policy_and_currency_quantum(): void
    {
        $calculate = $this->calculate();
        $lines = [$this->line(key: 'a'), $this->line(key: 'b')];
        $policy = RoundingPolicy::builtInDefault(1);
        $a = $calculate($lines, $policy, $this->rule());
        $this->assertSame($a->fingerprint(), $calculate(array_reverse($lines), $policy, $this->rule())->fingerprint());
        $this->assertNotSame($a->fingerprint(), $calculate([$this->line(rate: '5')], $policy, $this->rule())->fingerprint());
        $this->assertNotSame($calculate([$this->line(null)], $policy, $this->rule(2))->fingerprint(), $calculate([$this->line(null)], $policy, $this->rule(3))->fingerprint());
    }

    public function test_currency_quanta_and_known_disposition_evidence_remain_distinct(): void
    {
        $calculate = $this->calculate();
        $policy = RoundingPolicy::builtInDefault(1);
        foreach ([0 => '1.0000', 2 => '1.0100', 4 => '1.0050'] as $places => $net) {
            $result = $calculate([$this->line('0.3350', '3')], $policy, $this->rule($places));
            $this->assertSame($net, $result->consumerSafe()['totals']['net']['amount']);
        }
        $fingerprints = [];
        foreach ([TaxDisposition::Exempt, TaxDisposition::NotApplicable, TaxDisposition::ZeroRated] as $disposition) {
            $result = $calculate([$this->line(disposition: $disposition, rate: '0')], $policy, $this->rule());
            $this->assertSame('0.0000', $result->consumerSafe()['totals']['tax']['amount']);
            $this->assertSame($disposition->value, $result->toInternalArray()['lines'][0]['tax_facts']['disposition']);
            $fingerprints[] = $result->fingerprint();
        }
        $this->assertCount(3, array_unique($fingerprints));
    }

    public function test_foreign_context_duplicate_scope_and_outdated_policy_are_refused(): void
    {
        $calc = $this->calculate();
        $lines = [$this->line()];
        $this->refused(fn () => $calc($lines, RoundingPolicy::builtInDefault(2), $this->rule()), 'FINANCIAL_CONTEXT_MISMATCH');
        $this->refused(fn () => $calc($lines, RoundingPolicy::builtInDefault(1), $this->rule(currency: 2)), 'FINANCIAL_CONTEXT_MISMATCH');
        $this->refused(fn () => new QuotationFinancialLine($this->input(), new BasicTaxInput(2, TaxDisposition::Unknown)), 'FINANCIAL_CONTEXT_MISMATCH');
        $this->refused(fn () => $calc([$this->line(), $this->line()], RoundingPolicy::builtInDefault(1), $this->rule()), 'FINANCIAL_LINES_INVALID');
        $this->refused(fn () => $calc(['not-a-list' => $this->line()], RoundingPolicy::builtInDefault(1), $this->rule()), 'FINANCIAL_LINES_INVALID');
        $this->refused(fn () => $calc([], RoundingPolicy::builtInDefault(1), $this->rule()), 'FINANCIAL_LINES_INVALID');
        $expired = new RoundingPolicy(1, 1, 1, 4, SettlementRounding::HalfUp, new EffectivePeriod('2026-01-01', '2026-02-01'));
        $this->refused(fn () => $calc($lines, $expired, $this->rule()), 'FINANCIAL_CONTEXT_MISMATCH');
        $wrongMode = new SettlementRule(1, new CurrencyQuantum(2), RoundingMode::DOWN);
        $this->refused(fn () => $calc($lines, RoundingPolicy::builtInDefault(1), $wrongMode), 'FINANCIAL_CONTEXT_MISMATCH');
    }
}
