import {
  useNumberingConstantsIndex,
  useNumberingContext,
  useNumberingDocumentTypesIndex,
  useNumberingSeriesCounters,
  useNumberingSeriesRules,
  useNumberingSeriesVersions,
} from '@erp/core-api';

import type {
  NumberingConstantRow,
  NumberingContextInfo,
  NumberingCounterRow,
  NumberingDocumentType,
  NumberingHistoryRow,
  NumberingRuleRow,
} from './numbering-types';

/** The generated client types these payloads as open objects; they are named and cast here, once. */
function rows<T>(result: { data?: { status: number; data: unknown } } | undefined): T[] {
  return result?.data?.status === 200 ? ((result.data.data as { data: unknown }).data as T[]) : [];
}

function one<T>(result: { data?: { status: number; data: unknown } } | undefined): T | undefined {
  return result?.data?.status === 200 ? ((result.data.data as { data: unknown }).data as T) : undefined;
}

export function useNumberingTypes() {
  const query = useNumberingDocumentTypesIndex();
  return { query, types: rows<NumberingDocumentType>(query as never) };
}

export function useNumberingInfo() {
  const query = useNumberingContext();
  return { query, info: one<NumberingContextInfo>(query as never) };
}

export function useNumberingConstants() {
  const query = useNumberingConstantsIndex();
  return { query, constants: rows<NumberingConstantRow>(query as never) };
}

export function useSeriesHistory(seriesId: number | undefined, enabled: boolean) {
  const query = useNumberingSeriesVersions(seriesId ?? 0, { query: { enabled: enabled && seriesId !== undefined } });
  return { query, history: rows<NumberingHistoryRow>(query as never) };
}

export function useSeriesCounters(seriesId: number | undefined, enabled: boolean) {
  const query = useNumberingSeriesCounters(seriesId ?? 0, { query: { enabled: enabled && seriesId !== undefined } });
  return { query, counters: rows<NumberingCounterRow>(query as never) };
}

export function useSeriesRules(seriesId: number | undefined, enabled: boolean) {
  const query = useNumberingSeriesRules(seriesId ?? 0, { query: { enabled: enabled && seriesId !== undefined } });
  return { query, rules: rows<NumberingRuleRow>(query as never) };
}
