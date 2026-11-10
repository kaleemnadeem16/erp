import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  authorityState,
  mockAuthority,
  mockConfiguration,
  mockEvaluation,
  readQuotationAuthority,
  reasonText,
} from "../../../packages/core-ui/src/features/discount-authority/discount-authority-contract";
import { discountAuthorityAccess } from "../../../packages/core-ui/src/features/discount-authority/discount-authority-access";
import {
  emptyAssignmentForm,
  emptyPolicyForm,
  validateAssignmentForm,
  validatePolicyForm,
} from "../../../packages/core-ui/src/features/discount-authority/discount-authority-form-model";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";
import {
  mockApproval,
  mockApprovalRequest,
} from "../../../packages/core-ui/src/features/quotations/quotation-approval-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/discount-authority/quotation-discount-authority-panel") &
    typeof import("../../../packages/core-ui/src/features/discount-authority/discount-authority-settings-screen") &
    typeof import("../../../packages/core-ui/src/features/quotations/quotation-approval-screen") & {
      MemoryRouter: typeof import("react-router").MemoryRouter;
    };
const { loaded, cleanup } = ssrBundle<Screen>(
  "discount-authority-ssr",
  `export * from "../discount-authority/quotation-discount-authority-panel"; export * from "../discount-authority/discount-authority-settings-screen"; export { QuotationApprovalView } from "./quotation-approval-screen"; export { MemoryRouter } from "react-router";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const { AuthorityResultView, DiscountAuthorityView, QuotationApprovalView, MemoryRouter } = loaded;
test.afterAll(cleanup);

const CONFIGURE = ["sales.financial.configure"];
const EVIDENCE = ["sales.quotation.view", "sales.discount-authority.evidence.view"];

test("the screens are denied in production whatever the person holds, and each duty is separate", () => {
  const all = new Set([...CONFIGURE, ...EVIDENCE]);
  expect(discountAuthorityAccess(all, false)).toEqual({ configure: false, evidence: false });
  expect(discountAuthorityAccess(new Set(CONFIGURE), true)).toEqual({ configure: true, evidence: false });
  expect(discountAuthorityAccess(new Set(EVIDENCE), true)).toEqual({ configure: false, evidence: true });
  // evidence needs an independent quotation view
  expect(discountAuthorityAccess(new Set(["sales.discount-authority.evidence.view"]), true).evidence).toBe(false);
  expect(canAccessScreen("/settings/discount-authority", new Set(CONFIGURE))).toBe(false);
});

test("each evaluation maps to one plain state", () => {
  const key = (a: ReturnType<typeof mockAuthority>) => authorityState(a).key;
  expect(key(mockAuthority({ mode: "OFF" }))).toBe("INACTIVE");
  expect(key(mockAuthority({ current: null }))).toBe("NOT_EVALUATED");
  expect(key(mockAuthority({ current: mockEvaluation({ result: "UNEVALUABLE", policy_id: null, required_band: null, reasons: ["AUTHORITY_POLICY_MISSING"] }) }))).toBe("NOT_CONFIGURED");
  expect(key(mockAuthority({ current: mockEvaluation({ result: "UNEVALUABLE", reasons: ["AUTHORITY_FX_UNVERIFIED"] }) }))).toBe("CANNOT_EVALUATE");
  expect(key(mockAuthority({ current: mockEvaluation({ result: "NOT_APPLICABLE", reasons: [] }) }))).toBe("NOT_APPLICABLE");
  expect(key(mockAuthority({ current: mockEvaluation({ result: "ROUTINE", reasons: [] }) }))).toBe("WITHIN_AUTHORITY");
  expect(key(mockAuthority())).toBe("NEEDS_APPROVAL");
  expect(reasonText("SOMETHING_NEW")).toBe("A check on this quotation did not pass.");
});

test("the server response is parsed and stripped", () => {
  const parsed = readQuotationAuthority({ ...mockAuthority(), secret: "x", current: { ...mockEvaluation(), cost: "1" } });
  expect(JSON.stringify(parsed)).not.toContain("secret");
  expect(JSON.stringify(parsed)).not.toContain("cost");
});

test("the result view shows the state, the approver needed and why", () => {
  const html = renderToStaticMarkup(createElement(AuthorityResultView, { authority: mockAuthority() }));
  expect(html).toContain("Needs approval");
  expect(html).toContain("Approver needed");
  expect(html).toContain("DEMO ONLY Sales manager");
  expect(html).toContain("10.0000% on a line");
  expect(html).toContain("above the limit assigned");
  expect(html).toContain("8.0000%");
});

test("inactive and not-configured states show no figures", () => {
  const off = renderToStaticMarkup(createElement(AuthorityResultView, { authority: mockAuthority({ mode: "OFF" }) }));
  expect(off).toContain("Not active");
  expect(off).not.toContain("1000.0000");
  const none = renderToStaticMarkup(createElement(AuthorityResultView, {
    authority: mockAuthority({ current: mockEvaluation({ result: "UNEVALUABLE", policy_id: null, required_band: null, reference_amount: null, reasons: ["AUTHORITY_POLICY_MISSING"] }) }),
  }));
  expect(none).toContain("No authority levels set up");
  const within = renderToStaticMarkup(createElement(AuthorityResultView, { authority: mockAuthority({ current: mockEvaluation({ result: "ROUTINE", reasons: [] }) }) }));
  expect(within).toContain("Within authority");
  expect(within).not.toContain("Approver needed");
});

test("the approval screen shows a request's expiry and an expired state", () => {
  const view = (expired: boolean) =>
    renderToStaticMarkup(createElement(MemoryRouter, null, createElement(QuotationApprovalView, {
      approval: mockApproval({ state: "PENDING", request: { ...mockApprovalRequest(), expires_at: "2026-11-20T09:00:00Z", expired } }),
    })));
  expect(view(false)).toContain("Request valid until");
  expect(view(true)).toContain("has expired");
});

test("the configuration view is inactive by default and offers writes only with the duty", () => {
  const config = mockConfiguration();
  const people = [{ id: 21, name: "Demo Person" }];
  const read = renderToStaticMarkup(createElement(DiscountAuthorityView, { config, people, mayWrite: false }));
  expect(read).toContain("not active");
  expect(read).toContain("Demo only");
  expect(read).toContain("Demo Person");
  expect(read).toContain("Activated");
  expect(read).toContain("Not activated");
  expect(read).not.toContain("New policy version</button>");
  expect(read).not.toContain("Revoke</button>");
  const write = renderToStaticMarkup(createElement(DiscountAuthorityView, {
    config, people, mayWrite: true, onNewPolicy: () => undefined, onAssign: () => undefined, onActivate: () => undefined, onRevoke: () => undefined,
  }));
  expect(write).toContain("New policy version");
  expect(write).toContain("Assign authority");
  expect(write).toContain("Activate this version");
  expect(write).toContain("Revoke");
});

test("empty configuration says nothing is set up", () => {
  const html = renderToStaticMarkup(createElement(DiscountAuthorityView, {
    config: mockConfiguration({ policies: [], bands: [], assignments: [], activations: [] }), people: null, mayWrite: true, onNewPolicy: () => undefined, onAssign: () => undefined,
  }));
  expect(html).toContain("No authority levels are set up yet");
  expect(html).toContain("No policy versions yet");
  expect(html).toContain("No one has been assigned authority");
});

test("the policy form is empty of limits and validates as the server does", () => {
  const form = emptyPolicyForm("2026-11-10");
  expect(form.bands[0]!.limits.line_percent).toBe("");
  expect("errors" in validatePolicyForm(form)).toBe(true);
  const filled = {
    ...form,
    code: "sales-standard",
    reason: "Test",
    bands: [{ ...form.bands[0]!, label: "Level", limits: { ...form.bands[0]!.limits, line_percent: "5", document_percent: "5" } }],
  };
  const ok = validatePolicyForm(filled);
  expect("input" in ok && ok.input.bands[0]!.line_percent).toBe("5");
  const over = validatePolicyForm({ ...filled, bands: [{ ...filled.bands[0]!, limits: { ...filled.bands[0]!.limits, line_percent: "101" } }] });
  expect("errors" in over && over.errors["bands.0.line_percent"]).toBeTruthy();
  const missingDoc = validatePolicyForm({ ...filled, bands: [{ ...filled.bands[0]!, limits: { ...filled.bands[0]!.limits, document_percent: "" } }] });
  expect("errors" in missingDoc && missingDoc.errors["bands.0.limits"]).toBeTruthy();
  const exception = validatePolicyForm({ ...filled, bands: [{ ...filled.bands[0]!, exception_only: true }] });
  expect("input" in exception && exception.input.bands[0]!.line_percent).toBeNull();
  const dup = validatePolicyForm({ ...filled, bands: [filled.bands[0]!, { ...filled.bands[0]!, key: 99 }] });
  expect("errors" in dup && dup.errors["bands.1.rank"]).toBeTruthy();
});

test("the assignment form needs a person, a level, a start and a reason", () => {
  const form = emptyAssignmentForm("2026-11-10");
  const bad = validateAssignmentForm(form);
  expect("errors" in bad && Object.keys(bad.errors).sort()).toEqual(["band_id", "reason", "user_id"]);
  const ok = validateAssignmentForm({ ...form, user_id: "21", band_id: "3", reason: "r", effective_to: "2026-12-01" });
  expect("input" in ok && ok.input.effective_to).toBe("2026-12-01");
  const backwards = validateAssignmentForm({ ...form, user_id: "21", band_id: "3", reason: "r", effective_to: "2026-11-10" });
  expect("errors" in backwards && backwards.errors.effective_to).toBeTruthy();
});
