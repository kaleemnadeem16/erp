/**
 * Chart-of-accounts pure rules (docs/domain/general-ledger.md). The server is the authority on every one of these; this avoids a request that is certain to be
 * refused and says why beside the field.
 */
export const ACCOUNT_TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE'] as const;
export type AccountTypeValue = (typeof ACCOUNT_TYPES)[number];

const TYPE_LABEL: Record<string, string> = { ASSET: 'Asset', LIABILITY: 'Liability', EQUITY: 'Equity', INCOME: 'Income', EXPENSE: 'Expense' };
export const accountTypeLabel = (type: string): string => TYPE_LABEL[type] ?? type;

export interface AccountFormValues {
  code: string;
  name: string;
  type: AccountTypeValue | '';
  parentId: string;
  isPostable: boolean;
  currencyId: string;
  status: 'ACTIVE' | 'ARCHIVED';
}

export const EMPTY_ACCOUNT_FORM: AccountFormValues = { code: '', name: '', type: '', parentId: '', isPostable: true, currencyId: '', status: 'ACTIVE' };

export type AccountFormErrors = Partial<Record<'code' | 'name' | 'type' | 'parent_id', string>>;

/** `selfId` is the account being edited: it cannot be its own parent. */
export function validateAccountForm(values: AccountFormValues, selfId?: number): AccountFormErrors {
  const errors: AccountFormErrors = {};
  if (!values.code.trim()) errors.code = 'Enter an account code.';
  else if (values.code.trim().length > 255) errors.code = 'The code can be at most 255 characters.';
  if (!values.name.trim()) errors.name = 'Enter the account name.';
  else if (values.name.trim().length > 255) errors.name = 'The name can be at most 255 characters.';
  if (!values.type) errors.type = 'Choose what kind of account this is.';
  if (selfId !== undefined && values.parentId && Number(values.parentId) === selfId) errors.parent_id = 'An account cannot be its own parent.';

  return errors;
}

/** The request body. An emptied parent or currency is sent as `null` so a stored value can be cleared. */
export function accountPayload(values: AccountFormValues) {
  return {
    code: values.code.trim(),
    name: values.name.trim(),
    type: values.type as AccountTypeValue,
    parent_id: values.parentId ? Number(values.parentId) : null,
    is_postable: values.isPostable,
    currency_id: values.currencyId ? Number(values.currencyId) : null,
    status: values.status,
  };
}

export const accountLabel = (account: { code: string; name: string }) => `${account.code} — ${account.name}`;
