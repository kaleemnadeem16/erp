import { z } from "zod";
import {
  accessIntentSchema,
  type AccessIntent,
  type FamilyAccess,
  type FamilyShare,
} from "./quotation-family-access-contract";

export type AccessFormMode =
  | { operation: "owner" | "unit" | "grant" }
  | { operation: "revoke"; share: FamilyShare };
export const ACCESS_ACTION_LABELS = {
  owner: "Change responsible owner",
  unit: "Change access unit",
  grant: "Share quotation",
  revoke: "Revoke share",
} as const;

/** Native local date/time input; reject normalised invalid dates and non-existent local clock times. */
export function accessInstant(value: string): string | null {
  if (!value) return null;
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
  const date = new Date(value);
  if (
    !match ||
    date.getFullYear() < 1 ||
    Number.isNaN(date.valueOf()) ||
    date.getFullYear() !== Number(match[1]) ||
    date.getMonth() + 1 !== Number(match[2]) ||
    date.getDate() !== Number(match[3]) ||
    date.getHours() !== Number(match[4]) ||
    date.getMinutes() !== Number(match[5])
  )
    throw new Error("Enter a valid local date and time.");
  return date.toISOString();
}

export const accessFormDraftSchema = z.object({
  candidate: z
    .object({ id: z.number().int().positive(), name: z.string() })
    .nullable(),
  reason: z.string().max(1000),
  clear_unit: z.boolean(),
  level: z.enum(["VIEW", "PREPARE"]),
  starts_at: z.string(),
  ends_at: z.string(),
});
export const savedAccessDraftSchema = z.object({
  fields: accessFormDraftSchema,
  ownership: z.number().int().nonnegative(),
  access: z.number().int().nonnegative(),
  pending: z
    .object({ intent: accessIntentSchema, key: z.string().min(1).max(85) })
    .nullable(),
});
export function accessFormSchema(mode: AccessFormMode, allowPrepare: boolean) {
  return accessFormDraftSchema
    .extend({
      reason: z
        .string()
        .trim()
        .min(1, "Give a reason for this change.")
        .max(1000, "Use at most 1,000 characters."),
    })
    .superRefine((value, context) => {
      if (
        mode.operation !== "revoke" &&
        !(mode.operation === "unit" && value.clear_unit) &&
        value.candidate === null
      )
        context.addIssue({
          code: "custom",
          path: ["candidate"],
          message:
            mode.operation === "unit"
              ? "Choose a unit or explicitly clear it."
              : "Choose a person.",
        });
      if (mode.operation !== "grant") return;
      if (value.level === "PREPARE" && !allowPrepare)
        context.addIssue({
          code: "custom",
          path: ["level"],
          message: "You cannot delegate preparation on this quotation.",
        });
      const instants: (string | null)[] = [];
      for (const field of ["starts_at", "ends_at"] as const) {
        try {
          instants.push(accessInstant(value[field]));
        } catch {
          context.addIssue({
            code: "custom",
            path: [field],
            message: "Enter a valid local date and time.",
          });
          instants.push(null);
        }
      }
      if (instants[0] && instants[1] && instants[1] <= instants[0])
        context.addIssue({
          code: "custom",
          path: ["ends_at"],
          message: "The end must be after the start.",
        });
    });
}
export type AccessFormValues = z.infer<ReturnType<typeof accessFormSchema>>;
export function accessFormDefaults(
  snapshot: FamilyAccess,
  mode: AccessFormMode,
): AccessFormValues {
  const current =
    mode.operation === "owner"
      ? snapshot.responsible_owner
      : mode.operation === "unit"
        ? snapshot.access_unit
        : null;
  return {
    candidate: current ? { id: current.id, name: current.name } : null,
    reason: "",
    clear_unit: snapshot.access_unit === null,
    level: "VIEW",
    starts_at: "",
    ends_at: "",
  };
}
export function mayManageAccess(
  snapshot: FamilyAccess,
  mode: AccessFormMode,
): boolean {
  const rights = snapshot.allowed_actions;
  return mode.operation === "owner" || mode.operation === "unit"
    ? rights.assign
    : mode.operation === "grant"
      ? rights.delegate_view
      : rights.revoke;
}
export function accessIntent(
  snapshot: FamilyAccess,
  mode: AccessFormMode,
  values: AccessFormValues,
): AccessIntent {
  const parsed = accessFormSchema(
    mode,
    snapshot.allowed_actions.delegate_prepare,
  ).parse(values);
  const common = {
    expected_ownership_generation: snapshot.ownership_generation,
    expected_access_generation: snapshot.access_generation,
    reason: parsed.reason,
  };
  switch (mode.operation) {
    case "owner":
      return { ...common, operation: "owner", owner_id: parsed.candidate!.id };
    case "unit":
      return {
        ...common,
        operation: "unit",
        access_unit_id: parsed.clear_unit ? null : parsed.candidate!.id,
      };
    case "grant":
      return {
        ...common,
        operation: "grant",
        recipient_id: parsed.candidate!.id,
        level: parsed.level,
        starts_at: accessInstant(parsed.starts_at),
        ends_at: accessInstant(parsed.ends_at),
      };
    case "revoke":
      return { ...common, operation: "revoke", grant_id: mode.share.id };
  }
}
