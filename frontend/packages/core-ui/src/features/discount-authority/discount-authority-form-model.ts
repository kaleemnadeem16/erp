import type {
  AssignmentInput,
  BandInput,
  PolicyInput,
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
    errors.role_id = "Enter a role reference number or leave it empty.";
  if (form.unit_id.trim() !== "" && !positive(form.unit_id))
    errors.unit_id = "Enter a unit reference number or leave it empty.";
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
