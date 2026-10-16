/**
 * The stable reason codes the backend returns for a refused quantity, increment, factor or exchange rate (App\Support\Quantity\QuantityRefusal,
 * ManageExchangeRate::validRate). The server's own message stays the primary text; this adds the next step, because several of them are refusals a person
 * can fix only by changing the unit, the quantity or the item set-up.
 */
export const PRECISION_REASON_ADVICE: Readonly<Record<string, string>> = {
  QUANTITY_NOT_REPRESENTABLE: 'The stock ledger holds 4 decimal places and nothing is rounded for you. Change the quantity or the unit so the result is exact.',
  QUANTITY_INCREMENT_VIOLATED: 'This item or package can only be ordered in whole steps of its increment. Use a multiple of it.',
  QUANTITY_OVERFLOW: 'The quantity is larger than the system can hold.',
  QUANTITY_ZERO: 'Enter a quantity greater than zero.',
  QUANTITY_NEGATIVE: 'A quantity cannot be negative here.',
  QUANTITY_INVALID: 'Enter a plain number such as 12 or 12.5.',
  INCREMENT_NOT_REPRESENTABLE: 'An increment cannot be finer than 4 decimal places (once converted, in stock units).',
  INCREMENT_INCOMPATIBLE: 'The increment does not fit this item’s packages or pack units. Change those first, or choose a coarser increment.',
  INCREMENT_INVALID: 'An increment must be a number greater than zero.',
  FACTOR_NOT_REPRESENTABLE: 'A conversion factor has at most 9 decimal places.',
  FACTOR_INVALID: 'A conversion factor must be greater than zero.',
  EXCHANGE_RATE_INVALID: 'An exchange rate is a plain number with at most 10 whole and 9 decimal digits.',
  EXCHANGE_RATE_NOT_POSITIVE: 'An exchange rate must be greater than zero.',
};

export function isPrecisionReason(code: string | null | undefined): code is string {
  return !!code && Object.prototype.hasOwnProperty.call(PRECISION_REASON_ADVICE, code);
}

