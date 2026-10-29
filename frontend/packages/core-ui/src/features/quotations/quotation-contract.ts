import { z } from "zod";
import type {
  QuotationDraftResource,
  QuotationPreviewResource,
} from "@erp/core-api";

// The generated Resources are unknown dictionaries. Validate their safe projections at
// the display boundary; do not cast arbitrary response fields into financial facts.
const id = z.number().int().positive();
const decimal = z.string().regex(/^\d+(?:\.\d+)?$/);
const date = z.string().nullable();
export const draftSchema = z.object({
  revision_id: id,
  family_id: id,
  revision_ordinal: id,
  state: z.string(),
  input_generation: z.number().int().nonnegative(),
  recipient_party_id: id.nullable(),
  currency_id: id,
  pricing_date: date,
  tax_estimate_date: date,
  valid_through_local_date: date,
  current_opportunity_id: id.nullable(),
  line_count: z.number().int().nonnegative(),
  created_by: id,
});
export const amountSchema = z
  .object({
    certainty: z.enum(["COMPLETE", "PARTIAL", "UNKNOWN"]),
    amount: decimal.nullable(),
    known_amount: decimal.nullable(),
    currency_id: id.nullable(),
    reasons: z.array(z.string()),
  })
  .superRefine((value, context) => {
    if (
      (value.certainty === "COMPLETE" && value.amount === null) ||
      (value.certainty !== "COMPLETE" && value.amount !== null) ||
      (value.certainty === "PARTIAL" && value.known_amount === null)
    ) {
      context.addIssue({
        code: "custom",
        message: "Inconsistent financial certainty",
      });
    }
  });
const measure = z.object({ quantity: decimal, uom_id: id.nullable() });
const line = z.object({
  id,
  logical_scope_id: id,
  sort_order: z.number().int(),
  section_title: z.string().nullable(),
  description: z.string().nullable(),
  membership: z.enum(["BASE", "OPTIONAL", "CHOOSE_ONE"]),
  choice_group_id: id.nullable(),
  is_baseline: z.boolean(),
  source_kind: z.string(),
  item_id: id.nullable(),
  manual_measure: z.string().nullable(),
  offered: measure,
  priced: measure.nullable(),
  stock: measure
    .extend({ conversion_version: z.number().nullable() })
    .nullable(),
  unit_price: decimal.nullable(),
  base_unit_price: decimal.nullable(),
  net: amountSchema,
  issues: z.array(z.string()),
  fx: z
    .object({
      current: z.string(),
      currency_id: id,
      base_currency_id: id,
      route: z.unknown(),
      ratio: z.unknown(),
      fingerprint: z.string(),
    })
    .nullable(),
  settlement: z
    .object({
      computed: z.string().nullable(),
      computed_fraction: z.object({
        numerator: z.string(),
        denominator: z.string(),
      }),
      residual: z.string(),
    })
    .optional(),
});
export const previewSchema = z.object({
  revision_id: id,
  family_id: id,
  input_generation: z.number().int().nonnegative(),
  authority: z.literal("PREVIEW_ONLY"),
  currency_id: id,
  pricing_date: date,
  lines: z.array(line),
  totals: z.object({
    net: amountSchema,
    optional_net: amountSchema.nullable(),
  }),
  tax: z.object({
    certainty: z.literal("UNKNOWN"),
    reasons: z.array(z.string()),
    total_including_tax: z.object({
      certainty: z.literal("UNKNOWN"),
      amount: z.null(),
    }),
  }),
  controls: z.object({
    evaluated: z.number(),
    status: z.string(),
    blocks_issue: z.boolean().nullable(),
  }),
  issue_ready: z.literal(false),
  issue_blockers: z.array(z.string()),
  issues: z.array(z.string()),
  rounding_policy: z
    .object({
      source: z.string(),
      version_no: z.number().nullable(),
      unit_price_scale: z.number(),
      rounding_mode: z.string(),
      settlement_basis: z.string(),
      document_net: z.string(),
      calculation_version: z.string(),
    })
    .nullable(),
  revalidation: z
    .object({
      requires_review: z.boolean(),
      reasons: z.array(z.string()),
      non_conforming_lines: z.array(id),
    })
    .nullable(),
});
export type QuotationDraft = z.infer<typeof draftSchema>;
export type QuotationPreview = z.infer<typeof previewSchema>;
export type QuotationAmount = z.infer<typeof amountSchema>;
export function readDraft(value: QuotationDraftResource) {
  return draftSchema.parse(value);
}
export function readPreview(value: QuotationPreviewResource) {
  return previewSchema.parse(value);
}
export function revisionId(value: string | undefined): number | null {
  if (!value || !/^[1-9]\d*$/.test(value)) return null;
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) ? parsed : null;
}
