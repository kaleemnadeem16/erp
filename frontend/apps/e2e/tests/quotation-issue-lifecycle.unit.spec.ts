import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  availabilityView,
  describeBlocker,
  mockIssuedTerms,
  mockReadiness,
  readIssuedTerms,
} from "../../../packages/core-ui/src/features/quotations/quotation-issue-contract";
import {
  mockSummary,
  readListPage,
} from "../../../packages/core-ui/src/features/quotations/quotation-list-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-issue-screen") &
    typeof import("../../../packages/core-ui/src/features/quotations/quotation-list-screen") & {
      MemoryRouter: typeof import("react-router").MemoryRouter;
    };
const { loaded, cleanup } = ssrBundle<Screen>(
  "quotation-issue-lifecycle-ssr",
  `export * from "./quotation-issue-screen"; export { quotationListColumns } from "./quotation-list-screen"; export { MemoryRouter } from "react-router";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const {
  QuotationIssuedView,
  QuotationIssueOutcomeView,
  QuotationReadinessView,
  quotationListColumns,
  MemoryRouter,
} = loaded;
test.afterAll(cleanup);

const inRouter = (node: ReturnType<typeof createElement>) =>
  renderToStaticMarkup(createElement(MemoryRouter, null, node));

// Every code the backend can emit as a blocker (assessor, FR3 tax provider, FR4 controls provider) has plain wording, never the raw code.
const EMITTED = [
  ["RECIPIENT_REQUIRED", "HEADER"],
  ["RECIPIENT_NOT_ACTIVE", "HEADER"],
  ["PRICING_DATE_REQUIRED", "HEADER"],
  ["VALID_THROUGH_REQUIRED", "HEADER"],
  ["VALID_THROUGH_PAST", "HEADER"],
  ["NO_LINES", "HEADER"],
  ["ROUNDING_POLICY_CHANGED", "HEADER"],
  ["UNIT_PRICE_PRECISION_EXCEEDED", "LINE"],
  ["TAX_NOT_DETERMINED", "TAX"],
  ["TAX_ESTIMATE_DATE_REQUIRED", "TAX"],
  ["TAX_JURISDICTION_UNKNOWN", "TAX"],
  ["TAX_CLASSIFICATION_REQUIRED", "TAX"],
  ["TAX_NET_UNKNOWN", "TAX"],
  ["TAX_CONTEXT_UNKNOWN", "TAX"],
  ["TAX_CALCULATION_UNSUPPORTED", "TAX"],
  ["TAX_CONTEXT_NOT_AUTHORISED", "TAX"],
  ["TAX_GENERATION_MOVED", "TAX"],
  ["CONTROLS_NOT_CONFIGURED", "CONTROLS"],
  ["CONTROLS_NO_MANDATORY_CONTROL", "CONTROLS"],
  ["CONTROLS_NO_LINES", "CONTROLS"],
  ["CONTROLS_NET_UNKNOWN", "CONTROLS"],
  ["CONTROL_FAILED", "CONTROLS"],
  ["CONTROL_UNEVALUABLE", "CONTROLS"],
] as const;

test("every emitted blocker code has its own plain wording in the right group", () => {
  const seen = new Set<string>();
  for (const [code, scope] of EMITTED) {
    const view = describeBlocker({ code, scope, line_id: null });
    expect(view.label).not.toContain(code);
    expect(view.label.length).toBeGreaterThan(10);
    // A specific label, not the generic by-scope fallback.
    expect(view.label).not.toMatch(/needs attention\.$/);
    seen.add(view.label);
  }
  expect(seen.size).toBe(EMITTED.length);
  expect(
    describeBlocker({ code: "TAX_NOT_DETERMINED", scope: "TAX", line_id: null })
      .group,
  ).toBe("tax");
  expect(
    describeBlocker({ code: "CONTROL_FAILED", scope: "CONTROLS", line_id: 4 })
      .group,
  ).toBe("controls");
});

test("the readiness page lists tax and control blockers without raw codes or line ids", () => {
  const html = renderToStaticMarkup(
    createElement(QuotationReadinessView, {
      readiness: mockReadiness({
        issue_ready: false,
        blockers: [
          { code: "TAX_CLASSIFICATION_REQUIRED", scope: "TAX", line_id: 91 },
          { code: "CONTROL_FAILED", scope: "CONTROLS", line_id: 92 },
        ],
      }),
      mayIssue: true,
      onIssue: () => undefined,
    }),
  );
  expect(html).toContain("A line has no tax classification declared.");
  expect(html).toContain("does not meet a mandatory financial control");
  for (const raw of [
    "TAX_CLASSIFICATION_REQUIRED",
    "CONTROL_FAILED",
    "91",
    "92",
  ])
    expect(html).not.toContain(raw);
  expect(html).toMatch(/<button[^>]*disabled[^>]*>Issue quotation/);
});

test("availability is shown as a state badge and never claims acceptance", () => {
  for (const [availability, label] of [
    ["AVAILABLE", "Issued"],
    ["EXPIRED", "Expired"],
    ["WITHDRAWN", "Withdrawn"],
    ["REPLACED", "Replaced"],
  ] as const) {
    const html = renderToStaticMarkup(
      createElement(QuotationIssuedView, {
        terms: mockIssuedTerms({ availability }),
      }),
    );
    expect(html).toContain(label);
    expect(html).not.toMatch(/accepted/i);
    expect(html).toContain("QUO-2026-0007");
  }
  expect(availabilityView("SOMETHING_NEW").label).toBe("Issued");
});

test("the issued view links to the official PDF only when given a place, and totals stay hidden from non-commercial viewers", () => {
  const without = renderToStaticMarkup(
    createElement(QuotationIssuedView, { terms: mockIssuedTerms() }),
  );
  expect(without).not.toContain("Official PDF");
  const withLink = inRouter(
    createElement(QuotationIssuedView, {
      terms: mockIssuedTerms(),
      documentsTo: "/quotations/12/document",
    }),
  );
  expect(withLink).toContain('href="/quotations/12/document"');
  expect(withLink).toContain("Official PDF and delivery");
  const hidden = renderToStaticMarkup(
    createElement(QuotationIssuedView, {
      terms: mockIssuedTerms({ totals: null }),
    }),
  );
  expect(hidden).not.toContain("1150.00");
  expect(hidden).toContain("Totals are shown to people who prepare or issue");
  const outcome = inRouter(
    createElement(QuotationIssueOutcomeView, {
      outcome: {
        family_id: 1,
        revision_id: 12,
        issue_id: 3,
        official_number: "QUO-1",
        issued_at: "2026-11-01T10:00:00Z",
        replayed: true,
      },
      documentsTo: "/quotations/12/document",
    }),
  );
  expect(outcome).toContain("nothing was");
  expect(outcome).toContain("Official PDF and delivery");
});

test("the issued contract requires availability and still strips protected extras", () => {
  const { availability, ...rest } = mockIssuedTerms();
  void availability;
  expect(() => readIssuedTerms(rest)).toThrow();
  const parsed = readIssuedTerms({
    ...mockIssuedTerms(),
    fingerprint: "x",
    cost_total: "9",
  });
  expect(JSON.stringify(parsed)).not.toMatch(/fingerprint|cost_total/);
});

test("the list shows the official number and the offer's state, and the contract carries both", () => {
  const rows = [
    mockSummary(1),
    mockSummary(2, {
      state: "ISSUED",
      official_number: "QUO-2026-0007",
      availability: "EXPIRED",
    }),
  ];
  const page = readListPage({
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 2,
      from: 1,
      to: 2,
    },
  });
  expect(page.data[1].official_number).toBe("QUO-2026-0007");
  const number = quotationListColumns.find((c) => c.key === "official_number")!;
  const status = quotationListColumns.find((c) => c.key === "state")!;
  expect(number.value!(page.data[0])).toBe("Not issued");
  expect(number.value!(page.data[1])).toBe("QUO-2026-0007");
  expect(status.value!(page.data[0])).toBe("DRAFT");
  expect(status.value!(page.data[1])).toBe("Expired");
  expect(quotationListColumns.some((c) => c.key === "valid_through")).toBe(
    true,
  );
});
