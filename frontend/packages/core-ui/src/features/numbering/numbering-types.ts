/**
 * The shapes the Document Numbering API returns (ADR 0079). The API describes these payloads as open objects, so they are named
 * here once and cast in `numbering-api.ts`; keep them in step with `NumberingOverview` and the series controller.
 */
export type ScopePeriodCode = 'NONE' | 'YEAR' | 'MONTH' | 'DAY' | 'FISCAL_YEAR' | 'FISCAL_PERIOD';
export type ContinuityCode = 'GAPLESS' | 'ALLOW_GAPS';

export interface NumberingVersion {
  id: number;
  template: string;
  scope_period: ScopePeriodCode;
  scope_dimensions: string[];
  scope_text: string;
  continuity: ContinuityCode;
  effective_from: string;
  reason: string;
}

export interface NumberingSeries {
  id: number;
  doc_type: string;
  code: string;
  name: string;
  is_default: boolean;
  is_selectable: boolean;
  status: string;
  version: NumberingVersion | null;
  example: string | null;
  warnings: string[];
}

export interface NumberingVariableSupply {
  key: string;
  label: string;
  frozen: boolean;
}

export interface NumberingDocumentType {
  key: string;
  label: string;
  module: string;
  official_when: string;
  manage_permission: string;
  can_manage: boolean;
  continuity_locked: boolean;
  default_template: string;
  default_scope_period: ScopePeriodCode;
  default_continuity: ContinuityCode;
  dimensions: string[];
  routing_attributes: string[];
  variables: NumberingVariableSupply[];
  series: NumberingSeries[];
}

export interface NumberingContextInfo {
  engine_variables: { key: string; label: string; description: string; kind: string }[];
  constants: { code: string; value: string }[];
  timezone: string;
  timezone_is_default: boolean;
}

export interface NumberingPreviewResult {
  document_label: string;
  template: string;
  series: string;
  official_when: string;
  date_basis: string;
  timezone: string;
  local_date?: string | null;
  counter_scope: string;
  reset: string;
  continuity: ContinuityCode;
  continuity_text: string;
  length: number;
  max_length: number;
  errors: string[];
  warnings: string[];
  collisions: string[];
  example: string | null;
  next_numbers: string[];
  first_of_next_period: string | null;
  change_impact: string | null;
}

export interface NumberingHistoryRow {
  id: number;
  template: string;
  scope_text: string;
  continuity: ContinuityCode;
  status: string;
  reason: string;
  effective_from: string;
  effective_to: string | null;
  changed_by_name: string | null;
}

export interface NumberingCounterRow {
  id: number;
  counter_key: string;
  allocator: 'LOCKED' | 'SEQUENCE';
  next_number: number;
  updated_at: string | null;
}

export interface NumberingRuleRow {
  id: number;
  priority: number;
  conditions: { attribute: string; operator: 'EQ' | 'IN'; values: string[] }[];
}

export interface NumberingConstantRow {
  id: number;
  code: string;
  value: string | null;
  status: string;
  reason: string;
  effective_from: string;
  effective_to: string | null;
  changed_by_name: string | null;
}

/** Plain words for how often a counter restarts. */
export const PERIOD_OPTIONS: { value: ScopePeriodCode; label: string; help: string }[] = [
  { value: 'NONE', label: 'Never restart', help: 'One running count for ever.' },
  { value: 'YEAR', label: 'Every calendar year', help: 'Starts again at 1 on the first number issued each year.' },
  { value: 'MONTH', label: 'Every month', help: 'Starts again at 1 each calendar month.' },
  { value: 'DAY', label: 'Every day', help: 'Starts again at 1 each day.' },
  { value: 'FISCAL_YEAR', label: 'Every fiscal year', help: 'Follows the company fiscal calendar.' },
  { value: 'FISCAL_PERIOD', label: 'Every fiscal period', help: 'Follows the company fiscal calendar.' },
];

export const CONTINUITY_LABELS: Record<ContinuityCode, string> = {
  ALLOW_GAPS: 'Allow gaps',
  GAPLESS: 'No gaps',
};

export function periodLabel(period: ScopePeriodCode): string {
  return PERIOD_OPTIONS.find((option) => option.value === period)?.label ?? period;
}
