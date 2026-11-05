import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  APPROVAL_REFUSALS,
  mockApproval,
  mockApprovalRequest,
  readApprovalOutcome,
  readQuotationApproval,
} from "../../../packages/core-ui/src/features/quotations/quotation-approval-contract";
import { quotationApprovalAccess } from "../../../packages/core-ui/src/features/quotations/quotation-approval-access";
import {
  describeBlocker,
  groupBlockers,
  mockReadiness,
} from "../../../packages/core-ui/src/features/quotations/quotation-issue-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/quotations/quotation-approval-screen") &
    typeof import("../../../packages/core-ui/src/features/quotations/quotation-issue-screen") & {
      MemoryRouter: typeof import("react-router").MemoryRouter;
    };
const { loaded, cleanup } = ssrBundle<Screen>(
  "quotation-approval-ssr",
  `export * from "./quotation-approval-screen"; export { QuotationReadinessView } from "./quotation-issue-screen"; export { MemoryRouter } from "react-router";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const {
  QuotationApprovalView,
  QuotationReadinessView,
  approvalRefusalMessage,
  MemoryRouter,
} = loaded;
test.afterAll(cleanup);

const view = (
  approval = mockApproval(),
  onAction: ((a: string) => void) | undefined = () => undefined,
) =>
  renderToStaticMarkup(
    createElement(
      MemoryRouter,
      null,
      createElement(QuotationApprovalView, { approval, onAction }),
    ),
  );

const PREPARER = ["sales.quotation.view", "sales.quotation.prepare"];
const APPROVER = ["sales.quotation.view", "sales.quotation.approve"];

test("the approval screens are denied in production whatever the person holds", () => {
  const everything = new Set([...PREPARER, ...APPROVER]);
  expect(quotationApprovalAccess(everything, false)).toEqual({
    read: false,
    request: false,
    decide: false,
  });
});

test("each duty is judged with the independent view duty", () => {
  const on = (...p: string[]) => quotationApprovalAccess(new Set(p), true);
  expect(on()).toEqual({ read: false, request: false, decide: false });
  expect(on("sales.quotation.prepare").read).toBe(false);
  expect(on("sales.quotation.approve").read).toBe(false);
  expect(on(...PREPARER)).toEqual({ read: true, request: true, decide: false });
  expect(on(...APPROVER)).toEqual({ read: true, request: false, decide: true });
});

test("a preparer with a discount that needs approval is offered only the request", () => {
  const html = view(mockApproval());
  expect(html).toContain("No approval requested");
  expect(html).toContain("Ask for approval");
  expect(html).not.toContain("Approve<");
  expect(html).not.toContain("Reject");
  expect(html).not.toContain("Withdraw request");
});

test("a pending request shows the preparer a withdraw button and who is being waited for, never an approve button", () => {
  const html = view(
    mockApproval({
      state: "PENDING",
      request: mockApprovalRequest(),
      can: { request: false, withdraw: true, decide: false },
    }),
  );
  expect(html).toContain("Waiting for approval");
  expect(html).toContain("Amina Khan");
  expect(html).toContain("Withdraw request");
  expect(html).not.toContain(">Approve<");
  const other = view(
    mockApproval({
      state: "PENDING",
      request: mockApprovalRequest(),
      can: { request: false, withdraw: false, decide: false },
    }),
  );
  expect(other).toContain("You can’t approve this yourself");
  expect(other).not.toContain("<button");
});

test("an approver sees approve and reject, and the touch targets are at least 44px", () => {
  const html = view(
    mockApproval({
      state: "PENDING",
      request: mockApprovalRequest(),
      can: { request: false, withdraw: false, decide: true },
    }),
  );
  expect(html).toContain(">Approve<");
  expect(html).toContain(">Reject<");
  const buttons = html.match(/<button[^>]*>/g) ?? [];
  expect(buttons.length).toBe(2);
  for (const button of buttons) expect(button).toContain("min-h-11");
});

test("decisions show who decided, what and the written reason; nothing monetary appears", () => {
  const html = view(
    mockApproval({
      state: "REJECTED",
      request: mockApprovalRequest({
        status: "REJECTED",
        decisions: [
          {
            decision: "REJECTED",
            reason: "Too deep for this customer",
            decided_at: "2026-11-05T10:00:00Z",
            decided_by: { id: 9, name: "Bilal Ahmed" },
          },
        ],
      }),
      can: { request: false, withdraw: false, decide: false },
    }),
  );
  expect(html).toContain("Rejected");
  expect(html).toContain("Bilal Ahmed");
  expect(html).toContain("Too deep for this customer");
  expect(html).not.toMatch(/ceiling|limit|margin|cost/i);
});

test("a superseded request is explained, and a draft needing nothing says so", () => {
  expect(view(mockApproval({ superseded: true, request: mockApprovalRequest() }))).toContain(
    "previous version of this draft",
  );
  expect(
    view(mockApproval({ approval_needed: false, can: { request: false, withdraw: false, decide: false } })),
  ).toContain("has no discount or price override that needs approval");
  expect(view(mockApproval({ is_draft: false }))).toContain("Only a draft quotation");
});

test("the readiness page names an approval wait in plain words and links to the approval only for people who may open it", () => {
  const readiness = mockReadiness({
    issue_ready: false,
    blockers: [
      { code: "APPROVAL_PENDING", scope: "LINE", line_id: 4 },
      { code: "APPROVAL_REJECTED", scope: "HEADER", line_id: null },
    ],
  });
  const render = (approvalTo?: string) =>
    renderToStaticMarkup(
      createElement(
        MemoryRouter,
        null,
        createElement(QuotationReadinessView, {
          readiness,
          mayIssue: true,
          onIssue: () => undefined,
          approvalTo,
        }),
      ),
    );
  const without = render();
  expect(without).toContain("waiting for someone to approve it");
  expect(without).toContain("approver rejected");
  expect(without).not.toContain("APPROVAL_");
  expect(without).not.toContain("Open discount approval");
  expect(render("/quotations/12/approval")).toContain("Open discount approval");
  for (const code of ["APPROVAL_REQUIRED", "APPROVAL_PENDING", "APPROVAL_REJECTED"])
    expect(describeBlocker({ code, scope: "LINE", line_id: null }).group).toBe("approval");
  expect(groupBlockers(readiness.blockers).map((g) => g.id)).toEqual(["approval"]);
});

test("refusals use the stable code's wording and never echo server text", () => {
  for (const code of Object.keys(APPROVAL_REFUSALS)) {
    const error = Object.assign(new Error("server says: secret detail"), {
      status: 422,
      body: { reason_code: code },
    });
    expect(approvalRefusalMessage(error)).toBe(APPROVAL_REFUSALS[code]);
    expect(approvalRefusalMessage(error)).not.toContain("secret");
  }
  expect(approvalRefusalMessage(Object.assign(new Error("x"), { status: 403 }))).toContain("permission");
  expect(approvalRefusalMessage(Object.assign(new Error("x"), { status: 404 }))).toContain("not available");
  expect(approvalRefusalMessage(new Error("offline"))).toContain("Try again");
});

test("the contract parses the server shape and strips anything extra", () => {
  const parsed = readQuotationApproval({
    ...mockApproval({ state: "APPROVED", request: mockApprovalRequest({ status: "APPROVED" }) }),
    ceiling: "999999",
  });
  expect(parsed.state).toBe("APPROVED");
  expect("ceiling" in parsed).toBe(false);
  expect(() => readQuotationApproval({ ...mockApproval(), state: "SOMETHING" })).toThrow();
  expect(
    readApprovalOutcome({
      family_id: 1,
      revision_id: 12,
      approval_request_id: 31,
      status: "PENDING",
      input_generation: 6,
      replayed: false,
      extra: 1,
    }),
  ).not.toHaveProperty("extra");
});
