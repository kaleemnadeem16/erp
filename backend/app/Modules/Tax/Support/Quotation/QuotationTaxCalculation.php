<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Quotation;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Tax\Support\TaxRefusal;
use JsonSerializable;

/** Immutable computed evidence. No legal/issue authority, profile facts, rates or base-currency conversion is implied. */
final readonly class QuotationTaxCalculation implements JsonSerializable
{
    /** @param array<string,mixed> $safe @param array<string,mixed> $internal */
    public function __construct(private array $safe, private array $internal) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->safe;
    }

    /** Server-owned snapshot consumer only. @return array<string,mixed> */
    public function internalEvidence(): array
    {
        return $this->internal;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return $this->safe;
    }

    /** Header is the sum of settled lines, never a settlement of aggregate raw tax.
     * @param list<self> $lines */
    public static function sum(array $lines): self
    {
        if ($lines === []) {
            throw TaxRefusal::because('TAX_NO_LINES', 'No lines is not known zero Tax.');
        }
        usort($lines, static fn (self $a, self $b): int => strcmp((string) $a->safe['line_reference'], (string) $b->safe['line_reference']));
        $currency = (int) $lines[0]->internal['currency_id'];
        $company = (int) $lines[0]->internal['company_id'];
        $seen = [];
        $sums = [];
        foreach ($lines as $line) {
            foreach (['currency_id', 'company_id', 'currency_places', 'tax_date', 'tax_generation'] as $field) {
                if (($line->internal[$field] ?? null) !== ($lines[0]->internal[$field] ?? null)) {
                    throw TaxRefusal::because('TAX_CONTEXT_MISMATCH', 'Tax line contexts cannot be summed.');
                }
            }
            $id = (string) $line->safe['line_reference'];
            if (isset($seen[$id])) {
                throw TaxRefusal::because('TAX_LINE_DUPLICATE', 'A Tax line can be counted once.');
            }
            $seen[$id] = true;
            foreach (['net', 'tax', 'gross'] as $kind) {
                /** @var array{certainty:string,amount:?string} $fact */
                $fact = $line->safe[$kind];
                $sums[$kind][] = $fact['certainty'] === 'COMPLETE' ? FinancialResult::complete((string) $fact['amount'], $currency) : FinancialResult::unknown([['code' => 'TAX_CONTEXT_UNKNOWN']]);
            }
        }
        $safe = ['currency_id' => $currency, 'issue_ready' => false, 'payment' => ['certainty' => 'UNKNOWN', 'amount' => null],
            'lines' => array_map(static fn (self $line): array => $line->jsonSerialize(), $lines)];
        foreach ($sums as $kind => $values) {
            $sum = FinancialResult::sum($values);
            if ($sum->amount !== null) {
                StorageAmount::of($sum->amount);
            }
            $safe[$kind] = ['certainty' => $sum->certainty->value, 'amount' => $sum->amount === null ? null : (string) $sum->amount,
                'known_amount' => $sum->knownAmount === null ? null : (string) $sum->knownAmount];
        }

        return new self($safe, ['company_id' => $company, 'currency_id' => $currency, 'calculation_version' => 'FR3.1', 'basis' => 'SUM_OF_SETTLED_LINE_COMPONENTS',
            'lines' => array_map(static fn (self $line): array => $line->internalEvidence(), $lines)]);
    }
}
