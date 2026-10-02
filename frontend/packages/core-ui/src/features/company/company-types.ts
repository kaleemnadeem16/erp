/**
 * The shapes of `GET /api/v1/company` and `/company-changes`, written out by hand because the API's form description is built from a
 * field registry at run time and the generated client types it loosely (ADR 0081). The generated hooks still do the calling.
 */
export type CompanyFieldType = 'TEXT' | 'CODE' | 'EMAIL' | 'URL' | 'PHONE' | 'COUNTRY' | 'TIMEZONE' | 'CURRENCY' | 'MONTH';

export interface CompanyFieldDescription {
  key: string;
  section: string;
  label: string;
  description: string;
  type: CompanyFieldType;
  governed: boolean;
  nullable: boolean;
  max_length: number | null;
  can_edit: boolean;
  locked: boolean;
  locked_reason: string | null;
}

export interface CompanySection {
  key: string;
  label: string;
  description: string;
}

export type CompanyValues = Record<string, string | number | null>;

export interface CompanyView {
  id: number;
  values: CompanyValues;
  base_currency: { id: number; code: string; name: string } | null;
  sections: CompanySection[];
  fields: CompanyFieldDescription[];
  timezone_is_default: boolean;
}

/** Narrows the generated response to the hand-written shape. */
export function asCompanyView(data: unknown): CompanyView | undefined {
  return data && typeof data === 'object' ? (data as CompanyView) : undefined;
}
