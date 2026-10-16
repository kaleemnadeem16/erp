/**
 * Exact decimal-string helpers. Values travel as strings end to end (the API sends and accepts decimal strings, never numbers), and this module never
 * converts one to a JavaScript `number`: comparisons and divisibility work on BigInt-scaled integers, so 0.1 + 0.2 style error cannot occur and a
 * 999999999999999.9999 stays exact.
 */

const PLAIN = /^(-?)(\d+)(?:\.(\d+))?$/;

export interface ParsedDecimal {
  negative: boolean;
  /** Digits before the point, no leading zeros except a single "0". */
  integer: string;
  /** Digits after the point exactly as written (trailing zeros kept). */
  fraction: string;
}

/** Parse a plain decimal string ("12", "-0.50", "280.123456789"); null for anything else (exponent form, padding, a lone point, grouping). */
export function parseDecimal(value: string): ParsedDecimal | null {
  const match = PLAIN.exec(value);
  if (!match) return null;
  const [, sign, integer = '', fraction = ''] = match;

  return { negative: sign === '-', integer: integer.replace(/^0+(?=\d)/, ''), fraction };
}

/** Fractional digits as written ("1.2500" → 4). */
export function fractionDigits(value: string): number {
  return parseDecimal(value)?.fraction.length ?? 0;
}

function scaled(parsed: ParsedDecimal, scale: number): bigint {
  const digits = BigInt(parsed.integer + parsed.fraction.padEnd(scale, '0'));

  return parsed.negative ? -digits : digits;
}

/** -1, 0 or 1; null when either side is not a plain decimal. */
export function compareDecimal(a: string, b: string): -1 | 0 | 1 | null {
  const left = parseDecimal(a);
  const right = parseDecimal(b);
  if (!left || !right) return null;
  const scale = Math.max(left.fraction.length, right.fraction.length);
  const x = scaled(left, scale);
  const y = scaled(right, scale);

  return x === y ? 0 : x < y ? -1 : 1;
}

export function isZeroDecimal(value: string): boolean {
  return compareDecimal(value, '0') === 0;
}

export function isPositiveDecimal(value: string): boolean {
  return compareDecimal(value, '0') === 1;
}

/** Whether `value` is a whole multiple of `increment` (an exact test; a non-positive or malformed increment is never satisfied). */
export function isMultipleOf(value: string, increment: string): boolean {
  const v = parseDecimal(value);
  const step = parseDecimal(increment);
  if (!v || !step) return false;
  const scale = Math.max(v.fraction.length, step.fraction.length);
  const unit = scaled(step, scale);
  if (unit <= 0n) return false;

  return scaled(v, scale) % unit === 0n;
}

/** `a − b` as an exact plain decimal string (the scale of the more precise operand); null when either side is not a plain decimal. */
export function subtractDecimal(a: string, b: string): string | null {
  const left = parseDecimal(a);
  const right = parseDecimal(b);
  if (!left || !right) return null;
  const scale = Math.max(left.fraction.length, right.fraction.length);
  const difference = scaled(left, scale) - scaled(right, scale);

  return compose(difference < 0n ? -difference : difference, scale, difference < 0n);
}

/** Round half away from zero to `scale` fractional digits, as a plain string. This is *presentation* rounding; callers must say they want it. */
export function roundDecimal(value: string, scale: number): string | null {
  const parsed = parseDecimal(value);
  if (!parsed) return null;
  if (parsed.fraction.length <= scale) return value;
  const drop = parsed.fraction.length - scale;
  const digits = BigInt(parsed.integer + parsed.fraction);
  const divisor = 10n ** BigInt(drop);
  const rounded = (digits + divisor / 2n) / divisor;

  return compose(rounded, scale, parsed.negative);
}

/** Rebuild a plain string from an unscaled non-negative integer and a scale. */
function compose(unscaled: bigint, scale: number, negative: boolean): string {
  let text = unscaled.toString();
  if (scale > 0) {
    text = text.padStart(scale + 1, '0');
    text = `${text.slice(0, -scale)}.${text.slice(-scale)}`;
  }
  const isZero = /^0(\.0+)?$/.test(text);

  return negative && !isZero ? `-${text}` : text;
}
