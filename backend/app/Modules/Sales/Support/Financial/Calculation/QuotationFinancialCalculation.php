<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Calculation;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Kernel\OperationIntent;

/** Internal calculation evidence is not a durable candidate, current authority verdict, issue token or customer projection. */
final readonly class QuotationFinancialCalculation
{
    public const VERSION = 'M-PRFR1.1';

    /** @param list<array<string, mixed>> $lines */
    public function __construct(private RoundingPolicy $policy, private SettlementRule $rule, private array $lines) {}

    /** @return array<string, mixed> server-only: includes protected determination references */
    public function toInternalArray(): array
    {
        return ['calculation_version' => self::VERSION, 'company_id' => $this->policy->companyId, 'currency_id' => $this->rule->currencyId,
            'settlement_rule' => ['quantum_places' => $this->rule->quantum->decimalPlaces, 'rounding_mode' => $this->rule->mode->name],
            'rounding_policy' => $this->policy->toArray(), 'lines' => $this->lines, 'totals' => $this->totals(),
            'withholding' => ['certainty' => 'UNKNOWN', 'amount' => null], 'estimated_payment' => ['certainty' => 'UNKNOWN', 'amount' => null],
            'issue_ready' => false, 'issue_blockers' => self::issueBlockers()];
    }

    /**
     * Future callers still need their record/field guards. No determination refs, rates, source/price evidence or fingerprints.
     *
     * @return array<string, mixed>
     */
    public function consumerSafe(): array
    {
        return ['calculation_version' => self::VERSION, 'currency_id' => $this->rule->currencyId,
            'lines' => array_map(static fn (array $line): array => ['line_key' => $line['line_key'], 'net' => $line['net'], 'tax' => $line['tax'], 'gross' => $line['gross']], $this->lines),
            'totals' => $this->totals(), 'withholding' => ['certainty' => 'UNKNOWN', 'amount' => null],
            'estimated_payment' => ['certainty' => 'UNKNOWN', 'amount' => null], 'issue_ready' => false, 'issue_blockers' => self::issueBlockers()];
    }

    public function fingerprint(): string
    {
        return OperationIntent::hash('quotation.financial_calculation', $this->toInternalArray());
    }

    /** @return array<string, array{certainty: string, amount: ?string, known_amount: ?string}> */
    private function totals(): array
    {
        $totals = [];
        foreach (['net', 'tax', 'gross'] as $field) {
            $parts = array_map(fn (array $row): FinancialResult => $row[$field] === null
                ? FinancialResult::unknown([['code' => 'LINE_FINANCIAL_FACTS_UNKNOWN']])
                : FinancialResult::complete($row[$field], $this->rule->currencyId), $this->lines);
            $sum = FinancialResult::sum($parts);
            $totals[$field] = ['certainty' => $sum->certainty->value, 'amount' => $sum->amount === null ? null : (string) $sum->amount,
                'known_amount' => $sum->knownAmount === null ? null : (string) $sum->knownAmount];
        }

        return $totals;
    }

    /** @return list<string> */
    private static function issueBlockers(): array
    {
        return ['CALCULATION_ONLY', 'CURRENT_SOURCE_AND_TAX_DETERMINATION_REQUIRED', 'FX_AND_CONTROL_REVALIDATION_REQUIRED',
            'SCOPED_AUTHORITY_AND_APPROVAL_REQUIRED', 'ATOMIC_ISSUE_AND_DOCUMENT_BINDING_REQUIRED'];
    }
}
