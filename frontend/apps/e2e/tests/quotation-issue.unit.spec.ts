import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  QUOTATION_VIEW,
  QUOTATION_PREPARE,
} from "../../../packages/core-ui/src/features/quotations/quotation-access";
import { QUOTATION_ISSUE } from "../../../packages/core-ui/src/features/quotations/quotation-milestone-access";
import {
  groupBlockers,
  describeBlocker,
  mockIssuedTerms,
  mockReadiness,
  newRequestKey,
  readIssuedTerms,
  readIssueOutcome,
  readReadiness,
} from "../../../packages/core-ui/src/features/quotations/quotation-issue-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-issue-screen") &
    typeof import("../../../packages/core-ui/src/stores/auth-store");
const { loaded, cleanup } = ssrBundle<Screen>(
  "quotation-issue-ssr",
  `export * from "./quotation-issue-screen"; export { useAuthStore } from "../../stores/auth-store";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const {
  QuotationReadinessView,
  QuotationIssuedView,
  IssueConfirmDialog,
  QuotationIssueScreen,
  issueRefusalMessage,
  useAuthStore,
} = loaded;
test.afterAll(cleanup);

const blocker = (
  code: string,
  scope: "HEADER" | "LINE" | "TAX" | "CONTROLS" | "AUTHORITY",
  line_id: number | null = null,
) => ({ code, scope, line_id });
const view = (
  props: Partial<Parameters<typeof QuotationReadinessView>[0]> = {},
) =>
  renderToStaticMarkup(
    createElement(QuotationReadinessView, {
      readiness: mockReadiness(),
      mayIssue: true,
      onIssue: () => undefined,
      ...props,
    }),
  );

test("readiness names every blocker group in plain words and hides raw codes and line ids", () => {
  const readiness = mockReadiness({
    issue_ready: false,
    blockers: [
      blocker("RECIPIENT_REQUIRED", "HEADER"),
      blocker("NO_LINES", "HEADER"),
      blocker("VALID_THROUGH_PAST", "HEADER"),
      blocker("TAX_NOT_DETERMINED", "TAX"),
      blocker("CONTROLS_NOT_CONFIGURED", "CONTROLS"),
      blocker("FX_EVIDENCE_CHANGED", "LINE", 901),
      blocker("FX_EVIDENCE_CHANGED", "LINE", 902),
      blocker("ROUNDING_POLICY_CHANGED", "HEADER"),
      blocker("SOMETHING_NEW_AND_INTERNAL", "TAX"),
    ],
  });
  const html = view({ readiness });
  for (const text of [
    "Not ready",
    "Recipient",
    "Choose who the quotation is for.",
    "Add at least one quotation line.",
    "Validity",
    "The valid-through date has passed.",
    "Tax has not been determined.",
    "A tax check needs attention.",
    "Financial controls have not been configured.",
    "Pricing, exchange rates and rounding",
    "(2 lines)",
    "The rounding policy has changed",
  ])
    expect(html).toContain(text);
  for (const raw of [
    "SOMETHING_NEW_AND_INTERNAL",
    "FX_EVIDENCE_CHANGED",
    "901",
    "902",
  ])
    expect(html).not.toContain(raw);
  expect(html).toMatch(/<button[^>]*\sdisabled=""[^>]*>Issue quotation/);
  expect(groupBlockers([]).length).toBe(0);
  expect(describeBlocker(blocker("X", "AUTHORITY")).group).toBe("authority");
});

test("a ready draft enables Issue; people without the issue duty never see the action", () => {
  const ready = view();
  expect(ready).toContain("Ready to issue");
  expect(ready).not.toMatch(/<button[^>]*\sdisabled=""[^>]*>Issue quotation/);
  const noDuty = view({ mayIssue: false });
  expect(noDuty).not.toContain("Issue quotation");
  expect(noDuty).toContain("issuing needs a separate permission");
});

test("confirmation states the consequence and maps stable refusals without echoing server text", () => {
  const html = renderToStaticMarkup(
    createElement(IssueConfirmDialog, {
      open: false,
      onOpenChange: () => undefined,
      onConfirm: () => undefined,
      pending: false,
      error: null,
    }),
  );
  expect(html).toBe(""); // Closed dialog renders nothing; content is portalled when open.
  const stale = Object.assign(new Error("secret server text"), {
    status: 422,
    body: {
      reason_code: "QUOTATION_DRAFT_STALE",
      message: "generation 5 not 4 SECRET",
    },
  });
  expect(issueRefusalMessage(stale)).toContain("draft changed");
  expect(issueRefusalMessage(stale)).not.toContain("SECRET");
  expect(
    issueRefusalMessage(Object.assign(new Error(), { status: 403 })),
  ).toContain("permission");
  expect(
    issueRefusalMessage(Object.assign(new Error("hidden"), { status: 404 })),
  ).toBe("This quotation is not available.");
  expect(
    issueRefusalMessage(
      Object.assign(new Error(), {
        status: 409,
        body: { reason_code: "QUOTATION_ALREADY_ISSUED" },
      }),
    ),
  ).toContain("already been issued");
  expect(issueRefusalMessage(new Error("boom"))).toBe(
    "The quotation could not be issued. Try again.",
  );
});

test("issued state shows the official number, maker and checker, and frozen terms read only", () => {
  const html = renderToStaticMarkup(
    createElement(QuotationIssuedView, { terms: mockIssuedTerms() }),
  );
  for (const text of [
    "QUO-2026-0007",
    "Prepared by (maker)",
    "Issued by (checker)",
    "Frozen terms",
    "1150.00 · Currency reference #3",
    "Optional net total",
  ])
    expect(html).toContain(text);
  expect(html).not.toContain("<input");
  expect(html).not.toContain("<button");
  expect(html).not.toContain("The same person");
  const same = renderToStaticMarkup(
    createElement(QuotationIssuedView, {
      terms: mockIssuedTerms({ issued_by: 5 }),
    }),
  );
  expect(same).toContain("The same person prepared and issued");
});

test("contracts strip protected extras and reject malformed responses", () => {
  const terms = readIssuedTerms({
    ...mockIssuedTerms(),
    cost_total: "SECRET_COST",
    margin: "SECRET_MARGIN",
    financial_fingerprint: "abc",
  });
  expect(JSON.stringify(terms)).not.toMatch(/SECRET|fingerprint/);
  expect(
    JSON.stringify(readReadiness({ ...mockReadiness(), cost: "SECRET" })),
  ).not.toContain("SECRET");
  expect(() =>
    readReadiness({
      ...mockReadiness(),
      blockers: [{ code: "X", scope: "NOPE", line_id: null }],
    }),
  ).toThrow();
  expect(() => readIssueOutcome({ revision_id: 1 })).toThrow();
  expect(
    readIssueOutcome({
      family_id: 1,
      revision_id: 2,
      issue_id: 3,
      official_number: "Q-1",
      issued_at: "x",
      replayed: true,
    }).replayed,
  ).toBe(true);
});

test("request keys are unique per attempt and non-empty", () => {
  const a = newRequestKey();
  expect(a.length).toBeGreaterThan(8);
  expect(a.length).toBeLessThanOrEqual(120);
  expect(newRequestKey()).not.toBe(a);
});

test("the issue screen is denied in production and without the right permissions", () => {
  const set = (permissions: string[]) =>
    useAuthStore.setState({
      user: {
        id: 1,
        name: "T",
        email: "t@example.test",
        must_change_password: false,
        permissions,
      },
    });
  try {
    // Production gate is off: even a person holding every duty is denied before any query mounts.
    set([QUOTATION_VIEW, QUOTATION_PREPARE, QUOTATION_ISSUE]);
    expect(
      renderToStaticMarkup(
        createElement(QuotationIssueScreen, { revisionId: 12 }),
      ),
    ).toContain("You don’t have access");
    set([]);
    expect(
      renderToStaticMarkup(
        createElement(QuotationIssueScreen, { revisionId: 12 }),
      ),
    ).toContain("You don’t have access");
  } finally {
    useAuthStore.setState({ user: null });
  }
});
