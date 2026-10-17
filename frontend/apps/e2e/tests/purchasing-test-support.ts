import { expect, type Page } from "@playwright/test";
import { api, findByCode } from "./package-stock-support";

/** Create the print test's own draft instead of relying on a long-lived local database ID. */
export async function createPrintOrder(page: Page): Promise<number> {
  await page.goto("/");
  const item = await findByCode<any>(page, "/items", "demo-pig-blue");
  const supplier = await findByCode<any>(page, "/supplier-accounts", "sup-punjab");
  const currency = await findByCode<any>(page, "/currencies", "PKR");
  expect(item && supplier && currency).toBeTruthy();
  const date = new Date().toISOString().slice(0, 10);
  const order = (
    await api(page, "POST", "/purchase-orders", {
      supplier_account_id: supplier.id,
      currency_id: currency.id,
      fx_rate: "1.000000000",
      fx_rate_date: date,
      fx_rate_type: "SPOT",
      fx_rate_source: "manual",
      order_date: date,
      notes: "Browser print fixture: one pigment line worth exactly 95,000.",
      lines: [
        {
          line_number: 1,
          item_id: item.id,
          description: item.name,
          ordered_qty: "200.0000",
          purchase_uom_id: item.stock_uom_id,
          unit_price: "475.0000",
        },
      ],
    })
  ).data;
  return order.id;
}

/** Find the seeded submitted approval scenario by its marker, not its database sequence. */
export async function pendingReviewOrder(page: Page): Promise<number> {
  await page.goto("/");
  const orders = (await api(page, "GET", "/purchase-orders?filter[approval]=PENDING&per_page=100")).data;
  const order = orders.find(
    (row: { notes: string; approval_status: string }) =>
      row.notes?.includes("DEMO_APPROVAL_A") &&
      row.approval_status === "PENDING",
  );
  expect(order, "Seeded pending purchase-order review").toBeTruthy();
  return order.id;
}

/** Locate the thinner draft by its line item; later seeders may add other draft receipts. */
export async function thinnerDraftReceipt(page: Page): Promise<number> {
  const thinner = await findByCode<any>(page, "/items", "demo-thinner");
  expect(thinner).toBeTruthy();
  const receipts = (
    await api(
      page,
      "GET",
      "/goods-receipts?filter[status]=DRAFT&include=lines&per_page=100",
    )
  ).data;
  const receipt = receipts.find((row: { lines: { item_id: number }[] }) =>
    row.lines?.some((line) => line.item_id === thinner.id),
  );
  expect(receipt, "Seeded thinner draft receipt").toBeTruthy();
  return receipt.id;
}
