/**
 * The words the approval screens use for ADR 0070's vocabulary, in one place so the inbox, the settings screens and the
 * documents that show an approval describe it the same way.
 */
export const MODE_OPTIONS = [
  { value: 'NONE', label: 'Not used', hint: 'Nothing is ever sent for approval.' },
  { value: 'POLICY', label: 'When a policy applies', hint: 'Approval is asked for only when an active policy matches the document.' },
  { value: 'REQUIRED', label: 'Always required', hint: 'A matching policy must exist; with none, the document cannot proceed.' },
] as const;

export const MODE_LABELS: Record<string, string> = Object.fromEntries(MODE_OPTIONS.map((option) => [option.value, option.label]));

export const REQUEST_STATUS_LABELS: Record<string, string> = {
  PENDING: 'Waiting for approval',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
  WITHDRAWN: 'Withdrawn',
  INVALIDATED: 'No longer valid',
  EXPIRED: 'Expired',
};

export const REQUEST_STATUS_VARIANT: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
  PENDING: 'warning',
  APPROVED: 'success',
  REJECTED: 'secondary',
  WITHDRAWN: 'outline',
  INVALIDATED: 'outline',
  EXPIRED: 'outline',
};

export const STEP_STATUS_LABELS: Record<string, string> = {
  WAITING: 'Not started',
  OPEN: 'Open',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
  CLOSED: 'Closed',
};

export const POLICY_STATUS_LABELS: Record<string, string> = { DRAFT: 'Draft', ACTIVE: 'Active', SUPERSEDED: 'Superseded' };

export const ON_EXPIRY_OPTIONS = [
  { value: 'FLAG_OVERDUE', label: 'Mark it overdue (it can still be decided)' },
  { value: 'EXPIRE_REQUEST', label: 'End the request (it must be submitted again)' },
] as const;

/** "Rs 80,000" style amounts stay as the server sent them; this only trims trailing zeros for reading. */
export function trimZeros(value: string | null | undefined): string {
  if (value === null || value === undefined) return '';
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

/** Group the integer portion for scanning without converting a financial decimal to a float. */
export function readableApprovalAmount(value: string): string {
  const [integer, fraction] = trimZeros(value).split('.');
  return integer!.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction ? `.${fraction}` : '');
}
