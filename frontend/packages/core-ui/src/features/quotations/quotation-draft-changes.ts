import type {
  QuotationDraftEditableResource,
  UpdateQuotationDraftRequest,
  UpdateQuotationDraftRequestAddLinesItem,
  UpdateQuotationDraftRequestUpdateLinesItem,
  StoreQuotationDraftRequest,
} from "@erp/core-api";
import { z } from "zod";

const id = z.number().int().positive().safe();
const discount = z.object({
  kind: z.enum(["PERCENT", "PER_PRICED_UNIT", "PER_LINE"]),
  value: z.string(),
});
const override = z.object({ amount: z.string(), reason: z.string() });
const documentDiscount = z.object({
  kind: z.enum(["PERCENT", "AMOUNT"]),
  value: z.string(),
  approval_required: z.boolean().optional(),
  approval_reason: z.string().nullable().optional(),
});
const taxDeclaration = z.object({
  version_no: z.number().int().positive(),
  nature: z.enum(["GOODS", "SERVICES"]),
  classification: z.string(),
  reason: z.string(),
});
/** What the seller is stating now; saved as the next version of the line's declaration. */
export type TaxDeclarationInput = {
  nature: "GOODS" | "SERVICES";
  classification: string;
  reason: string;
};
export type LineDiscount = z.infer<typeof discount>;
export type LineOverride = z.infer<typeof override>;
export type DocumentDiscount = z.infer<typeof documentDiscount>;
const line = z.object({
  id,
  scope_ref: z.string().min(1).max(80),
  section_title: z.string().nullable(),
  description: z.string(),
  membership: z.enum(["BASE", "OPTIONAL", "CHOOSE_ONE"]),
  choice_group_ref: z.string().nullable(),
  is_baseline: z.boolean(),
  source_kind: z.enum(["DIRECT_ITEM", "SERVICE_ITEM", "ASSEMBLY_WORK", "MANUAL"]),
  item_id: id.nullable(),
  assembly_version_id: id.nullable().default(null),
  manual_measure: z.string().nullable(),
  offered_quantity: z.string(),
  offered_uom_id: id.nullable(),
  unit_price: z.string().nullable(),
  sort_order: z.number().int().nonnegative().safe(),
  discount: discount.nullable().default(null),
  net_override: override.nullable().default(null),
  tax_declaration: taxDeclaration.nullable().default(null),
  approval_required: z.boolean().default(false),
  approval_reason: z.string().nullable().default(null),
});
export const editableSchema = z.object({
  revision_id: id,
  family_id: id,
  state: z.literal("DRAFT"),
  input_generation: z.number().int().nonnegative().safe(),
  recipient_party_id: id.nullable(),
  currency_id: id,
  pricing_date: z.string().nullable(),
  tax_estimate_date: z.string().nullable(),
  valid_through_local_date: z.string().nullable(),
  current_opportunity_id: id.nullable(),
  document_discount: documentDiscount.nullable().default(null),
  lines: z.array(line),
});
export type Editable = z.infer<typeof editableSchema>;
export type EditableLine = z.infer<typeof line> & {
  /** Client-only: a declaration the person has typed and not saved yet. */
  tax_declaration_change?: TaxDeclarationInput | null;
};
export type NewLine = UpdateQuotationDraftRequestAddLinesItem & {
  client_id: string;
};
export type DraftValues = {
  header: Pick<
    Editable,
    | "recipient_party_id"
    | "currency_id"
    | "pricing_date"
    | "tax_estimate_date"
    | "valid_through_local_date"
  >;
  lines: EditableLine[];
  added: NewLine[];
  removed: number[];
  document_discount: DocumentDiscount | null;
};

export function readEditable(value: QuotationDraftEditableResource): Editable {
  return editableSchema.parse(value);
}
export function valuesFromEditable(value: Editable): DraftValues {
  return {
    header: {
      recipient_party_id: value.recipient_party_id,
      currency_id: value.currency_id,
      pricing_date: value.pricing_date,
      tax_estimate_date: value.tax_estimate_date,
      valid_through_local_date: value.valid_through_local_date,
    },
    lines: value.lines.map((x) => ({ ...x })),
    added: [],
    removed: [],
    document_discount: value.document_discount,
  };
}
const headerFields = [
  "recipient_party_id",
  "currency_id",
  "pricing_date",
  "tax_estimate_date",
  "valid_through_local_date",
] as const;
const lineFields = [
  "section_title",
  "description",
  "membership",
  "choice_group_ref",
  "is_baseline",
  "item_id",
  "manual_measure",
  "offered_quantity",
  "offered_uom_id",
  "unit_price",
  "sort_order",
] as const;
/** Derive only explicitly changed fields. Scope and source identity of existing lines are never rewritten. */
export function buildChanges(
  base: Editable,
  values: DraftValues,
): UpdateQuotationDraftRequest {
  const result: UpdateQuotationDraftRequest = {
    expected_generation: base.input_generation,
  };
  for (const key of headerFields)
    if (values.header[key] !== base[key])
      Object.assign(result, { [key]: values.header[key] });
  const removed = new Set(values.removed);
  if (
    removed.size !== values.removed.length ||
    values.removed.some((id) => !base.lines.some((line) => line.id === id))
  )
    throw new Error("Invalid removed lines.");
  const updates: UpdateQuotationDraftRequestUpdateLinesItem[] = [];
  for (const current of values.lines) {
    const prior = base.lines.find((x) => x.id === current.id);
    if (!prior || removed.has(current.id))
      throw new Error("Invalid changed lines.");
    const update: Record<string, unknown> = { id: current.id };
    for (const key of lineFields)
      if (current[key] !== prior[key]) update[key] = current[key];
    if (current.tax_declaration_change) {
      const t = current.tax_declaration_change;
      const now = prior.tax_declaration;
      // Restating what is in force changes nothing; anything else is the next version, and it needs a reason.
      if (
        !now ||
        now.nature !== t.nature ||
        now.classification !== t.classification.trim()
      )
        update.tax_declaration = {
          nature: t.nature,
          classification: t.classification.trim(),
          reason: t.reason.trim(),
        };
    }
    // Concessions are objects: compare by content, and send null to remove one.
    for (const key of ["discount", "net_override"] as const)
      if (JSON.stringify(current[key]) !== JSON.stringify(prior[key]))
        update[key] = current[key];
    if (Object.keys(update).length > 1)
      updates.push(update as UpdateQuotationDraftRequestUpdateLinesItem);
  }
  if (
    new Set(values.lines.map((x) => x.id)).size !== values.lines.length ||
    values.lines.length + removed.size !== base.lines.length
  )
    throw new Error("Invalid line set.");
  const sameDocument =
    JSON.stringify(
      values.document_discount && {
        kind: values.document_discount.kind,
        value: values.document_discount.value,
      },
    ) ===
    JSON.stringify(
      base.document_discount && {
        kind: base.document_discount.kind,
        value: base.document_discount.value,
      },
    );
  if (!sameDocument)
    Object.assign(result, {
      document_discount:
        values.document_discount && {
          kind: values.document_discount.kind,
          value: values.document_discount.value,
        },
    });
  if (updates.length) result.update_lines = updates;
  if (values.removed.length) result.remove_lines = values.removed;
  if (values.added.length)
    result.add_lines = values.added.map(
      ({ client_id: _clientId, ...rest }) => rest,
    );
  return result;
}
export function hasChanges(changes: UpdateQuotationDraftRequest): boolean {
  return Object.keys(changes).length > 1;
}
export type PendingAttempt =
  | { key: string; kind: "create"; payload: StoreQuotationDraftRequest }
  | {
      key: string;
      kind: "edit";
      revision: number;
      payload: UpdateQuotationDraftRequest;
    };
export function newAttemptKey(): string {
  return crypto.randomUUID();
}
