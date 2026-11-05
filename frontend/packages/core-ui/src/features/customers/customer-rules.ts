/**
 * The Customers screens' pure rules: which capability does what, what a form may send, and what is wrong with it before it is sent. Kept free of React so it is
 * unit-tested directly (apps/e2e/tests/customers-rules.unit.spec.ts). The server remains the authority on every one of these — this only decides what to show
 * and avoids sending a request that is certain to be refused (docs/domain/parties.md §Access to trading-partner master data, ADR 0084).
 */

export const CUSTOMER_MANAGE_PERMISSIONS = {
  /** Customer-role create / edit / archive. */
  account: 'parties.customer-account.manage',
  /** Shared identity create / edit (name, tax id, registration). */
  party: 'parties.party.manage',
  /** Payment terms — required when an account is created. */
  terms: 'parties.payment-terms.manage',
  /** Receivable control account — required when an account is created. */
  mapping: 'accounting.account-mapping.manage',
  mappingView: 'accounting.account-mapping.view',
  creditView: 'parties.credit-limit.view',
  creditManage: 'parties.credit-limit.manage',
} as const;

export type CustomerStatus = 'ACTIVE' | 'ARCHIVED';

export const customerStatusLabel = (status: string): string => (status === 'ACTIVE' ? 'Active' : status === 'ARCHIVED' ? 'Archived' : status);

/** What this person may do, as one value, from the set of effective permissions (`/api/v1/user`). */
export interface CustomerAuthority {
  /** May open the register-and-create form at all (the API needs terms and the receivable mapping as well as account maintenance). */
  create: boolean;
  /** May edit and archive an existing account. */
  edit: boolean;
  /** May register a brand-new trading partner while creating the customer. */
  registerParty: boolean;
  editTerms: boolean;
  editMapping: boolean;
  editCredit: boolean;
  viewCredit: boolean;
  viewMapping: boolean;
}

export function customerAuthority(has: (permission: string) => boolean): CustomerAuthority {
  const p = CUSTOMER_MANAGE_PERMISSIONS;
  const account = has(p.account);
  const terms = has(p.terms);
  const mapping = has(p.mapping);
  const credit = has(p.creditManage);

  return {
    create: account && terms && mapping,
    edit: account,
    registerParty: has(p.party),
    editTerms: terms,
    editMapping: mapping,
    editCredit: credit,
    viewCredit: credit || has(p.creditView),
    viewMapping: mapping || has(p.mappingView),
  };
}

export interface CustomerFormValues {
  code: string;
  paymentTerms: string;
  creditLimit: string;
  receivableAccountId: string;
  defaultCurrencyId: string;
  status: CustomerStatus;
}

export const EMPTY_CUSTOMER_FORM: CustomerFormValues = {
  code: '',
  paymentTerms: '30',
  creditLimit: '',
  receivableAccountId: '',
  defaultCurrencyId: '',
  status: 'ACTIVE',
};

/** decimal(19,4) as the API accepts it: up to 15 integer digits and 4 decimals, never negative, never a float. */
export const CREDIT_LIMIT_PATTERN = /^\d{1,15}(\.\d{1,4})?$/;

export type CustomerFormErrors = Partial<Record<'code' | 'payment_terms_days' | 'credit_limit' | 'receivable_account_id', string>>;

/** Field problems knowable before sending, in the order a person fixes them. `creating` makes the receivable account and terms mandatory. */
export function validateCustomerForm(values: CustomerFormValues, authority: CustomerAuthority, creating: boolean): CustomerFormErrors {
  const errors: CustomerFormErrors = {};
  if (!values.code.trim()) errors.code = 'Enter a customer code.';
  else if (values.code.trim().length > 255) errors.code = 'The code can be at most 255 characters.';

  if (authority.editTerms) {
    const terms = values.paymentTerms.trim();
    if (!/^\d+$/.test(terms) || Number(terms) > 32767) errors.payment_terms_days = 'Enter whole days between 0 and 32767.';
  }

  if (authority.editCredit && values.creditLimit.trim() !== '' && !CREDIT_LIMIT_PATTERN.test(values.creditLimit.trim())) {
    errors.credit_limit = 'Enter an amount of at most 15 digits and 4 decimals, with no sign.';
  }

  // Creating needs the receivable account (the API requires it); editing only asks for it from someone who may change it.
  if (authority.editMapping && !values.receivableAccountId) {
    errors.receivable_account_id = 'Choose the receivable control account.';
  } else if (creating && !authority.editMapping) {
    errors.receivable_account_id = 'You need permission to set the receivable account before you can create a customer.';
  }

  return errors;
}

/**
 * The body of a create/update request. Protected keys are included **only** when the person holds the field authority, because the API refuses their mere
 * presence otherwise (even unchanged or null); an omitted key keeps its stored value. On create the server needs terms and the receivable account, which
 * `customerAuthority(...).create` already guarantees the caller holds.
 */
export function customerAccountPayload(values: CustomerFormValues, authority: CustomerAuthority, options: { customFields: Record<string, string | number | boolean> }) {
  const credit = values.creditLimit.trim();

  return {
    code: values.code.trim(),
    status: values.status,
    default_currency_id: values.defaultCurrencyId ? Number(values.defaultCurrencyId) : null,
    custom_fields: options.customFields,
    ...(authority.editTerms ? { payment_terms_days: Number(values.paymentTerms) } : {}),
    ...(authority.editMapping && values.receivableAccountId ? { receivable_account_id: Number(values.receivableAccountId) } : {}),
    // An emptied credit limit means "no limit is enforced" (null), which is only sent by someone who may change it.
    ...(authority.editCredit ? { credit_limit: credit === '' ? null : credit } : {}),
  };
}

/** A starting code for a new customer, offered as the field's value: the partner's own code, which a person can change. */
export function suggestCustomerCode(partyCode: string | undefined | null): string {
  return (partyCode ?? '').trim();
}

/** A code for a freshly registered trading partner when the person leaves it blank: upper-case letters and digits of the name plus a short suffix. */
export function suggestPartyCode(name: string, now: number = Date.now()): string {
  const base = name.trim().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8) || 'PTY';

  return `${base}-${String(now).slice(-4)}`;
}

/** What the "More options" disclosure summarises while closed (stored non-default values must not silently vanish). */
export function customerOptionsSummary(values: Pick<CustomerFormValues, 'defaultCurrencyId' | 'status'>, currencyLabel: string | undefined, customFieldCount: number): string {
  const parts: string[] = [];
  if (values.defaultCurrencyId) parts.push(currencyLabel ?? 'default currency');
  if (values.status !== 'ACTIVE') parts.push('archived');
  if (customFieldCount > 0) parts.push(`${customFieldCount} custom ${customFieldCount === 1 ? 'field' : 'fields'}`);

  return parts.join(', ');
}
