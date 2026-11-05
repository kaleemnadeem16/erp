import { expect, test } from '@playwright/test';

import { accountPayload, validateAccountForm, EMPTY_ACCOUNT_FORM } from '../../../packages/core-ui/src/features/chart-of-accounts/account-rules';
import { pairLabel, RATE_PATTERN, rateTypeLabel, todayIso, validateExchangeRateForm, validateRate } from '../../../packages/core-ui/src/features/exchange-rates/exchange-rate-rules';
import { canAccessScreen } from '../../../packages/core-ui/src/routes/screen-access';
import { SETTINGS_GROUPS } from '../../../packages/core-ui/src/features/settings/settings-catalogue';

test.describe('exchange-rate rules', () => {
  test('the rate pattern is the API pattern: up to 10 digits before the point, 9 after, never signed', () => {
    for (const ok of ['1', '280.25', '0.000000001', '9999999999.999999999', '1.5']) expect(RATE_PATTERN.test(ok), ok).toBe(true);
    for (const bad of ['', '-1', '.5', '1e3', '1,5', '12345678901', '1.1234567891', 'abc']) expect(RATE_PATTERN.test(bad), bad).toBe(false);
  });

  test('a rate must be above zero and says why otherwise; it stays a string', () => {
    expect(validateRate('280.250000000')).toBeUndefined();
    expect(validateRate('')).toMatch(/Enter the rate/);
    expect(validateRate('0')).toMatch(/above zero/);
    expect(validateRate('0.000000000')).toMatch(/above zero/);
    expect(validateRate('1,5')).toMatch(/digits/);
  });

  test('creating needs two different currencies, a date and a valid rate; editing checks only the rate', () => {
    const base = { fromCurrencyId: '1', toCurrencyId: '2', rateDate: '2026-10-01', rateType: 'SPOT' as const, rate: '1.5' };
    expect(validateExchangeRateForm(base, true)).toEqual({});
    expect(validateExchangeRateForm({ ...base, fromCurrencyId: '', toCurrencyId: '' }, true)).toMatchObject({ from_currency_id: expect.any(String), to_currency_id: expect.any(String) });
    expect(validateExchangeRateForm({ ...base, toCurrencyId: '1' }, true).to_currency_id).toMatch(/different/);
    expect(validateExchangeRateForm({ ...base, rateDate: '' }, true).rate_date).toBeTruthy();
    expect(validateExchangeRateForm({ ...base, fromCurrencyId: '', rateDate: '', rate: '2' }, false)).toEqual({});
    expect(validateExchangeRateForm({ ...base, rate: 'x' }, false).rate).toBeTruthy();
  });

  test('labels and the pair read in plain words and never hide a missing relationship', () => {
    expect(rateTypeLabel('AVERAGE')).toBe('Average');
    expect(rateTypeLabel('SOMETHING')).toBe('SOMETHING');
    expect(pairLabel({ code: 'USD' }, { code: 'PKR' })).toBe('USD → PKR');
    expect(pairLabel(undefined, undefined, 3, 4)).toBe('#3 → #4');
    expect(todayIso(new Date(2026, 9, 5))).toBe('2026-10-05');
  });
});

test.describe('chart-of-accounts rules', () => {
  test('code, name and type are required; an account cannot be its own parent', () => {
    expect(Object.keys(validateAccountForm(EMPTY_ACCOUNT_FORM)).sort()).toEqual(['code', 'name', 'type']);
    const ok = { ...EMPTY_ACCOUNT_FORM, code: '1100', name: 'Receivables', type: 'ASSET' as const };
    expect(validateAccountForm(ok)).toEqual({});
    expect(validateAccountForm({ ...ok, parentId: '7' }, 7).parent_id).toMatch(/own parent/);
    expect(validateAccountForm({ ...ok, parentId: '8' }, 7)).toEqual({});
  });

  test('the body clears an emptied parent and currency with null and trims text', () => {
    expect(accountPayload({ ...EMPTY_ACCOUNT_FORM, code: ' 1100 ', name: ' AR ', type: 'ASSET' })).toEqual({ code: '1100', name: 'AR', type: 'ASSET', parent_id: null, is_postable: true, currency_id: null, status: 'ACTIVE' });
    expect(accountPayload({ ...EMPTY_ACCOUNT_FORM, code: 'x', name: 'y', type: 'EXPENSE', parentId: '5', currencyId: '2', isPostable: false, status: 'ARCHIVED' })).toMatchObject({ parent_id: 5, currency_id: 2, is_postable: false, status: 'ARCHIVED' });
  });
});

test.describe('reference-data screen access and settings index', () => {
  const has = (...permissions: string[]) => new Set(permissions);

  test('each screen needs its own maintenance duty (default-deny)', () => {
    expect(canAccessScreen('/settings/exchange-rates', has('currency.exchange-rate.manage'))).toBe(true);
    expect(canAccessScreen('/settings/exchange-rates/new', has('currency.exchange-rate.manage'))).toBe(true);
    expect(canAccessScreen('/settings/exchange-rates', has('accounting.chart-of-account.manage'))).toBe(false);
    expect(canAccessScreen('/settings/chart-of-accounts/5', has('accounting.chart-of-account.manage'))).toBe(true);
    expect(canAccessScreen('/settings/chart-of-accounts', has('currency.exchange-rate.manage'))).toBe(false);
    expect(canAccessScreen('/settings/chart-of-accounts', has())).toBe(false);
  });

  test('both screens are listed in the Settings index with the duty that gates them', () => {
    const entries = SETTINGS_GROUPS.flatMap((group) => group.entries);
    expect(entries.find((entry) => entry.to === '/settings/exchange-rates')?.permission).toBe('currency.exchange-rate.manage');
    expect(entries.find((entry) => entry.to === '/settings/chart-of-accounts')?.permission).toBe('accounting.chart-of-account.manage');
  });
});
