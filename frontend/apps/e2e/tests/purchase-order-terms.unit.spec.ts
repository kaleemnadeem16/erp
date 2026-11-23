import { expect, test } from "@playwright/test";
import { paymentTermsLabel } from "../../../packages/core-ui/src/features/purchase-orders/purchase-order-terms";
import { buildPurchaseOrderPrintDocument } from "../../../packages/core-ui/src/features/purchase-orders/purchase-order-print-model";

/**
 * ADR 0191 corrections: payment terms are commercial, so a reader without commercial access sees none (the supplier record never fills the gap), and an issued order that never
 * recorded terms says "Not recorded" — today's supplier default is not what was agreed. A draft may still show the live supplier default.
 */
const order = (over: Record<string, unknown>) =>
  ({ id: 1, supplier_account_id: 4, status: "ISSUED", order_date: "2026-01-01", created_at: "2026-01-01T00:00:00Z", lines: [], ...over }) as never;
const supplier = { id: 4, code: "SUP", payment_terms_days: 90, party: { name: "Supplier" } } as never;
const print = (o: unknown, commercial: boolean) =>
  buildPurchaseOrderPrintDocument({
    order: o as never,
    supplier,
    settings: { companyName: "Co" } as never,
    currency: "PKR",
    commercial,
    uom: () => "ea",
    printedAt: "2026-11-16T00:00:00Z",
  });
const termsOnPrint = (o: unknown, commercial = true) =>
  print(o, commercial).metadata.find((m: { label: string }) => m.label === "Payment terms")?.value;

test("each state of the order's terms reads as what it is", () => {
  expect(paymentTermsLabel(order({ payment_terms_state: "NOT_RECORDED" }))).toBe("Not recorded");
  expect(paymentTermsLabel(order({ payment_terms_state: "DRAFT_SUPPLIER_DEFAULT", payment_terms_effective_days: 30 }))).toBe("30 days (supplier default)");
  expect(paymentTermsLabel(order({ payment_terms_state: "DRAFT_ORDER", payment_terms_effective_days: 45 }))).toBe("45 days (set on this order)");
  expect(paymentTermsLabel(order({ payment_terms_state: "ISSUED_ORDER", payment_terms_effective_days: 45 }))).toBe("45 days (agreed on this order)");
  expect(paymentTermsLabel(order({ payment_terms_state: "ISSUED_SUPPLIER_DEFAULT", payment_terms_effective_days: 30 }))).toBe("30 days (supplier default when issued)");
});

test("a reader without commercial access gets no terms, whatever the supplier record says", () => {
  // The API sends no terms fields to such a reader.
  expect(paymentTermsLabel(order({}))).toBeNull();
  expect(termsOnPrint(order({}), false)).toBeUndefined();
});

test("the print shows the order's own terms, 'Not recorded' for an old issued order, and never the supplier's live default", () => {
  expect(termsOnPrint(order({ payment_terms_state: "ISSUED_ORDER", payment_terms_effective_days: 45, payment_terms_days: 45 }))).toBe("45 days (agreed on this order)");
  expect(termsOnPrint(order({ payment_terms_state: "NOT_RECORDED", payment_terms_days: null }))).toBe("Not recorded");
  expect(termsOnPrint(order({ status: "DRAFT", payment_terms_state: "DRAFT_SUPPLIER_DEFAULT", payment_terms_effective_days: 90 }))).toBe("90 days (supplier default)");
});

test("without commercial access nothing about payment terms is printed, even if the order object carries them", () => {
  const carrying = order({ payment_terms_state: "ISSUED_ORDER", payment_terms_effective_days: 45, payment_terms_days: 45, payment_terms_source: "PURCHASE_ORDER" });
  const document = print(carrying, false);
  expect(termsOnPrint(carrying, false)).toBeUndefined();
  const text = JSON.stringify(document);
  expect(text).not.toMatch(/Payment terms/i);
  expect(text).not.toContain("45 days");
  expect(text).not.toContain("90 days"); // nor the supplier's own record, which this test's supplier carries
  // The same order printed for a commercial reader does show them.
  expect(termsOnPrint(carrying, true)).toBe("45 days (agreed on this order)");
});
