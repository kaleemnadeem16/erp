import { expect, test } from "@playwright/test";
import {
  familyAccessSchema,
  accessCandidatePageSchema,
  familyAccessOutcomeSchema,
} from "../../../packages/core-ui/src/features/quotations/quotation-family-access-contract";

const secret = "927361480.4183";
const snapshot = {
  family_id: 11,
  record_scope: "ALL",
  created_by: 12,
  created_by_name: "Original maker",
  responsible_owner: {
    id: 13,
    name: "Current owner",
    status: "SUSPENDED",
    email: "private@example.test",
  },
  access_unit: { id: 14, name: "Main", status: "ACTIVE" },
  baseline_state: "NATIVE",
  ownership_generation: 3,
  access_generation: 7,
  allowed_actions: {
    prepare: false,
    assign: true,
    delegate_view: true,
    delegate_prepare: false,
    revoke: true,
  },
  ownership_history: [
    {
      id: 15,
      generation: 3,
      kind: "REASSIGNED",
      old_owner_id: 12,
      new_owner_id: 13,
      old_unit_id: null,
      new_unit_id: 14,
      actor_id: 16,
      recorded_at: "2026-11-05T00:00:00.000000Z",
      reason: secret,
    },
  ],
  ownership_history_has_more: false,
  ownership_history_next_generation: null,
  shares: [
    {
      id: 17,
      granted_generation: 7,
      recipient_id: 18,
      recipient_name: "Recipient",
      issuer_id: 16,
      level: "VIEW",
      starts_at: "2026-11-05T00:00:00.000000Z",
      ends_at: null,
      recorded_at: "2026-11-05T00:00:00.000000Z",
      revoked_by: null,
      revoked_at: null,
      status: "ACTIVE",
      issuer_authorization_generation: 301,
      reason: secret,
      cost: secret,
    },
  ],
  shares_has_more: false,
  shares_next_generation: null,
};

test("access display boundary retains responsibility and creator separately and strips unprojected secrets", () => {
  expect(JSON.stringify(snapshot)).toContain(secret);
  const parsed = familyAccessSchema.parse(snapshot);
  expect(parsed.created_by).toBe(12);
  expect(parsed.responsible_owner?.id).toBe(13);
  expect(parsed.responsible_owner?.status).toBe("SUSPENDED");
  for (const representation of [
    JSON.stringify(parsed),
    JSON.stringify({ data: parsed }),
    String(Object.keys(parsed.shares?.[0] ?? {})),
  ])
    for (const hidden of [
      secret,
      "issuer_authorization_generation",
      "private@example.test",
      "reason",
      '"cost"',
    ])
      expect(representation).not.toContain(hidden);
});

test("named VIEW never implies prepare or management and arbitrary role titles are ignored", () => {
  const view = familyAccessSchema.parse({
    ...snapshot,
    role: "Manager",
    record_scope: "DELEGATED",
    allowed_actions: {
      prepare: false,
      assign: false,
      delegate_view: false,
      delegate_prepare: false,
      revoke: false,
    },
    ownership_history: undefined,
    shares: undefined,
  });
  expect(view.allowed_actions).toEqual({
    prepare: false,
    assign: false,
    delegate_view: false,
    delegate_prepare: false,
    revoke: false,
  });
  expect(view.ownership_history).toBeUndefined();
  expect(view.shares).toBeUndefined();
  expect(JSON.stringify(view)).not.toContain("Manager");
});

test("malformed rights identities generations and unsupported share levels are refused", () => {
  for (const invalid of [
    { ...snapshot, family_id: -1 },
    { ...snapshot, access_generation: -1 },
    {
      ...snapshot,
      allowed_actions: { ...snapshot.allowed_actions, assign: "true" },
    },
    { ...snapshot, shares: [{ ...snapshot.shares[0], level: "ISSUE" }] },
    { ...snapshot, shares: [{ ...snapshot.shares[0], status: "UNKNOWN" }] },
  ])
    expect(familyAccessSchema.safeParse(invalid).success).toBe(false);
});

test("directory pages retain only identities and an opaque server cursor", () => {
  const page = accessCandidatePageSchema.parse({
    data: [
      {
        id: 2,
        name: "Synthetic person",
        permissions: ["repair"],
        email: secret,
      },
    ],
    meta: { next_cursor: "opaque", prev_cursor: null, total: 1000 },
  });
  expect(page).toEqual({
    data: [{ id: 2, name: "Synthetic person" }],
    meta: { next_cursor: "opaque", prev_cursor: null },
  });
  expect(JSON.stringify(page)).not.toContain(secret);
  expect(
    accessCandidatePageSchema.safeParse({
      data: [],
      meta: { next_cursor: 1, prev_cursor: null },
    }).success,
  ).toBe(false);
});

test("mutation outcomes contain only stable family state and retry facts", () => {
  const result = familyAccessOutcomeSchema.parse({
    family_id: 11,
    ownership_generation: 3,
    access_generation: 8,
    changed: true,
    replayed: true,
    reason: secret,
    issuer_authorization_generation: 301,
  });
  expect(result).toEqual({
    family_id: 11,
    ownership_generation: 3,
    access_generation: 8,
    changed: true,
    replayed: true,
  });
  expect(JSON.stringify(result)).not.toContain(secret);
});

test("creation retains its original capability gate while review and edit require both duties", async () => {
  const {
    quotationAccess,
    quotationCreationAccess,
    QUOTATION_VIEW,
    QUOTATION_PREPARE,
  } =
    await import("../../../packages/core-ui/src/features/quotations/quotation-access");
  expect(quotationCreationAccess(new Set([QUOTATION_PREPARE]))).toBe(false);
  expect(quotationCreationAccess(new Set([QUOTATION_PREPARE]), true)).toBe(
    true,
  );
  expect(quotationCreationAccess(new Set([QUOTATION_VIEW]), true)).toBe(false);
  expect(quotationAccess(new Set([QUOTATION_PREPARE]), true).review).toBe(
    false,
  );
  expect(
    quotationAccess(new Set([QUOTATION_PREPARE, QUOTATION_VIEW]), true).review,
  ).toBe(true);
});

test("access forms require explicit targets and preserve reviewed generations and exact retry intent", async () => {
  const {
    accessFormDefaults,
    accessFormSchema,
    accessIntent,
    savedAccessDraftSchema,
  } =
    await import("../../../packages/core-ui/src/features/quotations/quotation-family-access-form-model");
  const current = familyAccessSchema.parse(snapshot);
  const fields = {
    ...accessFormDefaults(current, { operation: "unit" }),
    candidate: null,
    reason: "  Approved unit recovery  ",
    clear_unit: false,
  };
  expect(
    accessFormSchema({ operation: "unit" }, false).safeParse(fields).success,
  ).toBe(false);
  const intent = accessIntent(
    current,
    { operation: "unit" },
    { ...fields, clear_unit: true },
  );
  expect(intent).toEqual({
    operation: "unit",
    access_unit_id: null,
    reason: "Approved unit recovery",
    expected_ownership_generation: 3,
    expected_access_generation: 7,
  });
  const saved = {
    fields,
    ownership: 3,
    access: 7,
    pending: { intent, key: "same-uncertain-attempt" },
  };
  expect(
    savedAccessDraftSchema.parse(JSON.parse(JSON.stringify(saved))),
  ).toEqual(saved);
  expect(
    savedAccessDraftSchema.safeParse({ ...saved, pending: { intent, key: "" } })
      .success,
  ).toBe(false);
});

test("share form rejects normalised calendar dates, inverted periods and unheld preparation delegation", async () => {
  const { accessInstant, accessFormDefaults, accessFormSchema } =
    await import("../../../packages/core-ui/src/features/quotations/quotation-family-access-form-model");
  const current = familyAccessSchema.parse(snapshot);
  const fields = {
    ...accessFormDefaults(current, { operation: "grant" }),
    candidate: { id: 18, name: "Recipient" },
    reason: "Temporary collaboration",
    starts_at: "2026-11-05T10:00",
    ends_at: "2026-11-06T10:00",
  };
  expect(accessInstant("")).toBeNull();
  expect(accessInstant(fields.starts_at)).toMatch(/^2026-11-05T/);
  for (const invalid of [
    "2026-02-30T10:00",
    "0000-01-01T10:00",
    "2026-11-05T25:00",
    "2026-11-05",
  ])
    expect(() => accessInstant(invalid)).toThrow(
      "Enter a valid local date and time.",
    );
  expect(
    accessFormSchema({ operation: "grant" }, false).safeParse(fields).success,
  ).toBe(true);
  expect(
    accessFormSchema({ operation: "grant" }, false).safeParse({
      ...fields,
      level: "PREPARE",
    }).success,
  ).toBe(false);
  expect(
    accessFormSchema({ operation: "grant" }, true).safeParse({
      ...fields,
      level: "PREPARE",
    }).success,
  ).toBe(true);
  for (const ends_at of [
    fields.starts_at,
    "2026-11-04T10:00",
    "2026-02-30T10:00",
  ])
    expect(
      accessFormSchema({ operation: "grant" }, true).safeParse({
        ...fields,
        ends_at,
      }).success,
    ).toBe(false);
});
