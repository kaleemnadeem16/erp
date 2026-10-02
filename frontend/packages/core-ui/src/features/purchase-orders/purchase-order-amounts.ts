/**
 * Exact money arithmetic for the on-screen estimate of a purchase order being typed.
 *
 * It mirrors the server's rule for a line — quantity × unit price, less the line's fixed discount, plus its fixed
 * tax, rounded half-up to four decimals — using whole-number arithmetic (BigInt) so no floating-point error can
 * creep in. It is an *estimate*: the server works out the saved amounts, and the screen says so wherever it shows
 * these.
 */

const SCALE = 4;
const UNIT = 10n ** BigInt(SCALE);

/** "12.5" -> 125000n (four decimals). Null for anything that is not a plain non-negative decimal of at most four places. */
export function parseDecimal(text: string | undefined): bigint | null {
  const value = (text ?? "").trim();
  if (!/^\d+(\.\d{1,4})?$/.test(value)) return null;
  const [whole = "0", fraction = ""] = value.split(".");
  return BigInt(whole) * UNIT + BigInt(fraction.padEnd(SCALE, "0"));
}

export interface LineAmounts {
  /** Quantity × unit price, rounded to four decimals. */
  gross: bigint;
  discount: bigint;
  tax: bigint;
  /** gross − discount + tax. */
  total: bigint;
}

/** Null while any of the four typed values is missing or not a valid amount (so a half-typed line is never summed). */
export function lineAmounts(line: {
  ordered_qty?: string;
  unit_price?: string;
  discount_amount?: string;
  tax_amount?: string;
}): LineAmounts | null {
  const quantity = parseDecimal(line.ordered_qty);
  const price = parseDecimal(line.unit_price);
  const discount = parseDecimal(line.discount_amount ?? "0");
  const tax = parseDecimal(line.tax_amount ?? "0");
  if (quantity === null || price === null || discount === null || tax === null) return null;

  // quantity × price carries eight decimals; the server keeps them until the final rounding.
  const exactGross = quantity * price;
  const exactTotal = exactGross - discount * UNIT + tax * UNIT;
  const round = (value: bigint) => (value + UNIT / 2n) / UNIT;
  return { gross: round(exactGross), discount, tax, total: round(exactTotal) };
}

/** 2500000n -> "250.00": thousands separators, at least two decimals, more only when the value needs them. */
export function formatScaled(value: bigint): string {
  const negative = value < 0n;
  const abs = negative ? -value : value;
  const whole = (abs / UNIT).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  let fraction = (abs % UNIT).toString().padStart(SCALE, "0").replace(/0+$/, "");
  if (fraction.length < 2) fraction = fraction.padEnd(2, "0");
  return `${negative ? "-" : ""}${whole}.${fraction}`;
}
