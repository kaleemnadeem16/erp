import type {
  QuotationDraftEditableResource,
  UpdateQuotationDraftRequest,
  UpdateQuotationDraftRequestAddLinesItem,
  UpdateQuotationDraftRequestUpdateLinesItem,
  StoreQuotationDraftRequest,
} from "@erp/core-api";
import { z } from "zod";

const id = z.number().int().positive().safe();
const line = z.object({
  id,
  scope_ref: z.string().min(1).max(80),
  section_title: z.string().nullable(),
  description: z.string(),
  membership: z.enum(["BASE", "OPTIONAL", "CHOOSE_ONE"]),
  choice_group_ref: z.string().nullable(),
  is_baseline: z.boolean(),
  source_kind: z.enum(["DIRECT_ITEM", "MANUAL"]),
  item_id: id.nullable(),
  manual_measure: z.string().nullable(),
  offered_quantity: z.string(),
  offered_uom_id: id.nullable(),
  unit_price: z.string().nullable(),
  sort_order: z.number().int().nonnegative().safe(),
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
  lines: z.array(line),
});
export type Editable = z.infer<typeof editableSchema>;
export type EditableLine = z.infer<typeof line>;
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
    if (Object.keys(update).length > 1)
      updates.push(update as UpdateQuotationDraftRequestUpdateLinesItem);
  }
  if (
    new Set(values.lines.map((x) => x.id)).size !== values.lines.length ||
    values.lines.length + removed.size !== base.lines.length
  )
    throw new Error("Invalid line set.");
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
