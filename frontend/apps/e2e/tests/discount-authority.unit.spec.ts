import { expect, test } from "@playwright/test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ssrBundle } from "./fixtures/ssr-bundle";
import {
  authorityState,
  mockAuthority,
  mockConfiguration,
  mockEvaluation,
  mockHistoryPage,
  mockReferenceConfiguration,
  historyState,
  readAuthorityHistory,
  readBindResult,
  readReferencePage,
  readQuotationAuthority,
  reasonText,
} from "../../../packages/core-ui/src/features/discount-authority/discount-authority-contract";
import { discountAuthorityAccess } from "../../../packages/core-ui/src/features/discount-authority/discount-authority-access";
import {
  emptyAssignmentForm,
  emptyPolicyForm,
  emptyReferenceForm,
  validateAssignmentForm,
  validatePolicyForm,
  validateReferenceForm,
} from "../../../packages/core-ui/src/features/discount-authority/discount-authority-form-model";
import { calendarDayIn } from "../../../packages/core-ui/src/features/company/company-calendar";
import { bindRefusalMessage } from "../../../packages/core-ui/src/features/discount-authority/quotation-bind-references-action";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";
import {
  mockApproval,
  mockApprovalRequest,
} from "../../../packages/core-ui/src/features/quotations/quotation-approval-contract";

type Screen =
  typeof import("../../../packages/core-ui/src/features/discount-authority/quotation-discount-authority-panel") &
    typeof import("../../../packages/core-ui/src/features/discount-authority/discount-authority-settings-screen") &
    typeof import("../../../packages/core-ui/src/features/discount-authority/discount-authority-references-screen") &
    typeof import("../../../packages/core-ui/src/features/discount-authority/quotation-authority-history-screen") &
    typeof import("../../../packages/core-ui/src/features/quotations/quotation-approval-screen") & {
      MemoryRouter: typeof import("react-router").MemoryRouter;
    };
const { loaded, cleanup } = ssrBundle<Screen>(
  "discount-authority-ssr",
  `export * from "../discount-authority/quotation-discount-authority-panel"; export * from "../discount-authority/discount-authority-settings-screen"; export * from "../discount-authority/discount-authority-references-screen"; export * from "../discount-authority/quotation-authority-history-screen"; export { QuotationApprovalView } from "./quotation-approval-screen"; export { MemoryRouter } from "react-router";`,
  new URL("../../../packages/core-ui/src/features/quotations/", import.meta.url)
    .pathname,
);
const { AuthorityResultView, DiscountAuthorityView, ReferenceListView, AuthorityHistoryView, QuotationApprovalView, MemoryRouter } = loaded;
test.afterAll(cleanup);

const CONFIGURE = ["sales.financial.configure"];
const EVIDENCE = ["sales.quotation.view", "sales.discount-authority.evidence.view"];

test("the screens are denied in production whatever the person holds, and each duty is separate", () => {
  const all = new Set([...CONFIGURE, ...EVIDENCE]);
  const off = discountAuthorityAccess(new Set([...all, "sales.pricing.reference.approve", "sales.quotation.prepare"]), false);
  expect(Object.values(off).every((v) => v === false)).toBe(true);
  const configure = discountAuthorityAccess(new Set(CONFIGURE), true);
  expect([configure.configure, configure.evidence, configure.references, configure.referencePropose, configure.referenceApprove, configure.bind]).toEqual([true, false, true, true, false, false]);
  const evidence = discountAuthorityAccess(new Set(EVIDENCE), true);
  expect([evidence.configure, evidence.evidence, evidence.references, evidence.bind]).toEqual([false, true, false, false]);
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
  const read = renderToStaticMarkup(createElement(DiscountAuthorityView, { config, people, today: "2026-11-10", mayWrite: false }));
  expect(read).toContain("not active");
  expect(read).toContain("Demo only");
  expect(read).toContain("Demo Person");
  expect(read).toContain("Activated");
  expect(read).toContain("Not activated");
  expect(read).not.toContain("New policy version</button>");
  expect(read).not.toContain("Revoke</button>");
  const write = renderToStaticMarkup(createElement(DiscountAuthorityView, {
    config, people, today: "2026-11-10", mayWrite: true, onNewPolicy: () => undefined, onAssign: () => undefined, onActivate: () => undefined, onRevoke: () => undefined,
  }));
  expect(write).toContain("New policy version");
  expect(write).toContain("Assign authority");
  expect(write).toContain("Activate this version");
  expect(write).toContain("Revoke");
});

test("empty configuration says nothing is set up", () => {
  const html = renderToStaticMarkup(createElement(DiscountAuthorityView, {
    config: mockConfiguration({ policies: [], bands: [], assignments: [], activations: [] }), people: null, today: "2026-11-10", mayWrite: true, onNewPolicy: () => undefined, onAssign: () => undefined,
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

test("the company calendar day follows the named time zone, not the browser or UTC", () => {
  const lateUtc = new Date("2026-11-10T20:30:00Z");
  expect(calendarDayIn("Asia/Karachi", lateUtc)).toBe("2026-11-11");
  expect(calendarDayIn("UTC", lateUtc)).toBe("2026-11-10");
  expect(calendarDayIn("America/Los_Angeles", lateUtc)).toBe("2026-11-10");
  // an unknown zone never throws
  expect(calendarDayIn("Not/AZone", lateUtc)).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(calendarDayIn(null, lateUtc)).toMatch(/^\d{4}-\d{2}-\d{2}$/);
});

test("expired and starts-later follow the day the screen is given", () => {
  const config = mockConfiguration({
    assignments: [{ id: 7, user_id: 21, band_id: 3, purpose: "ROUTINE", role_id: null, unit_id: null, effective_from: "2026-11-01", effective_to: "2026-11-10", assigned_by: 5, reason: null }],
  });
  const at = (today: string) => renderToStaticMarkup(createElement(DiscountAuthorityView, { config, people: null, today, mayWrite: false }));
  expect(at("2026-11-10")).toContain("In effect");
  expect(at("2026-11-11")).toContain("Expired");
});

test("the reference form needs a source, a measure, a currency and a price above zero", () => {
  const form = emptyReferenceForm("2026-11-10");
  const bad = validateReferenceForm(form);
  expect("errors" in bad && Object.keys(bad.errors).sort()).toEqual(["code", "currency_id", "item_id", "reason", "source_key", "source_version", "unit_price"]);
  const base = { ...form, code: "demo-list", source_key: "List", source_version: "1", item_id: "31", currency_id: "1", unit_price: "100", reason: "r" };
  const ok = validateReferenceForm(base);
  expect("input" in ok && ok.input.item_id).toBe(31);
  expect("input" in ok && ok.input.manual_measure).toBeNull();
  const zero = validateReferenceForm({ ...base, unit_price: "0.0000" });
  expect("errors" in zero && zero.errors.unit_price).toBeTruthy();
  const places = validateReferenceForm({ ...base, unit_price: "1.23456" });
  expect("errors" in places && places.errors.unit_price).toBeTruthy();
  const agreement = validateReferenceForm({ ...base, source_kind: "CUSTOMER_AGREEMENT" });
  expect("errors" in agreement && agreement.errors.customer_id).toBeTruthy();
  const manual = validateReferenceForm({ ...base, basis: "manual", item_id: "", manual_measure: "per square metre" });
  expect("errors" in manual && Object.keys(manual.errors).sort()).toEqual(["family_id", "source_kind"]);
  const baseline = validateReferenceForm({ ...base, basis: "manual", source_kind: "PROJECT_BASELINE", item_id: "", manual_measure: "per square metre", family_id: "3" });
  expect("input" in baseline && [baseline.input.item_id, baseline.input.manual_measure, baseline.input.family_id]).toEqual([null, "per square metre", 3]);
  const backwards = validateReferenceForm({ ...base, effective_to: "2026-11-10" });
  expect("errors" in backwards && backwards.errors.effective_to).toBeTruthy();
});

test("the reference list shows who proposed, who approved, and offers approval only to someone else", () => {
  const config = mockReferenceConfiguration();
  const people = [{ id: 5, name: "Demo Proposer" }, { id: 6, name: "Demo Approver" }];
  const currencies = [{ id: 1, code: "PKR", name: "Pakistani rupee" }];
  const view = (actorId: number | null, mayApprove: boolean, mayPropose = true) =>
    renderToStaticMarkup(createElement(ReferenceListView, { config, people, currencies, actorId, today: "2026-11-10", mayPropose, mayApprove, onPropose: () => undefined, onApprove: () => undefined }));
  const other = view(42, true);
  expect(other).toContain("Awaiting approval");
  expect(other).toContain("Approved by Demo Approver");
  expect(other).toContain("Proposed by Demo Proposer");
  expect(other).toContain("PKR");
  expect(other).toContain("Demo only");
  expect(other).toContain("Approve this reference");
  expect(other).toContain("Propose a price reference");
  // the proposer is told someone else must approve
  const own = view(5, true);
  expect(own).not.toContain("Approve this reference");
  expect(own).toContain("someone else has to approve it");
  // an approver without the configuration duty cannot propose
  expect(view(42, true, false)).not.toContain("Propose a price reference");
  const readOnly = view(42, false, false);
  expect(readOnly).not.toContain("Approve this reference");
  expect(readOnly).toContain("Changing them needs a separate permission");
  expect(renderToStaticMarkup(createElement(ReferenceListView, { config: mockReferenceConfiguration({ references: [], approvals: [] }), people: null, currencies: null, actorId: 1, today: "2026-11-10", mayPropose: true, mayApprove: false }))).toContain("No price references yet");
});

test("reference rows are parsed and stripped", () => {
  const page = readReferencePage({ generation: 1, mode: "OFF", page: 1, per_page: 25, total: 1, records: [{ ...mockReferenceConfiguration().references[0], secret: "x" }] });
  expect(JSON.stringify(page)).not.toContain("secret");
  expect(() => readReferencePage({ generation: 1, mode: "OFF", page: 1, per_page: 25, total: 1, records: [{ id: 1 }] })).toThrow();
});

test("the history is parsed, stripped and read with the state it had when recorded", () => {
  const raw = mockHistoryPage();
  const parsed = readAuthorityHistory({ ...raw, records: raw.records.map((r) => ({ ...r, evaluation: { ...r.evaluation, cost: "1" } })) });
  expect(JSON.stringify(parsed)).not.toContain("cost");
  expect(historyState(parsed.records[0]!).key).toBe("NEEDS_APPROVAL");
  expect(historyState(parsed.records[1]!).key).toBe("WITHIN_AUTHORITY");
  expect(historyState({ ...parsed.records[0]!, mode: "OFF" }).key).toBe("INACTIVE");
});

test("the history view lists each recorded result with its mode then, figures and reasons", () => {
  const html = renderToStaticMarkup(createElement(AuthorityHistoryView, { history: mockHistoryPage() }));
  expect(html).toContain("Needs approval");
  expect(html).toContain("Within authority");
  expect(html).toContain("Setting then: Enforced");
  expect(html).toContain("Setting then: Record only");
  expect(html).toContain("above the limit assigned");
  expect(html).toContain("DEMO ONLY Sales manager");
  expect(html).toContain("draft version 5");
  expect(html).not.toContain("Pages of recorded results");
  const empty = renderToStaticMarkup(createElement(AuthorityHistoryView, { history: mockHistoryPage({ records: [], total: 0 }) }));
  expect(empty).toContain("No result has been recorded");
  const paged = renderToStaticMarkup(createElement(AuthorityHistoryView, { history: mockHistoryPage({ total: 60 }), onPage: () => undefined }));
  expect(paged).toContain("Page 1 of 3");
});

test("the bind result is parsed and refusals have plain wording", () => {
  expect(readBindResult({ family_id: 3, revision_id: 12, bound_lines: 2, extra: 1 })).toEqual({ family_id: 3, revision_id: 12, bound_lines: 2 });
  expect(() => readBindResult({ family_id: 3 })).toThrow();
  const refusal = (code: string) => Object.assign(new Error("x"), { status: 422, body: { reason_code: code } });
  expect(bindRefusalMessage(refusal("AUTHORITY_STALE"))).toContain("quotation changed");
  expect(bindRefusalMessage(refusal("REFERENCE_MISSING"))).toContain("approved price reference");
  expect(bindRefusalMessage(new Error("boom"))).toContain("could not be captured");
});

test("the new routes are denied in production and each needs its own duty", () => {
  const refs = new Set(["sales.pricing.reference.approve"]);
  expect(canAccessScreen("/settings/discount-authority/references", refs)).toBe(false);
  expect(canAccessScreen("/quotations/12/discount-authority-history", new Set(EVIDENCE))).toBe(false);
  expect(canAccessScreen("/quotations/0/discount-authority-history", new Set(EVIDENCE))).toBe(false);
  expect(discountAuthorityAccess(refs, true).references).toBe(true);
  expect(discountAuthorityAccess(refs, true).referencePropose).toBe(false);
});
