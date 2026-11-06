/**
 * Exchange-rate pure rules (ADR 0031 manual entry; docs/domain/multi-currency.md). The server validates all of this too — these only avoid a request that is
 * certain to be refused and say why beside the field. A rate is a decimal **string**: never parsed into a float.
 */
export const RATE_TYPES = ['SPOT', 'AVERAGE', 'CLOSING'] as const;
export type RateTypeValue = (typeof RATE_TYPES)[number];

const RATE_TYPE_LABEL: Record<string, string> = { SPOT: 'Spot', AVERAGE: 'Average', CLOSING: 'Closing' };

/** `SPOT` → `Spot`; an unknown type reads as itself rather than disappearing. */
export const rateTypeLabel = (type: string): string => RATE_TYPE_LABEL[type] ?? type;

/** Up to 10 digits before the point and 9 after, as the API accepts (decimal(19,9)). */
export const RATE_PATTERN = /^(?=\d)\d{1,10}(\.\d{1,9})?$/;

const hasNonZeroDigit = (value: string) => /[1-9]/.test(value);

export function validateRate(raw: string): string | undefined {
  const rate = raw.trim();
  if (rate === '') return 'Enter the rate.';
  if (!RATE_PATTERN.test(rate)) return 'Use digits with at most 10 before the point and 9 after it.';
  if (!hasNonZeroDigit(rate)) return 'The rate must be above zero.';

  return undefined;
}

export interface ExchangeRateFormValues {
  fromCurrencyId: string;
  toCurrencyId: string;
  rateDate: string;
  rateType: RateTypeValue;
  rate: string;
}

export type ExchangeRateFormErrors = Partial<Record<'from_currency_id' | 'to_currency_id' | 'rate_date' | 'rate_type' | 'rate', string>>;

/** Field problems knowable before sending. Editing changes only the rate, so only the rate is checked then. */
export function validateExchangeRateForm(values: ExchangeRateFormValues, creating: boolean): ExchangeRateFormErrors {
  const errors: ExchangeRateFormErrors = {};
  const rate = validateRate(values.rate);
  if (rate) errors.rate = rate;
  if (!creating) return errors;

  if (!values.fromCurrencyId) errors.from_currency_id = 'Choose the currency you are converting from.';
  if (!values.toCurrencyId) errors.to_currency_id = 'Choose the currency you are converting to.';
  else if (values.fromCurrencyId && values.fromCurrencyId === values.toCurrencyId) errors.to_currency_id = 'The two currencies must be different.';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(values.rateDate)) errors.rate_date = 'Enter the date the rate applies from.';

  return errors;
}

/** `USD → PKR`; falls back to the ids so a missing relationship never hides the pair. */
export function pairLabel(from: { code?: string } | null | undefined, to: { code?: string } | null | undefined, fromId?: number, toId?: number): string {
  return `${from?.code ?? `#${fromId ?? '?'}`} → ${to?.code ?? `#${toId ?? '?'}`}`;
}

export const todayIso = (now: Date = new Date()): string => `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
