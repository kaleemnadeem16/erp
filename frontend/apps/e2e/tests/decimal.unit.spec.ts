import { expect, test } from '@playwright/test';

import {
  checkDecimal,
  compareDecimal,
  decimalField,
  DECIMAL_SPECS,
  describeDecimalRule,
  describePrecisionRefusal,
  formatDecimal,
  formatDecimalDetailed,
  formatMoney,
  formatMoneyDetailed,
  isMultipleOf,
  decimalForEdit,
  roundDecimal,
  subtractDecimal,
  unitPriceSpec,
} from '../../../packages/core-ui/src/lib/decimal';

/** The shared decimal foundation (ADR 0141/0142): exact strings, the backend's contexts, and honest display. No browser needed. */

test.describe('exact decimal strings', () => {
  test('compares and tests divisibility without a float', () => {
    expect(compareDecimal('0.1', '0.10')).toBe(0);
    expect(compareDecimal('999999999999999.9999', '999999999999999.9998')).toBe(1);
    expect(compareDecimal('-1', '0')).toBe(-1);
    expect(compareDecimal('abc', '0')).toBeNull();
    // 0.1 + 0.2 style traps: these are exact, not nearly equal.
    expect(isMultipleOf('0.3', '0.1')).toBe(true);
    expect(isMultipleOf('1.275', '0.001')).toBe(true);
    expect(isMultipleOf('1.2755', '0.001')).toBe(false);
    expect(isMultipleOf('2.25', '0.5')).toBe(false);
    expect(isMultipleOf('7', '0')).toBe(false);
  });

  test('presentation rounding is half away from zero and explicit', () => {
    expect(roundDecimal('1.0050', 2)).toBe('1.01');
    expect(roundDecimal('1.0049', 2)).toBe('1.00');
    expect(roundDecimal('-1.005', 2)).toBe('-1.01');
    expect(roundDecimal('9.9999', 2)).toBe('10.00');
    expect(roundDecimal('1.5', 4)).toBe('1.5');
    expect(roundDecimal('x', 2)).toBeNull();
  });
});

test.describe('input contexts', () => {
  const stock = { spec: 'stockLedger', sign: 'positive', label: 'Quantity' } as const;

  test('four-place stock quantities', () => {
    expect(checkDecimal('12.1234', stock)).toMatchObject({ ok: true, value: '12.1234' });
    expect(checkDecimal('0.0001', stock).ok).toBe(true);
    expect(checkDecimal('999999999999999.9999', stock).ok).toBe(true);
    expect(checkDecimal('12.12345', stock)).toMatchObject({ ok: false, problem: 'TOO_MANY_DECIMALS' });
    expect(checkDecimal('1000000000000000', stock)).toMatchObject({ ok: false, problem: 'TOO_LARGE' });
    expect(checkDecimal('0', stock)).toMatchObject({ ok: false, problem: 'ZERO' });
    expect(checkDecimal('-1', stock)).toMatchObject({ ok: false, problem: 'NEGATIVE' });
  });

  test('six-place technical and nine-place factor and rate contexts', () => {
    expect(checkDecimal('0.000125', { spec: 'assemblyTechnical', sign: 'positive' }).ok).toBe(true);
    expect(checkDecimal('0.0000001', { spec: 'assemblyTechnical', sign: 'positive' }).problem).toBe('TOO_MANY_DECIMALS');
    expect(checkDecimal('6.666666667', { spec: 'conversionFactor', sign: 'positive' }).ok).toBe(true);
    expect(checkDecimal('6.6666666667', { spec: 'conversionFactor', sign: 'positive' }).problem).toBe('TOO_MANY_DECIMALS');
    expect(checkDecimal('280.123456789', { spec: 'fxRate', sign: 'positive' })).toMatchObject({ ok: true, value: '280.123456789' });
    expect(checkDecimal('9999999999.999999999', { spec: 'fxRate', sign: 'positive' }).ok).toBe(true);
    expect(checkDecimal('10000000000', { spec: 'fxRate', sign: 'positive' }).problem).toBe('TOO_LARGE');
    expect(checkDecimal('0.000000000', { spec: 'fxRate', sign: 'positive' }).problem).toBe('ZERO');
  });

  test('sales unit-price entry precision is two to four places and defaults to four', () => {
    expect(unitPriceSpec().scale).toBe(4);
    expect(unitPriceSpec(2).scale).toBe(2);
    expect(unitPriceSpec(1).scale).toBe(2);
    expect(unitPriceSpec(9).scale).toBe(4);
    expect(checkDecimal('125.1234', { spec: unitPriceSpec(4), sign: 'nonNegative' })).toMatchObject({ ok: true, value: '125.1234' });
    expect(checkDecimal('125.1234', { spec: unitPriceSpec(2), sign: 'nonNegative' }).problem).toBe('TOO_MANY_DECIMALS');
    expect(checkDecimal('125.12', { spec: unitPriceSpec(2), sign: 'nonNegative' }).ok).toBe(true);
  });

  test('whole-unit contexts refuse a fraction', () => {
    expect(checkDecimal('2.5', { spec: { scale: 0, maxInteger: 6 }, sign: 'positive', label: 'Count' })).toMatchObject({ ok: false, message: 'Count must be a whole number' });
    expect(checkDecimal('25', { spec: { scale: 0, maxInteger: 6 }, sign: 'positive' }).ok).toBe(true);
  });

  test('invalid text, exponent notation, padding and grouping are refused, never repaired', () => {
    for (const bad of ['abc', '1e3', '1E3', '0x10', '.5', '5.', '1 2', '--1', '+5', '1.2.3', '١٢']) {
      expect(checkDecimal(bad, stock).ok, bad).toBe(false);
    }
    expect(checkDecimal('', stock)).toMatchObject({ ok: false, problem: 'EMPTY' });
    expect(checkDecimal('   ', stock)).toMatchObject({ ok: false, problem: 'EMPTY' });
    expect(checkDecimal('1,234.5', stock)).toMatchObject({ ok: false, problem: 'GROUPING' });
    // Leading and trailing blanks are the only thing trimmed; the digits are returned exactly as typed.
    expect(checkDecimal('  12.50  ', stock)).toMatchObject({ ok: true, value: '12.50' });
    expect(checkDecimal('007', stock)).toMatchObject({ ok: true, value: '007' });
  });

  test('trailing zeros count as typed digits, as the server request rule does', () => {
    expect(checkDecimal('1.50000', stock).problem).toBe('TOO_MANY_DECIMALS');
    expect(checkDecimal('1.5000', stock).ok).toBe(true);
  });

  test('increments are exact and may be undefined or zero', () => {
    const halves = { ...stock, increment: '0.5' };
    expect(checkDecimal('1.5', halves).ok).toBe(true);
    expect(checkDecimal('1.25', halves)).toMatchObject({ ok: false, problem: 'NOT_A_MULTIPLE', message: 'Quantity must be a multiple of 0.5' });
    expect(checkDecimal('1.275', { ...stock, increment: '0.001000000' }).ok).toBe(true);
    expect(checkDecimal('1.2755', { ...stock, increment: '0.001000000' }).problem).toBe('NOT_A_MULTIPLE');
    expect(checkDecimal('1.25', { ...stock, increment: null }).ok).toBe(true);
    expect(checkDecimal('1.25', { ...stock, increment: '0' }).ok).toBe(true);
  });

  test('large decimals stay exact through check, compare and submit', () => {
    const value = '999999999999999.9999';
    expect(checkDecimal(value, { spec: 'money', sign: 'positive' }).value).toBe(value);
    expect(formatDecimal(value, { minFraction: 2, maxFraction: 4 })).toBe('999,999,999,999,999.9999');
    expect(formatDecimal('123456789012345.1234', { minFraction: 2, maxFraction: 4 })).toBe('123,456,789,012,345.1234');
  });

  test('a rule describes itself for titles and help text', () => {
    expect(describeDecimalRule({ spec: 'stockLedger' })).toBe('Up to 4 decimal places');
    expect(describeDecimalRule({ spec: 'stockLedger', increment: '0.500000000' })).toBe('Up to 4 decimal places, in steps of 0.5');
    expect(describeDecimalRule({ spec: { scale: 0, maxInteger: 3 } })).toBe('Whole numbers only');
    expect(describeDecimalRule({ spec: { scale: 1, maxInteger: 3 } })).toBe('Up to 1 decimal place');
  });

  test('the zod field refuses what the server refuses and keeps the optional blank', () => {
    const required = decimalField({ spec: 'stockLedger', sign: 'positive', label: 'Quantity' });
    expect(required.safeParse('12.1234').success).toBe(true);
    const refused = required.safeParse('12.12345');
    expect(refused.success).toBe(false);
    expect(refused.error?.issues[0]?.message).toBe('Quantity must have at most 4 decimal places');
    expect(decimalField({ spec: 'stockLedger', optional: true }).safeParse('').success).toBe(true);
    expect(decimalField({ spec: 'stockLedger' }).safeParse('').success).toBe(false);
    expect(DECIMAL_SPECS.stockLedger).toEqual({ scale: 4, maxInteger: 15 });
  });
});

test.describe('display', () => {
  test('groups with the locale marks without touching the digits', () => {
    expect(formatDecimal('1234567.5', { minFraction: 2 })).toBe('1,234,567.50');
    expect(formatDecimal('1234567.5', { minFraction: 2, locale: 'de-DE' })).toBe('1.234.567,50');
    expect(formatDecimal('1234.5', { grouping: false })).toBe('1234.5');
    expect(formatDecimal('0.5')).toBe('0.5');
    expect(formatDecimal('12.5000')).toBe('12.5');
    expect(formatDecimal('12.5000', { minFraction: 4 })).toBe('12.5000');
    expect(formatDecimal('-0.0000')).toBe('0');
    expect(formatDecimal('-1234.5')).toBe('-1,234.5');
    expect(formatDecimal('')).toBe('');
    expect(formatDecimal(null)).toBe('');
    expect(formatDecimal('not a number')).toBe('not a number');
  });

  test('a shortened display is flagged and keeps the exact value', () => {
    const shown = formatDecimalDetailed('125.1234', { maxFraction: 2 });
    expect(shown).toEqual({ text: '125.12', exact: '125.1234', rounded: true });
    expect(formatDecimalDetailed('125.1200', { maxFraction: 2 })).toEqual({ text: '125.12', exact: '125.1200', rounded: false });
    expect(formatDecimalDetailed('1.0050', { maxFraction: 2 }).text).toBe('1.01');
    expect(formatDecimalDetailed('9.9999', { maxFraction: 2 }).text).toBe('10');
    expect(formatDecimalDetailed('9.9999', { minFraction: 2, maxFraction: 2 }).text).toBe('10.00');
  });

  test('money follows the currency minor unit, not an assumed two decimals', () => {
    expect(formatMoney('1200', { decimal_places: 0 })).toBe('1,200');
    expect(formatMoney('12.5', { decimal_places: 2 })).toBe('12.50');
    expect(formatMoney('0.335', { decimal_places: 3 })).toBe('0.335');
    expect(formatMoney('12', { decimal_places: 3 })).toBe('12.000');
    expect(formatMoney('1.2345', { decimal_places: 4 })).toBe('1.2345');
    expect(formatMoney('5', null)).toBe('5.00');
  });

  test('stored precision beyond the minor unit stays visible unless a settled view is asked for', () => {
    expect(formatMoney('1.0050', { decimal_places: 2 })).toBe('1.005');
    expect(formatMoney('3.0150', { decimal_places: 2 })).toBe('3.015');
    const settled = formatMoneyDetailed('1.0050', { decimal_places: 2 }, { settled: true });
    expect(settled).toEqual({ text: '1.01', exact: '1.0050', rounded: true });
    expect(formatMoneyDetailed('12.50', { decimal_places: 2 }, { settled: true }).rounded).toBe(false);
    expect(formatMoneyDetailed('1200.4', { decimal_places: 0 }, { settled: true }).text).toBe('1,200');
  });
});

test.describe('server refusals', () => {
  const refusal = (code: string, message: string) => Object.assign(new Error(message), { status: 422, body: { message, reason_code: code } });

  test('a precision reason code adds the next step to the server message', () => {
    const text = describePrecisionRefusal(refusal('QUANTITY_NOT_REPRESENTABLE', 'The quantity needs more than 4 decimal places and is not rounded.'));
    expect(text).toContain('The quantity needs more than 4 decimal places and is not rounded.');
    expect(text).toContain('nothing is rounded for you');
    expect(describePrecisionRefusal(refusal('EXCHANGE_RATE_NOT_POSITIVE', 'An exchange rate must be greater than zero.'))).toContain('greater than zero');
  });

  test('anything else is left to the screen’s normal handling', () => {
    expect(describePrecisionRefusal(refusal('NEEDS_COUNT_FIRST', 'Count first.'))).toBeNull();
    expect(describePrecisionRefusal(new Error('network'))).toBeNull();
    expect(describePrecisionRefusal(null)).toBeNull();
  });
});

test.describe('subtraction and edit values', () => {
  test('subtracts exactly, keeping the finer scale and the sign', () => {
    expect(subtractDecimal('10.5', '0.0001')).toBe('10.4999');
    expect(subtractDecimal('1', '2.25')).toBe('-1.25');
    expect(subtractDecimal('0.1', '0.1')).toBe('0.0');
    expect(subtractDecimal('x', '1')).toBeNull();
  });

  test('an edit value drops the server padding and grouping but never a significant digit', () => {
    expect(decimalForEdit('0.500000000')).toBe('0.5');
    expect(decimalForEdit('1234.5000')).toBe('1234.5');
    expect(decimalForEdit('280.123456789')).toBe('280.123456789');
    expect(decimalForEdit(null)).toBe('');
  });
});
