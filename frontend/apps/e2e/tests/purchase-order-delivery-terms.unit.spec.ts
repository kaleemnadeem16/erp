import { expect, test } from "@playwright/test";
import { buildSync } from "esbuild";
import { runInNewContext } from "node:vm";

/**
 * ADR 0191: the purchase-order form's four new facts reach the API in exactly the shape the server expects — optional, blank meaning "none"
 * (and, for payment terms, "follow the supplier"), and a delivery location never sent without its warehouse.
 */
const bundle = buildSync({
  stdin: {
    contents: `export { toPurchaseOrderPayload } from "./purchase-order-form";`,
    resolveDir: new URL("../../../packages/core-ui/src/features/purchase-orders/", import.meta.url).pathname,
    loader: "ts",
  },
  bundle: true,
  platform: "browser",
  format: "iife",
  globalName: "po",
  jsx: "automatic",
  write: false,
  loader: { ".css": "empty", ".svg": "empty", ".png": "empty" },
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
  logLevel: "silent",
}).outputFiles[0]!.text;

const base = {
  supplier_account_id: "4",
  supplier_site_id: "",
  currency_id: "2",
  fx_policy: "FIXED",
  fx_rate: "1.000000000",
  fx_rate_date: "2026-11-16",
  fx_rate_type: "MANUAL",
  fx_rate_source: "Manual entry",
  order_date: "2026-11-16",
  expected_delivery_date: "",
  notes: "",
  ship_to_warehouse_id: "",
  ship_to_location_id: "",
  buyer_user_id: "",
  payment_terms_days: "",
  shipping_method: "",
  lines: [{ item_id: "1", description: "Item", ordered_qty: "1.0000", purchase_uom_id: "1", unit_price: "1.0000", discount_amount: "0", tax_amount: "0", expected_delivery_date: "", qa_required: false }],
};

function payload(values: Record<string, unknown>): Record<string, unknown> {
  const context: Record<string, unknown> = { window: {}, document: {}, navigator: {}, localStorage: {}, sessionStorage: {} };
  context.globalThis = context;
  runInNewContext(`${bundle}\nglobalThis.__result = po.toPurchaseOrderPayload(${JSON.stringify({ ...base, ...values })});`, context);
  return context.__result as Record<string, unknown>;
}

test("blank delivery, buyer, terms and method are sent as none", () => {
  const sent = payload({});
  expect([sent.ship_to_warehouse_id, sent.ship_to_location_id, sent.buyer_user_id, sent.payment_terms_days, sent.shipping_method]).toEqual([null, null, null, null, null]);
});

test("the four facts are sent as typed values, trimmed", () => {
  const sent = payload({ ship_to_warehouse_id: "7", ship_to_location_id: "9", buyer_user_id: "3", payment_terms_days: " 45 ", shipping_method: "  Sea freight " });
  expect([sent.ship_to_warehouse_id, sent.ship_to_location_id, sent.buyer_user_id, sent.payment_terms_days, sent.shipping_method]).toEqual([7, 9, 3, 45, "Sea freight"]);
});

test("zero days is a real term, not 'none', and a location never travels without its warehouse", () => {
  expect(payload({ payment_terms_days: "0" }).payment_terms_days).toBe(0);
  expect(payload({ ship_to_warehouse_id: "", ship_to_location_id: "9" }).ship_to_location_id).toBeNull();
});
