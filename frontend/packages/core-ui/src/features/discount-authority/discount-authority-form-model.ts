import type {
  AssignmentInput,
  BandInput,
  PolicyInput,
  ReferenceInput,
  SourceKind,
} from "./discount-authority-contract";

/** Form state is text, validated here the way the server will (a convenience; the server stays authoritative). */
export const CEILINGS = [
  "line_percent",
  "line_base_amount",
  "document_percent",
  "document_base_amount",
  "input_percent",
  "input_base_amount",
] as const;
export type Ceiling = (typeof CEILINGS)[number];

export interface BandForm {
  key: number;
  rank: string;
  label: string;
  exception_only: boolean;
  limits: Record<Ceiling, string>;
}
export interface PolicyForm {
  code: string;
  priority: string;
  operation: PolicyInput["operation"];
  enforcement: PolicyInput["enforcement"];
  effective_from: string;
  effective_to: string;
  expiry_calendar_days: string;
  allow_higher_approver: boolean;
  bands: BandForm[];
  reason: string;
}
export interface AssignmentForm {
  user_id: string;
  band_id: string;
  purpose: AssignmentInput["purpose"];
  role_id: string;
  unit_id: string;
  effective_from: string;
  effective_to: string;
  reason: string;
}
export type FormErrors = Record<string, string>;

const emptyLimits = (): Record<Ceiling, string> => ({
  line_percent: "",
  line_base_amount: "",
  document_percent: "",
  document_base_amount: "",
  input_percent: "",
  input_base_amount: "",
});
let nextKey = 1;
export const emptyBand = (rank = ""): BandForm => ({
  key: nextKey++,
  rank,
  label: "",
  exception_only: false,
  limits: emptyLimits(),
});
/** Empty by design: no limit, person or date is ever pre-filled with a real-looking value. */
export const emptyPolicyForm = (today: string): PolicyForm => ({
  code: "",
  priority: "0",
  operation: "ANY",
  enforcement: "BOTH",
  effective_from: today,
  effective_to: "",
  expiry_calendar_days: "14",
  allow_higher_approver: false,
  bands: [emptyBand("1")],
  reason: "",
});
export const emptyAssignmentForm = (today: string): AssignmentForm => ({
  user_id: "",
  band_id: "",
  purpose: "ROUTINE",
  role_id: "",
  unit_id: "",
  effective_from: today,
  effective_to: "",
  reason: "",
});

const WHOLE = /^[0-9]+$/;
const SIGNED_WHOLE = /^-?[0-9]+$/;
const DECIMAL = /^\d+(\.\d{1,4})?$/;
const CODE = /^[a-z0-9][a-z0-9_.-]*$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

export const CEILING_LABEL: Record<Ceiling, string> = {
  line_percent: "Line discount limit (%)",
  line_base_amount: "Line discount limit (amount)",
  document_percent: "Document discount limit (%)",
  document_base_amount: "Document discount limit (amount)",
  input_percent: "Input reduction limit (%)",
  input_base_amount: "Input reduction limit (amount)",
};

export function validatePolicyForm(
  form: PolicyForm,
): { input: PolicyInput; reason: string } | { errors: FormErrors } {
  const errors: FormErrors = {};
  const code = form.code.trim();
  if (!CODE.test(code) || code.length > 80)
    errors.code =
      "Use lower-case letters, digits, dots, dashes or underscores (up to 80), starting with a letter or digit.";
  if (!SIGNED_WHOLE.test(form.priority.trim()))
    errors.priority = "Enter a whole number.";
  const days = Number(form.expiry_calendar_days);
  if (!WHOLE.test(form.expiry_calendar_days.trim()) || days < 1 || days > 365)
    errors.expiry_calendar_days = "Enter a whole number of days from 1 to 365.";
  if (!DATE.test(form.effective_from)) errors.effective_from = "Choose a start date.";
  if (form.effective_to !== "") {
    if (!DATE.test(form.effective_to)) errors.effective_to = "Choose a valid end date.";
    else if (form.effective_to <= form.effective_from)
      errors.effective_to = "The end date must be after the start date.";
  }
  if (form.reason.trim() === "") errors.reason = "Give a reason.";
  if (form.bands.length === 0) errors.bands = "Add at least one authority level.";
  const ranks = new Set<string>();
  const bands: BandInput[] = [];
  form.bands.forEach((band, index) => {
    const at = (field: string) => `bands.${index}.${field}`;
    if (!WHOLE.test(band.rank.trim())) errors[at("rank")] = "Enter a whole number, 0 or more.";
    else if (ranks.has(String(Number(band.rank))))
      errors[at("rank")] = "Each level needs its own rank.";
    else ranks.add(String(Number(band.rank)));
    if (band.label.trim() === "" || band.label.length > 100)
      errors[at("label")] = "Name this level (up to 100 characters).";
    const limits = {} as Record<Ceiling, string | null>;
    let any = false;
    for (const ceiling of CEILINGS) {
      const text = band.exception_only ? "" : band.limits[ceiling].trim();
      if (text === "") {
        limits[ceiling] = null;
        continue;
      }
      any = true;
      if (!DECIMAL.test(text))
        errors[at(ceiling)] = "Enter a number with at most four decimal places.";
      else if (ceiling.endsWith("_percent") && Number(text) > 100)
        errors[at(ceiling)] = "A percentage can’t be more than 100.";
      limits[ceiling] = text;
    }
    if (!band.exception_only) {
      if (!any) errors[at("limits")] = "A routine level needs at least one limit.";
      else {
        const line = limits.line_percent !== null || limits.line_base_amount !== null;
        const doc = limits.document_percent !== null || limits.document_base_amount !== null;
        if (form.enforcement !== "DOCUMENT" && !line)
          errors[at("limits")] = "Set a line limit, because this policy checks lines.";
        else if (form.enforcement !== "LINE" && !doc)
          errors[at("limits")] = "Set a document limit, because this policy checks the document.";
      }
    }
    bands.push({
      rank: Number(band.rank),
      label: band.label.trim(),
      exception_only: band.exception_only,
      ...limits,
    });
  });
  if (Object.keys(errors).length > 0) return { errors };
  return {
    reason: form.reason.trim(),
    input: {
      code,
      priority: Number(form.priority),
      operation: form.operation,
      enforcement: form.enforcement,
      effective_from: form.effective_from,
      effective_to: form.effective_to === "" ? null : form.effective_to,
      expiry_calendar_days: days,
      allow_higher_approver: form.allow_higher_approver,
      bands,
    },
  };
}

export function validateAssignmentForm(
  form: AssignmentForm,
): { input: AssignmentInput; reason: string } | { errors: FormErrors } {
  const errors: FormErrors = {};
  const positive = (text: string) => /^[1-9]\d*$/.test(text.trim());
  if (!positive(form.user_id)) errors.user_id = "Choose a person.";
  if (!positive(form.band_id)) errors.band_id = "Choose an authority level.";
  if (form.role_id.trim() !== "" && !positive(form.role_id))
    errors.role_id = "Choose a role or leave it empty.";
  if (form.unit_id.trim() !== "" && !positive(form.unit_id))
    errors.unit_id = "Choose a unit or leave it empty.";
  if (!DATE.test(form.effective_from)) errors.effective_from = "Choose a start date.";
  if (form.effective_to !== "") {
    if (!DATE.test(form.effective_to)) errors.effective_to = "Choose a valid end date.";
    else if (form.effective_to <= form.effective_from)
      errors.effective_to = "The end date must be after the start date.";
  }
  if (form.reason.trim() === "") errors.reason = "Give a reason.";
  if (Object.keys(errors).length > 0) return { errors };
  return {
    reason: form.reason.trim(),
    input: {
      user_id: Number(form.user_id),
      band_id: Number(form.band_id),
      purpose: form.purpose,
      role_id: form.role_id.trim() === "" ? null : Number(form.role_id),
      unit_id: form.unit_id.trim() === "" ? null : Number(form.unit_id),
      effective_from: form.effective_from,
      effective_to: form.effective_to === "" ? null : form.effective_to,
    },
  };
}

export interface ReferenceForm {
  code: string;
  source_kind: SourceKind;
  source_key: string;
  source_version: string;
  /** "item" prices one item (and optionally a unit); "manual" is an independently governed project baseline with its own measure. */
  basis: "item" | "manual";
  item_id: string;
  uom_id: string;
  customer_id: string;
  family_id: string;
  manual_measure: string;
  currency_id: string;
  unit_price: string;
  priority: string;
  effective_from: string;
  effective_to: string;
  reason: string;
}
export const emptyReferenceForm = (today: string): ReferenceForm => ({
  code: "",
  source_kind: "PRICE_LIST",
  source_key: "",
  source_version: "",
  basis: "item",
  item_id: "",
  uom_id: "",
  customer_id: "",
  family_id: "",
  manual_measure: "",
  currency_id: "",
  unit_price: "",
  priority: "0",
  effective_from: today,
  effective_to: "",
  reason: "",
});

/** Mirrors the server's reference rules (a convenience; the server stays authoritative). */
export function validateReferenceForm(
  form: ReferenceForm,
): { input: ReferenceInput; reason: string } | { errors: FormErrors } {
  const errors: FormErrors = {};
  const positive = (text: string) => /^[1-9]\d*$/.test(text.trim());
  const code = form.code.trim();
  if (!CODE.test(code) || code.length > 80)
    errors.code =
      "Use lower-case letters, digits, dots, dashes or underscores (up to 80), starting with a letter or digit.";
  if (form.source_key.trim() === "" || form.source_key.length > 160)
    errors.source_key = "Name the source document (up to 160 characters).";
  if (form.source_version.trim() === "" || form.source_version.length > 80)
    errors.source_version = "Give the source version (up to 80 characters).";
  const manual = form.basis === "manual";
  if (manual) {
    if (form.source_kind !== "PROJECT_BASELINE")
      errors.source_kind = "Only a project baseline can be priced by a manual measure.";
    if (form.manual_measure.trim() === "" || form.manual_measure.length > 80)
      errors.manual_measure = "Describe the measure, for example “per square metre” (up to 80 characters).";
    if (!positive(form.family_id))
      errors.family_id = "Enter the quotation family reference number this baseline is for.";
  } else if (!positive(form.item_id)) errors.item_id = "Choose an item.";
  if (!manual && form.family_id.trim() !== "" && !positive(form.family_id))
    errors.family_id = "Enter a reference number or leave it empty.";
  if (form.uom_id.trim() !== "" && !positive(form.uom_id))
    errors.uom_id = "Choose a unit or leave it empty.";
  if (form.source_kind === "CUSTOMER_AGREEMENT" && !positive(form.customer_id))
    errors.customer_id = "A customer agreement must name its customer.";
  if (form.customer_id.trim() !== "" && !positive(form.customer_id))
    errors.customer_id = "Choose a customer or leave it empty.";
  if (!positive(form.currency_id)) errors.currency_id = "Choose a currency.";
  const price = form.unit_price.trim();
  if (!DECIMAL.test(price))
    errors.unit_price = "Enter a number with at most four decimal places.";
  else if (Number(price) === 0)
    errors.unit_price = "A reference price must be more than zero.";
  if (!SIGNED_WHOLE.test(form.priority.trim()))
    errors.priority = "Enter a whole number.";
  if (!DATE.test(form.effective_from)) errors.effective_from = "Choose a start date.";
  if (form.effective_to !== "") {
    if (!DATE.test(form.effective_to)) errors.effective_to = "Choose a valid end date.";
    else if (form.effective_to <= form.effective_from)
      errors.effective_to = "The end date must be after the start date.";
  }
  if (form.reason.trim() === "") errors.reason = "Give a reason.";
  if (Object.keys(errors).length > 0) return { errors };
  const optional = (text: string) => (text.trim() === "" ? null : Number(text));
  return {
    reason: form.reason.trim(),
    input: {
      code,
      source_kind: form.source_kind,
      source_key: form.source_key.trim(),
      source_version: form.source_version.trim(),
      family_id: optional(form.family_id),
      customer_id: optional(form.customer_id),
      item_id: manual ? null : optional(form.item_id),
      manual_measure: manual ? form.manual_measure.trim() : null,
      uom_id: optional(form.uom_id),
      currency_id: Number(form.currency_id),
      unit_price: price,
      effective_from: form.effective_from,
      effective_to: form.effective_to === "" ? null : form.effective_to,
      priority: Number(form.priority),
    },
  };
}
