import { expect, test } from "@playwright/test";
import { pickCombobox } from "./support";
import { lineAmounts } from "../../../packages/core-ui/src/features/purchase-orders/purchase-order-amounts";

test("independent price quantity keeps exact displayed money", () => {
  expect(
    lineAmounts({
      ordered_qty: "10",
      item_packaging_id: "1",
      priced_qty: "20.000000000",
      unit_price: "850",
      price_quantity: "1",
    })?.total,
  ).toBe(170000000n);
  expect(
    lineAmounts({
      ordered_qty: "10",
      priced_qty: "1000.000000000",
      unit_price: "850",
      price_quantity: "100",
    })?.total,
  ).toBe(85000000n);
  expect(
    lineAmounts({
      ordered_qty: "2",
      unit_price: "10",
      discount_amount: "1",
      tax_amount: "2",
    })?.total,
  ).toBe(210000n);
});

test("buyer selects a named package and sees the server conversion and price basis", async ({
  page,
}) => {
  await page.route("**/api/v1/item-packagings?*", async (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 900001,
            item_id: 1,
            code: "box20",
            name: "Box 20 small bottles",
            stock_quantity: "2.000000000",
            quantity_precision: "1.000000000",
            barcode: null,
            inner_packaging_id: null,
            inner_count: null,
            active: true,
          },
        ],
        meta: { current_page: 1, last_page: 1, total: 1 },
        links: {},
      },
    }),
  );
  await page.route("**/api/v1/supplier-offers?*", async (route) =>
    route.fulfill({
      json: {
        data: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
        links: {},
      },
    }),
  );
  await page.route("**/api/v1/purchase-order-lines/preview", async (route) => {
    const data = route.request().postDataJSON();
    const l = data.lines[0];
    const packed = !!l.item_packaging_id;
    await route.fulfill({
      json: {
        data: {
          ...l,
          id: 0,
          item_code: null,
          supplier_item_code: null,
          package_name: packed ? "Box 20 small bottles" : null,
          package_code: packed ? "box20" : null,
          order_unit_label: packed ? "Box 20 small bottles" : "kg",
          stock_uom_id: l.purchase_uom_id,
          uom_factor: packed ? "2.000000000" : "1.000000000",
          ordered_stock_qty: packed ? "20.0000" : "10.0000",
          priced_qty: packed ? "20.000000000" : "10.000000000",
          price_quantity: "1.0000",
          price_unit_label: l.price_uom_id ? "kg" : "Box 20 small bottles",
          price_stock_factor: "1.000000000",
          line_amount: packed ? "17000.0000" : "8500.0000",
          minimum_order_qty: null,
          order_multiple: null,
          received_stock_qty: "0",
          outstanding_stock_qty: "20",
          short_closed_stock_qty: "0",
          qa_required: false,
        },
      },
    });
  });
  await page.goto("/purchase-orders/new");
  const sheet = page.getByRole("dialog", { name: "New purchase order" });
  await pickCombobox(page, "Supplier", "Industrial Coatings Ltd", {
    within: sheet,
  });
  await sheet.getByRole("button", { name: "Next" }).click();
  await pickCombobox(page, "Item", "Phthalo Blue Pigment", { within: sheet });
  // Package and price basis sit under the line's "More details".
  await sheet.getByRole("button", { name: "More details for this line" }).click();
  await sheet
    .getByLabel("Package or loose", { exact: true })
    .selectOption("900001");
  await sheet
    .getByLabel("Price basis", { exact: true })
    .selectOption({ label: "kg" });
  await sheet.getByLabel("Quantity", { exact: true }).fill("10");
  await sheet.getByLabel("Unit price", { exact: true }).fill("850");
  await expect(sheet.getByText(/in stock/)).toContainText("20 kg");
  await expect(sheet.getByLabel("Line total", { exact: true })).toContainText("17000.0000");
  await expect(
    sheet.getByLabel("Purchase UoM", { exact: true }),
  ).toBeDisabled();
});
