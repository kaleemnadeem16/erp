/**
 * The decimal contexts the backend enforces (ADR 0141/0142). A context says how many fractional and integer digits a value may carry; it is **not** a
 * display setting and nothing here rounds. Keep these in step with `App\Support\Quantity\QuantityContract` and the Form Request rules: a screen that
 * allows more than the server accepts only moves the refusal to submit time.
 */
export interface DecimalSpec {
  /** Most fractional digits accepted, counted literally as typed (the Form Requests' `decimal:0,N`). */
  readonly scale: number;
  /** Most integer digits accepted (decimal(19, scale) leaves 19 - scale). */
  readonly maxInteger: number;
}

export const DECIMAL_SPECS = {
  /** Stock-ledger and transaction quantities, increments and package content: decimal(19,4). */
  stockLedger: { scale: 4, maxInteger: 15 },
  /** Recipe and Assembly technical quantities and resource rates: decimal(19,6). */
  assemblyTechnical: { scale: 6, maxInteger: 13 },
  /** Unit-conversion and packaging factors (evidence, not a quantity): decimal(19,9). */
  conversionFactor: { scale: 9, maxInteger: 10 },
  /** Stored monetary amounts and purchasing unit prices: decimal(19,4). */
  money: { scale: 4, maxInteger: 15 },
  /** Manual exchange rates: decimal(19,9), at most ten whole digits. */
  fxRate: { scale: 9, maxInteger: 10 },
} as const satisfies Record<string, DecimalSpec>;

export type DecimalContextName = keyof typeof DECIMAL_SPECS;

/**
 * The Sales unit-price *entry* precision is a governed company policy of two to four places (ADR 0144), default four. It limits what may be typed; the
 * stored value stays decimal(19,4) and settlement follows the currency, so this is a separate context from {@link DECIMAL_SPECS.money}.
 */
export function unitPriceSpec(entryScale: number = 4): DecimalSpec {
  const scale = Math.min(4, Math.max(2, Math.trunc(entryScale)));

  return { scale, maxInteger: 15 };
}

export function resolveSpec(spec: DecimalSpec | DecimalContextName): DecimalSpec {
  return typeof spec === 'string' ? DECIMAL_SPECS[spec] : spec;
}
