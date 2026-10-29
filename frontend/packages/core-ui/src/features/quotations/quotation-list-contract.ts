import { z } from "zod";
import type { QuotationDraftResource } from "@erp/core-api";

// The list endpoint is not delivered. Its proposed rows match the existing generated
// detail summary. Validate a safe projection and strip unrecognised data before display.
const id = z.number().int().positive().safe();
const nullableId = id.nullable();
const summary = z.object({
  revision_id: id,
  family_id: id,
  revision_ordinal: id,
  state: z.enum(["DRAFT", "CANCELLED_DRAFT", "ISSUED"]),
  input_generation: z.number().int().nonnegative().safe(),
  recipient_party_id: nullableId,
  currency_id: id,
  pricing_date: z.string().nullable(),
  tax_estimate_date: z.string().nullable(),
  valid_through_local_date: z.string().nullable(),
  current_opportunity_id: nullableId,
  line_count: z.number().int().nonnegative().safe(),
  created_by: id,
});
export type QuotationListSummary = z.infer<typeof summary>;
export function readQuotationListSummaries(
  rows: QuotationDraftResource[],
): QuotationListSummary[] {
  return z.array(summary).parse(rows);
}
