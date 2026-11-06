import { expect, test } from '@playwright/test';
import type { CustomerAccountResource, PartyResource } from '@erp/core-api';

import { getCustomerColumns } from '../../../packages/core-ui/src/features/customers/customers-columns';
import {
  CREDIT_LIMIT_PATTERN,
  customerAccountPayload,
  customerAuthority,
  customerOptionsSummary,
  EMPTY_CUSTOMER_FORM,
  suggestPartyCode,
  validateCustomerForm,
} from '../../../packages/core-ui/src/features/customers/customer-rules';
import { partyColumns, partyRoles } from '../../../packages/core-ui/src/features/parties/parties-columns';
import {
  EMPTY_PARTY_IDENTITY,
  partyCreatePayload,
  partyUpdatePayload,
  validatePartyIdentity,
} from '../../../packages/core-ui/src/features/parties/party-identity';
import { canAccessScreen } from '../../../packages/core-ui/src/routes/screen-access';

const grant = (...permissions: string[]) => (permission: string) => permissions.includes(permission);

const ACCOUNT = 'parties.customer-account.manage';
const TERMS = 'parties.payment-terms.manage';
const MAPPING = 'accounting.account-mapping.manage';
const CREDIT = 'parties.credit-limit.manage';

const sample = (overrides: Partial<CustomerAccountResource> = {}): CustomerAccountResource =>
  ({
    id: 7,
    party_id: 3,
    code: 'CUS-ALPHA',
    credit_limit: '500000.0000',
    payment_terms_days: 30,
    tax_code_id: null,
    receivable_account_id: 12,
    default_currency_id: 2,
    custom_fields: {},
    status: 'ACTIVE',
    party: { id: 3, code: 'ALPHA', name: 'Alpha Decorators', tax_id: 'NTN-1' } as PartyResource,
    created_at: '2026-10-01T00:00:00+00:00',
    updated_at: '2026-10-20T10:00:00+00:00',
    ...overrides,
  }) as CustomerAccountResource;

test.describe('customer authority', () => {
  test('creating needs account maintenance AND the two field authorities the API requires', () => {
    expect(customerAuthority(grant(ACCOUNT, TERMS, MAPPING)).create).toBe(true);
    expect(customerAuthority(grant(ACCOUNT, TERMS)).create).toBe(false);
    expect(customerAuthority(grant(ACCOUNT, MAPPING)).create).toBe(false);
    expect(customerAuthority(grant(TERMS, MAPPING)).create).toBe(false);
  });

  test('editing needs account maintenance only; field edits follow their own capability', () => {
    const authority = customerAuthority(grant(ACCOUNT));
    expect(authority.edit).toBe(true);
    expect(authority.editTerms).toBe(false);
    expect(authority.editMapping).toBe(false);
    expect(authority.editCredit).toBe(false);
  });

  test('read of protected fields follows view or manage and manage implies read', () => {
    expect(customerAuthority(grant('parties.credit-limit.view')).viewCredit).toBe(true);
    expect(customerAuthority(grant(CREDIT)).viewCredit).toBe(true);
    expect(customerAuthority(grant()).viewCredit).toBe(false);
    expect(customerAuthority(grant('accounting.account-mapping.view')).viewMapping).toBe(true);
    expect(customerAuthority(grant(MAPPING)).viewMapping).toBe(true);
    expect(customerAuthority(grant()).viewMapping).toBe(false);
  });

  test('registering a new partner is a separate capability', () => {
    expect(customerAuthority(grant(ACCOUNT, TERMS, MAPPING)).registerParty).toBe(false);
    expect(customerAuthority(grant('parties.party.manage')).registerParty).toBe(true);
  });
});

test.describe('customer form validation', () => {
  const full = customerAuthority(grant(ACCOUNT, TERMS, MAPPING, CREDIT));

  test('a complete form has no problems', () => {
    const values = { ...EMPTY_CUSTOMER_FORM, code: 'CUS-1', receivableAccountId: '12' };
    expect(validateCustomerForm(values, full, true)).toEqual({});
  });

  test('requires a code, valid whole-day terms, a clean credit limit and the receivable account', () => {
    const errors = validateCustomerForm({ ...EMPTY_CUSTOMER_FORM, code: '  ', paymentTerms: '3.5', creditLimit: '-5', receivableAccountId: '' }, full, true);
    expect(Object.keys(errors).sort()).toEqual(['code', 'credit_limit', 'payment_terms_days', 'receivable_account_id']);
    expect(validateCustomerForm({ ...EMPTY_CUSTOMER_FORM, code: 'X', paymentTerms: '32768', receivableAccountId: '1' }, full, true).payment_terms_days).toBeTruthy();
    expect(validateCustomerForm({ ...EMPTY_CUSTOMER_FORM, code: 'X', paymentTerms: '32767', receivableAccountId: '1' }, full, true).payment_terms_days).toBeUndefined();
  });

  test('the credit limit pattern is the API pattern: 15 integer digits, 4 decimals, no sign', () => {
    for (const ok of ['0', '1', '500000', '500000.5', '999999999999999.9999']) expect(CREDIT_LIMIT_PATTERN.test(ok), ok).toBe(true);
    for (const bad of ['', '-1', '1e5', '1,000', '.5', '1.12345', '1234567890123456']) expect(CREDIT_LIMIT_PATTERN.test(bad), bad).toBe(false);
  });

  test('without field authority a field is not validated (it is not sent either)', () => {
    const limited = customerAuthority(grant(ACCOUNT));
    expect(validateCustomerForm({ ...EMPTY_CUSTOMER_FORM, code: 'X', paymentTerms: 'abc', creditLimit: 'abc' }, limited, false)).toEqual({});
  });

  test('creating without the mapping authority is refused with a reason rather than a server round trip', () => {
    const noMapping = customerAuthority(grant(ACCOUNT, TERMS));
    expect(validateCustomerForm({ ...EMPTY_CUSTOMER_FORM, code: 'X' }, noMapping, true).receivable_account_id).toMatch(/permission/i);
  });
});

test.describe('customer request body', () => {
  test('protected keys are sent only with their field authority (their mere presence is refused otherwise)', () => {
    const body = customerAccountPayload({ ...EMPTY_CUSTOMER_FORM, code: ' CUS-9 ', paymentTerms: '45', creditLimit: '1000', receivableAccountId: '12' }, customerAuthority(grant(ACCOUNT)), { customFields: {} });
    expect(body).toEqual({ code: 'CUS-9', status: 'ACTIVE', default_currency_id: null, custom_fields: {} });
    expect('payment_terms_days' in body).toBe(false);
    expect('credit_limit' in body).toBe(false);
    expect('receivable_account_id' in body).toBe(false);
  });

  test('with authority the keys are sent as the server expects them, money as a string', () => {
    const body = customerAccountPayload(
      { ...EMPTY_CUSTOMER_FORM, code: 'CUS-9', paymentTerms: '45', creditLimit: '1000.50', receivableAccountId: '12', defaultCurrencyId: '3' },
      customerAuthority(grant(ACCOUNT, TERMS, MAPPING, CREDIT)),
      { customFields: { region: 'North' } },
    );
    expect(body).toMatchObject({ payment_terms_days: 45, credit_limit: '1000.50', receivable_account_id: 12, default_currency_id: 3, custom_fields: { region: 'North' } });
    expect(typeof body.credit_limit).toBe('string');
  });

  test('emptying the credit limit clears it (null = no limit enforced), but only for someone who may change it', () => {
    const withCredit = customerAuthority(grant(ACCOUNT, CREDIT));
    expect(customerAccountPayload({ ...EMPTY_CUSTOMER_FORM, code: 'C', creditLimit: '  ' }, withCredit, { customFields: {} })).toMatchObject({ credit_limit: null });
    expect('credit_limit' in customerAccountPayload({ ...EMPTY_CUSTOMER_FORM, code: 'C', creditLimit: '' }, customerAuthority(grant(ACCOUNT)), { customFields: {} })).toBe(false);
  });

  test('suggestPartyCode is upper-case letters and digits of the name plus a short suffix', () => {
    expect(suggestPartyCode('Alpha Decorators (Pvt) Ltd', 1_700_000_001_234)).toBe('ALPHADEC-1234');
    expect(suggestPartyCode('  ***  ', 99_999)).toBe('PTY-9999');
  });

  test('the closed "More options" disclosure summarises everything set away from the default', () => {
    expect(customerOptionsSummary({ defaultCurrencyId: '', status: 'ACTIVE' }, undefined, 0)).toBe('');
    expect(customerOptionsSummary({ defaultCurrencyId: '2', status: 'ARCHIVED' }, 'PKR', 2)).toBe('PKR, archived, 2 custom fields');
  });
});

test.describe('customer columns', () => {
  test('protected columns do not exist for a reader without the capability (not in cards, menus or print)', () => {
    const none = getCustomerColumns().map((column) => column.key);
    expect(none).not.toContain('credit_limit');
    expect(none).not.toContain('receivable_account');
    const all = getCustomerColumns({ mayViewCredit: true, mayViewMapping: true }).map((column) => column.key);
    expect(all).toContain('credit_limit');
    expect(all).toContain('receivable_account');
  });

  test('identity, status, terms and credit read correctly and credit is never turned into a float', () => {
    const columns = getCustomerColumns({ mayViewCredit: true, mayViewMapping: true, currencyLabels: new Map([[2, 'PKR — Pakistani Rupee']]), accountLabels: new Map([[12, '1200 — Receivables']]) });
    const value = (key: string, row = sample()) => columns.find((column) => column.key === key)!.value(row);
    expect(value('code')).toBe('CUS-ALPHA');
    expect(value('name')).toBe('Alpha Decorators');
    expect(value('status')).toBe('Active');
    expect(value('status', sample({ status: 'ARCHIVED' }))).toBe('Archived');
    expect(value('payment_terms_days')).toBe('30 days');
    expect(value('credit_limit')).toBe('500000.0000');
    expect(value('credit_limit', sample({ credit_limit: null }))).toBe('No limit');
    expect(value('default_currency')).toBe('PKR — Pakistani Rupee');
    expect(value('default_currency', sample({ default_currency_id: null }))).toBe('Company default');
    expect(value('receivable_account')).toBe('1200 — Receivables');
  });

  test('the title and subtitle roles make a usable phone card', () => {
    const columns = getCustomerColumns();
    expect(columns.find((column) => column.cardRole === 'title')?.key).toBe('name');
    expect(columns.find((column) => column.cardRole === 'subtitle')?.key).toBe('code');
    expect(columns.filter((column) => column.sortable).map((column) => column.key)).toEqual(expect.arrayContaining(['code', 'status', 'payment_terms_days', 'updated_at']));
  });
});

test.describe('trading partners', () => {
  test('roles are independent and both, one or none can apply', () => {
    expect(partyRoles({})).toEqual([]);
    expect(partyRoles({ customer_account: {} as never })).toEqual(['Customer']);
    expect(partyRoles({ customer_account: {} as never, supplier_account: {} as never })).toEqual(['Customer', 'Supplier']);
    const roles = partyColumns.find((column) => column.key === 'roles')!;
    expect(roles.value({ customer_account: undefined, supplier_account: undefined } as PartyResource)).toBe('No role yet');
  });

  test('identity validation and payloads', () => {
    expect(validatePartyIdentity(EMPTY_PARTY_IDENTITY).name).toBeTruthy();
    expect(validatePartyIdentity({ ...EMPTY_PARTY_IDENTITY, name: 'Acme' })).toEqual({});
    const created = partyCreatePayload({ ...EMPTY_PARTY_IDENTITY, name: ' Acme Ltd ', taxId: ' T-1 ' });
    expect(created).toMatchObject({ name: 'Acme Ltd', kind: 'ORGANISATION', tax_id: 'T-1', registration_no: null, website: null, custom_fields: {} });
    expect(created.code).toMatch(/^ACMELTD-\d{4}$/);
    // Editing: a blank code keeps the stored one; emptied identifiers are cleared with null.
    const updated = partyUpdatePayload({ ...EMPTY_PARTY_IDENTITY, name: 'Acme', taxId: '' });
    expect('code' in updated).toBe(false);
    expect(updated.tax_id).toBeNull();
  });
});

test.describe('screen access', () => {
  const has = (...permissions: string[]) => new Set(permissions);

  test('Customers is reachable by those who maintain customers/partners or work the pipeline, and by nobody else', () => {
    expect(canAccessScreen('/customers', has(ACCOUNT))).toBe(true);
    expect(canAccessScreen('/customers/5', has('parties.party.manage'))).toBe(true);
    expect(canAccessScreen('/customers', has('sales.opportunity.manage'))).toBe(true);
    expect(canAccessScreen('/customers', has('purchasing.purchase-order.view'))).toBe(false);
    expect(canAccessScreen('/customers', has())).toBe(false);
  });

  test('creating needs all three capabilities, editing needs account maintenance', () => {
    expect(canAccessScreen('/customers/new', has(ACCOUNT, TERMS, MAPPING))).toBe(true);
    expect(canAccessScreen('/customers/new', has(ACCOUNT))).toBe(false);
    expect(canAccessScreen('/customers/5/edit', has(ACCOUNT))).toBe(true);
    expect(canAccessScreen('/customers/5/edit', has('sales.opportunity.manage'))).toBe(false);
  });

  test('Trading partners needs partner or role maintenance; create/edit need partner maintenance', () => {
    expect(canAccessScreen('/parties', has('parties.supplier-account.manage'))).toBe(true);
    expect(canAccessScreen('/parties', has('sales.lead.manage'))).toBe(false);
    expect(canAccessScreen('/parties/new', has('parties.party.manage'))).toBe(true);
    expect(canAccessScreen('/parties/new', has(ACCOUNT))).toBe(false);
    expect(canAccessScreen('/parties/9/edit', has(ACCOUNT))).toBe(false);
    expect(canAccessScreen('/parties/9', has(ACCOUNT))).toBe(true);
  });
});
