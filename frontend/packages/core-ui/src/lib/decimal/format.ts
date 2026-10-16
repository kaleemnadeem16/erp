import { parseDecimal, roundDecimal } from './arithmetic';

export interface FormatOptions {
  /** Fewest fractional digits shown; trailing zeros are kept up to this many. Default 0. */
  minFraction?: number;
  /** Most fractional digits shown. Fewer than the value carries means *presentation rounding* (half away from zero), reported by the result. Default: all. */
  maxFraction?: number;
  /** Group the integer part ("1,234,567"). Default true. */
  grouping?: boolean;
  /** BCP 47 locale for the group and decimal marks. Default "en-US". */
  locale?: string;
}

export interface FormattedDecimal {
  /** What to show. */
  text: string;
  /** The authoritative value, untouched. */
  exact: string;
  /** True when digits were dropped for display, so the text hides a difference from `exact`. */
  rounded: boolean;
}

const marksCache = new Map<string, { group: string; decimal: string }>();

function marks(locale: string): { group: string; decimal: string } {
  let cached = marksCache.get(locale);
  if (!cached) {
    // Only the *symbols* come from Intl; the digits of the value never pass through a number.
    const parts = new Intl.NumberFormat(locale).formatToParts(1234567.5);
    cached = {
      group: parts.find((part) => part.type === 'group')?.value ?? '',
      decimal: parts.find((part) => part.type === 'decimal')?.value ?? '.',
    };
    marksCache.set(locale, cached);
  }

  return cached;
}

/**
 * Format an exact decimal string for display without ever converting it to a `number`. Anything that is not a plain decimal comes back unchanged
 * (`rounded: false`), so a malformed server value is shown, not hidden. Trailing zeros beyond `minFraction` are trimmed; digits beyond `maxFraction`
 * are rounded half away from zero and flagged, so the caller can mark the figure and keep the exact value reachable.
 */
export function formatDecimalDetailed(value: string | null | undefined, options: FormatOptions = {}): FormattedDecimal {
  if (value === null || value === undefined || value === '') return { text: '', exact: '', rounded: false };
  const parsed = parseDecimal(value);
  if (!parsed) return { text: value, exact: value, rounded: false };
  const minFraction = Math.max(0, options.minFraction ?? 0);
  const maxFraction = Math.max(minFraction, options.maxFraction ?? Number.POSITIVE_INFINITY);

  let shown = value;
  let rounded = false;
  if (parsed.fraction.length > maxFraction) {
    shown = roundDecimal(value, maxFraction) ?? value;
    // Rounding hides something only if a dropped digit was not zero.
    rounded = !/^0*$/.test(parsed.fraction.slice(maxFraction));
  }
  const again = parseDecimal(shown);
  if (!again) return { text: value, exact: value, rounded: false };

  let fraction = again.fraction;
  while (fraction.length > minFraction && fraction.endsWith('0')) fraction = fraction.slice(0, -1);
  fraction = fraction.padEnd(minFraction, '0');

  const symbols = marks(options.locale ?? 'en-US');
  const integer = options.grouping === false ? again.integer : again.integer.replace(/\B(?=(\d{3})+(?!\d))/g, symbols.group);
  const isZero = /^0*$/.test(again.integer) && /^0*$/.test(fraction);
  const sign = again.negative && !isZero ? '-' : '';

  return { text: `${sign}${integer}${fraction ? symbols.decimal + fraction : ''}`, exact: value, rounded };
}

export function formatDecimal(value: string | null | undefined, options: FormatOptions = {}): string {
  return formatDecimalDetailed(value, options).text;
}

export interface CurrencyPlaces {
  decimal_places: number;
  code?: string;
}

/**
 * Money for display, driven by the currency's real minor unit rather than an assumed two decimals. The default shows the stored value (up to the four
 * places storage keeps), never fewer than the minor unit: JPY 1200 → "1,200", USD 12.5 → "12.50", KWD 0.335 → "0.335", and a stored 1.0050 shows
 * "1.005", never "1.01", because it differs from a settled 1.01. `settled: true` rounds to the minor unit and says so (`rounded`), for summaries that want it.
 */
export function formatMoneyDetailed(
  value: string | null | undefined,
  currency: CurrencyPlaces | null | undefined,
  options: { settled?: boolean; locale?: string; grouping?: boolean } = {}
): FormattedDecimal {
  const places = currency ? Math.min(4, Math.max(0, currency.decimal_places)) : 2;

  return formatDecimalDetailed(value, {
    minFraction: places,
    maxFraction: options.settled ? places : 4,
    locale: options.locale,
    grouping: options.grouping,
  });
}

export function formatMoney(
  value: string | null | undefined,
  currency: CurrencyPlaces | null | undefined,
  options: { settled?: boolean; locale?: string; grouping?: boolean } = {}
): string {
  return formatMoneyDetailed(value, currency, options).text;
}

/**
 * A server decimal as the starting text of an edit field: the same number with its padding zeros removed ("0.500000000" → "0.5", "12.5000" → "12.5").
 * The value is unchanged; only the column width the database pads it to is dropped, so an untouched field submits the number it loaded. Anything that
 * is not a plain decimal comes back as is.
 */
export function decimalForEdit(value: string | null | undefined): string {
  return formatDecimal(value, { grouping: false });
}
