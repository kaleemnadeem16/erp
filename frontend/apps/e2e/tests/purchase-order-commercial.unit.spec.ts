import { expect, test } from "@playwright/test";
import { mayViewOrderCommercial } from "../../../packages/core-ui/src/features/purchase-orders/purchase-order-commercial";
import { buildPurchaseOrderPrintDocument } from "../../../packages/core-ui/src/features/purchase-orders/purchase-order-print-model";

/**
 * ADR 0192: a person's commercial reach can be narrower than their operational reach, so whether an order's money is shown is the server's verdict for THAT order
 * (`commercial_visible`), never the general permission. The print and the detail headings follow it; nothing falls back to supplier or permission data.
 */
const line = { id: 1, line_number: 1, item_id: 2, item_code: "RM1", description: "Resin", ordered_qty: "2.0000", purchase_uom_id: 5, stock_uom_id: 5, ordered_stock_qty: "2.0000",
  unit_price: "42.5000", discount_amount: "1.0000", tax_amount: "2.0000", line_amount: "86.0000", price_quantity: "1.0000" };
const order = (over: Record<string, unknown>) =>
  ({ id: 9, supplier_account_id: 4, status: "ISSUED", order_date: "2026-01-01", created_at: "2026-01-01T00:00:00Z", currency_id: 1, lines: [line], total_amount: "86.0000",
    payment_terms_state: "ISSUED_ORDER", payment_terms_effective_days: 45, ...over }) as never;
const printFor = (o: never) =>
  buildPurchaseOrderPrintDocument({ order: o, supplier: { id: 4, code: "SUP", payment_terms_days: 90, party: { name: "S" } } as never, settings: { companyName: "Co" } as never,
    currency: "PKR", commercial: mayViewOrderCommercial(o), uom: () => "ea", printedAt: "2026-11-24T00:00:00Z" });

test("only an explicit server verdict of true shows an order's money", () => {
  expect(mayViewOrderCommercial(order({ commercial_visible: true }))).toBe(true);
  expect(mayViewOrderCommercial(order({ commercial_visible: false }))).toBe(false);
  expect(mayViewOrderCommercial(order({}))).toBe(false);
  expect(mayViewOrderCommercial(undefined)).toBe(false);
  expect(mayViewOrderCommercial(null)).toBe(false);
});

test("an order the server marks restricted prints no prices, totals or terms, even if the object carries them and the person holds the general permission", () => {
  const document = printFor(order({ commercial_visible: false }));
  expect(document.commercial).toBe(false);
  expect(document.lines[0]).not.toHaveProperty("price");
  expect(document.lines[0]).not.toHaveProperty("amount");
  expect(document).not.toHaveProperty("total");
  const text = JSON.stringify(document);
  expect(text).not.toMatch(/Payment terms/i);
  expect(text).not.toContain("42.5");
  expect(text).not.toContain("86.0000");
  expect(text).not.toContain("90 days"); // the supplier's live default never fills the gap
});

test("the same order printed where the server shows its money carries the prices, the total and its own terms", () => {
  const document = printFor(order({ commercial_visible: true }));
  expect(document.commercial).toBe(true);
  expect(document.lines[0]).toMatchObject({ price: "42.5000", amount: "86.0000" });
  expect(document).toMatchObject({ total: "86.0000" });
  expect(document.metadata.find((m: { label: string }) => m.label === "Payment terms")?.value).toBe("45 days (agreed on this order)");
});
