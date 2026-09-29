import type { MaterialVariancePolicyBandResource } from '@erp/core-api';

/**
 * The words the variance screens use for ADR 0054's vocabulary, in one place so the settings screens and
 * the Material Preview describe the same rule the same way.
 */
export const DIRECTION_LABELS: Record<string, string> = { OVER: 'Over plan', UNDER: 'Under plan' };

export const MEASURE_LABELS: Record<string, string> = {
  QUANTITY: 'Quantity',
  PERCENT: '% of the standard quantity',
  COST_AMOUNT: 'Internal cost impact',
  COST_PERCENT: '% of the standard cost',
};

/** The effects a company may choose today. "Approval required" is not offered until the shared approval workflow exists. */
export const EFFECT_OPTIONS = [
  { value: 'AUDIT_ONLY', label: 'Record only' },
  { value: 'REASON_REQUIRED', label: 'Reason required' },
  { value: 'BLOCKED', label: 'Blocked' },
] as const;

export const EFFECT_LABELS: Record<string, string> = {
  AUDIT_ONLY: 'Record only',
  REASON_REQUIRED: 'Reason required',
  APPROVAL_REQUIRED: 'Approval required',
  BLOCKED: 'Blocked',
};

export const REASON_STATUS_LABELS: Record<string, string> = {
  NOT_NEEDED: 'No reason needed',
  PROVIDED: 'Reason given',
  MISSING: 'Reason missing',
  NOT_ACCEPTED: 'Not accepted',
};

/** "Over plan by 2 L or more → Reason required" — one band, in a sentence. */
export function bandSentence(band: Pick<MaterialVariancePolicyBandResource, 'direction' | 'measure' | 'threshold' | 'effect'> & { uom?: { code: string } | null }): string {
  const amount = trimZeros(band.threshold);
  const size =
    band.measure === 'QUANTITY'
      ? `${amount} ${band.uom?.code ?? ''}`.trim()
      : band.measure === 'PERCENT'
        ? `${amount}% of the standard`
        : band.measure === 'COST_PERCENT'
          ? `${amount}% of the standard cost`
          : `${amount} in internal cost`;

  return `${DIRECTION_LABELS[band.direction] ?? band.direction} by ${size} or more → ${EFFECT_LABELS[band.effect] ?? band.effect}`;
}

export function trimZeros(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}
