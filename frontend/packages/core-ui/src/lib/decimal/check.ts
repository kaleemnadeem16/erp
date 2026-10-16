import { isMultipleOf, isPositiveDecimal, isZeroDecimal, parseDecimal } from './arithmetic';
import { resolveSpec, type DecimalContextName, type DecimalSpec } from './specs';

export type DecimalProblem =
  | 'EMPTY'
  | 'NOT_A_NUMBER'
  | 'GROUPING'
  | 'NEGATIVE'
  | 'ZERO'
  | 'TOO_MANY_DECIMALS'
  | 'TOO_LARGE'
  | 'NOT_A_MULTIPLE';

export interface DecimalRule {
  spec: DecimalSpec | DecimalContextName;
  /** positive: greater than zero; nonNegative: zero allowed; any: a sign is allowed (adjustments). */
  sign?: 'positive' | 'nonNegative' | 'any';
  /** A permitted increment: the value must be a whole multiple of it. */
  increment?: string | null;
  /** The noun used in messages ("Quantity"). */
  label?: string;
}

export interface DecimalCheck {
  ok: boolean;
  /** The value exactly as it should be submitted (trimmed, otherwise untouched); null when it is not acceptable. */
  value: string | null;
  problem?: DecimalProblem;
  message?: string;
}

const GROUPED = /^-?\d{1,3}(,\d{3})+(\.\d+)?$/;

/**
 * Judge a typed value against a decimal rule, the same way the server's Form Request and quantity contract will (ADR 0142). It never rewrites the
 * value: an acceptable one is returned as typed, an unacceptable one is refused with the reason. Leading/trailing blanks are the only thing trimmed.
 */
export function checkDecimal(raw: string, rule: DecimalRule): DecimalCheck {
  const spec = resolveSpec(rule.spec);
  const label = rule.label ?? 'Value';
  const sign = rule.sign ?? 'nonNegative';
  const text = raw.trim();
  const refuse = (problem: DecimalProblem, message: string): DecimalCheck => ({ ok: false, value: null, problem, message });

  if (text === '') return refuse('EMPTY', `${label} is required`);
  if (GROUPED.test(text)) return refuse('GROUPING', `${label}: remove the thousands separators (type 1234.5, not 1,234.5)`);
  const parsed = parseDecimal(text);
  if (!parsed) return refuse('NOT_A_NUMBER', `${label} must be a plain number such as 12 or 12.5 (digits and one decimal point)`);
  if (parsed.negative && sign !== 'any') return refuse('NEGATIVE', `${label} cannot be negative`);
  if (parsed.fraction.length > spec.scale) {
    return refuse('TOO_MANY_DECIMALS', spec.scale === 0
      ? `${label} must be a whole number`
      : `${label} must have at most ${spec.scale} decimal place${spec.scale === 1 ? '' : 's'}`);
  }
  if (parsed.integer.length > spec.maxInteger) return refuse('TOO_LARGE', `${label} is too large (at most ${spec.maxInteger} digits before the decimal point)`);
  if (sign === 'positive' && !isPositiveDecimal(text)) return refuse('ZERO', `${label} must be greater than zero`);
  if (rule.increment && !isZeroDecimal(rule.increment) && !isMultipleOf(text, rule.increment)) {
    return refuse('NOT_A_MULTIPLE', `${label} must be a multiple of ${rule.increment.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')}`);
  }

  return { ok: true, value: text };
}

/** A one-line description of what a rule accepts, for placeholders, titles and help text ("Up to 4 decimal places"). */
export function describeDecimalRule(rule: Pick<DecimalRule, 'spec' | 'increment'>): string {
  const spec = resolveSpec(rule.spec);
  const places = spec.scale === 0 ? 'Whole numbers only' : `Up to ${spec.scale} decimal place${spec.scale === 1 ? '' : 's'}`;
  const step = rule.increment && !isZeroDecimal(rule.increment) ? `, in steps of ${rule.increment.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')}` : '';

  return `${places}${step}`;
}
