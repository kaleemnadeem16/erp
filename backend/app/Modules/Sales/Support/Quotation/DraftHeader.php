<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\Currency;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Support\Status;

/**
 * The typed header fields of a draft and their validation, shared by create and update. Only these keys exist: an unknown key (a client-supplied status,
 * approval, base amount or cost) is refused, never ignored. Absent means "not mentioned"; an explicit null clears a clearable field.
 */
final class DraftHeader
{
    public const FIELDS = ['recipient_party_id', 'currency_id', 'pricing_date', 'tax_estimate_date', 'valid_through_local_date'];

    private const DATES = ['pricing_date', 'tax_estimate_date', 'valid_through_local_date'];

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $extra  further keys the caller handles itself (create takes `opportunity_id`)
     * @return array<string, int|string|null> the mentioned fields, validated and normalised
     */
    public static function parse(array $input, array $extra = []): array
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, [...self::FIELDS, ...$extra], true)) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, "[{$key}] is not a field of a quotation draft.", (string) $key);
            }
        }
        $header = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($value === null) {
                if ($field === 'currency_id') {
                    throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A quotation always has a currency.', $field);
                }
                $header[$field] = null;

                continue;
            }
            if (in_array($field, self::DATES, true)) {
                if (! is_string($value)) {
                    throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A date is written YYYY-MM-DD.', $field);
                }
                FinancialDate::assertCalendarDate($value, $field);
                $header[$field] = $value;

                continue;
            }
            if (! is_int($value) || $value < 1) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'An identifier is a positive whole number.', $field);
            }
            $header[$field] = $value;
        }

        return $header;
    }

    /**
     * The references must exist in this company (a foreign id is simply not found), the Party must be active and the currency settleable within decimal(19,4).
     *
     * @param  array<string, int|string|null>  $header
     */
    public static function verifyReferences(array $header): void
    {
        $party = $header['recipient_party_id'] ?? null;
        if ($party !== null && Party::query()->whereKey($party)->where('status', Status::Active->value)->doesntExist()) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'The recipient Party was not found or is archived.', 'recipient_party_id');
        }
        if (($header['currency_id'] ?? null) !== null) {
            $currency = Currency::query()->find($header['currency_id']);
            if ($currency === null) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'The currency was not found.', 'currency_id');
            }
            CurrencyQuantum::forCurrency($currency);
        }
    }
}
