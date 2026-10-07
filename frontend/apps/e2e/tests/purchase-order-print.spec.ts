import { expect, test } from "@playwright/test";
import { createPrintOrder } from "./purchasing-test-support";

let orderId: number;
test.beforeEach(async ({ page }) => {
  orderId = await createPrintOrder(page);
});

test("PO print uses real company data, amount columns and one authoritative total", async ({
  page,
}) => {
  await page.setViewportSize({ width: 794, height: 1123 });
  await page.route("**/api/v1/company", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    Object.assign(body.data.values, {
      name: "Coating Works",
      legal_name: "Coating Works (Pvt) Ltd",
      address_line1: "42 Works Road",
      city: "Lahore",
      tax_number: "TEST-TAX-42",
    });
    await route.fulfill({ response, json: body });
  });
  await page.route(`**/api/v1/purchase-orders/${orderId}?*`, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.supplier_site = {
      id: 88001,
      supplier_account_id: body.data.supplier_account_id,
      role: "ORDERING",
      is_default: true,
      party_site: {
        id: 88002,
        party_id: 88003,
        code: "main",
        name: "Main office",
        address_line1: "18 Industrial Road",
        address_line2: null,
        city: "Lahore",
        state: "Punjab",
        postal_code: "54000",
        country_code: "PK",
        status: "ACTIVE",
      },
    };
    await route.fulfill({ response, json: body });
  });
  await page.route("**/api/v1/supplier-accounts/*?*", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    Object.assign(body.data.party, {
      tax_id: "SUPPLIER-TAX-18",
      registration_no: "SUPPLIER-REG-18",
      website: "https://supplier.example",
    });
    await route.fulfill({ response, json: body });
  });
  await page.goto(`/purchase-orders/${orderId}`);
  const print = page.getByTestId("purchase-order-print");
  await expect(print).toContainText("Coating Works");
  await expect(print).toContainText("Punjab Pigments");
  await expect(print).toContainText("18 Industrial Road");
  await expect(print).toContainText("SUPPLIER-TAX-18");
  await expect(print.locator("table")).toHaveCount(1);
  await expect(print.locator(".po-print-order-details")).toContainText(
    "PO details",
  );

  await expect(print).toContainText("95,000.0000");
  await expect(print.getByText("42 Works Road", { exact: false })).toHaveCount(
    1,
  );
  await expect(print.locator(".po-print-total")).toHaveCount(1);
  await expect(print).not.toContainText("Discount (%)");
  await expect(print).not.toContainText("Demo User");
  await expect(print).toContainText("Delivery address not specified");
  await expect(print).not.toContainText("Terms and conditions");
  await expect(print).toContainText("Draft — not issued");
  await expect(print).toHaveAttribute("data-pagination-ready", "true");
  await expect(print).toHaveAttribute("data-template", "template-1");
  // Render the actual hidden print tree as paper for visual inspection and PDF output.
  await page.evaluate(() => {
    const paper = document
      .querySelector('[data-testid="purchase-order-print"]')!
      .cloneNode(true);
    document.body.replaceChildren(paper);
    document.body.style.cssText =
      "background:white;margin:0;padding:0;width:794px";
  });
  await page.emulateMedia({ media: "print" });
  for (const sheet of await print.locator(".po-print-page").all()) {
    const dimensions = await sheet.evaluate((element) => ({
      height: element.getBoundingClientRect().height,
      scroll: element.scrollHeight,
    }));
    expect(dimensions.height).toBeLessThanOrEqual(1123);
    expect(dimensions.scroll).toBeLessThanOrEqual(1123);
    const overlap = await sheet.evaluate((element) => {
      const footer = element
        .querySelector(".po-print-footer")!
        .getBoundingClientRect();
      const content = [...element.children].filter(
        (child) => !child.classList.contains("po-print-footer"),
      );
      return content.some(
        (child) => child.getBoundingClientRect().bottom > footer.top - 8,
      );
    });
    expect(overlap).toBe(false);
  }
  await page.screenshot({
    path: "test-results/po-print-preview.png",
    fullPage: true,
  });
  await page.evaluate(() => {
    document.body.style.cssText =
      "background:white;margin:0;padding:0;width:794px";
  });
  const pdf = await page.pdf({
    path: "test-results/po-print-preview.pdf",
    format: "A4",
    printBackground: true,
    margin: { top: "0", bottom: "0", left: "0", right: "0" },
  });
  expect((pdf.toString("latin1").match(/\/Type \/Page\b/g) ?? []).length).toBe(
    await print.locator(".po-print-page").count(),
  );
  const width = await print.evaluate((element) => ({
    scroll: element.scrollWidth,
    client: element.clientWidth,
  }));
  expect(width.scroll).toBeLessThanOrEqual(width.client);
});

test("PO print omits commercial fields for a quantity-only reader", async ({
  page,
}) => {
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.permissions = ["purchasing.purchase-order.view"];
    await route.fulfill({ response, json: body });
  });
  await page.goto(`/purchase-orders/${orderId}`);
  const print = page.getByTestId("purchase-order-print");
  await expect(print).toContainText("Punjab Pigments");
  await expect(
    print.getByRole("columnheader", {
      name: "Unit price",
      includeHidden: true,
    }),
  ).toHaveCount(0);
  await expect(print.locator(".po-print-total")).toHaveCount(0);
  await expect(print).not.toContainText("95,000.0000");
});

test("long PO print repeats table headings and preserves exact decimal totals", async ({
  page,
}) => {
  await page.route(`**/api/v1/purchase-orders/${orderId}?*`, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const line = body.data.lines[0];
    body.data.lines = Array.from({ length: 55 }, (_, i) => ({
      ...line,
      id: 80000 + i,
      line_number: i + 1,
      description:
        `Line ${i + 1}: coating material with a detailed supplier specification and handling instructions. `.repeat(
          3,
        ),
    }));
    body.data.total_amount = "999999999999999.0001";
    await route.fulfill({ response, json: body });
  });
  await page.goto(`/purchase-orders/${orderId}`);
  const print = page.getByTestId("purchase-order-print");
  await expect(print).toHaveAttribute("data-pagination-ready", "true");
  await expect(print.locator("tbody tr")).toHaveCount(55);
  expect(await print.locator(".po-print-page").count()).toBeGreaterThan(1);
  await expect(print.locator(".po-print-continuation").first()).toContainText(
    `Draft #${orderId}`,
  );
  await expect(print.locator(".po-print-total")).toContainText(
    "999,999,999,999,999.0001",
  );
  await page.evaluate(() => {
    const paper = document
      .querySelector('[data-testid="purchase-order-print"]')!
      .cloneNode(true);
    document.body.replaceChildren(paper);
    document.body.style.cssText =
      "background:white;margin:0;padding:0;width:794px";
  });
  await page.emulateMedia({ media: "print" });
  for (const sheet of await print.locator(".po-print-page").all()) {
    const dimensions = await sheet.evaluate((element) => ({
      height: element.getBoundingClientRect().height,
      scroll: element.scrollHeight,
    }));
    expect(dimensions.height).toBeLessThanOrEqual(1123);
    expect(dimensions.scroll).toBeLessThanOrEqual(1123);
    const overlap = await sheet.evaluate((element) => {
      const footer = element
        .querySelector(".po-print-footer")!
        .getBoundingClientRect();
      const content = [...element.children].filter(
        (child) => !child.classList.contains("po-print-footer"),
      );
      return content.some(
        (child) => child.getBoundingClientRect().bottom > footer.top - 8,
      );
    });
    expect(overlap).toBe(false);
  }
  await print
    .locator(".po-print-page")
    .nth(1)
    .screenshot({ path: "test-results/po-print-continuation.png" });
  await expect(print.locator("thead").first()).toHaveCSS(
    "display",
    "table-header-group",
  );
  const width = await print.evaluate((element) => ({
    scroll: element.scrollWidth,
    client: element.clientWidth,
  }));
  expect(width.scroll).toBeLessThanOrEqual(width.client);
  const pdf = await page.pdf({
    path: "test-results/po-print-long.pdf",
    format: "A4",
    printBackground: true,
    margin: { top: "0", bottom: "0", left: "0", right: "0" },
  });
  expect((pdf.toString("latin1").match(/\/Type \/Page\b/g) ?? []).length).toBe(
    await print.locator(".po-print-page").count(),
  );
});

test("PO print includes non-zero whole-line discount and tax columns", async ({
  page,
}) => {
  await page.route(`**/api/v1/purchase-orders/${orderId}?*`, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.lines[0].discount_amount = "125.0000";
    body.data.lines[0].tax_amount = "75.0000";
    await route.fulfill({ response, json: body });
  });
  await page.goto(`/purchase-orders/${orderId}`);
  const print = page.getByTestId("purchase-order-print");
  await expect(
    print.getByRole("columnheader", {
      name: "Discount (amount)",
      includeHidden: true,
    }),
  ).toHaveCount(1);
  await expect(
    print.getByRole("columnheader", {
      name: "Tax (amount)",
      includeHidden: true,
    }),
  ).toHaveCount(1);
  await expect(print.locator("tbody")).toContainText("125.0000");
  await expect(print.locator("tbody")).toContainText("75.0000");
});

test("PO print retains optional line specifications and supplier identifiers", async ({
  page,
}) => {
  await page.route(`**/api/v1/purchase-orders/${orderId}?*`, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    Object.assign(body.data.lines[0], {
      item_code: "PIGMENT-01",
      supplier_item_code: "SUP-PIG-01",
      package_name: "Pigment drum",
      package_code: "DRUM-25",
      expected_delivery_date: "2026-10-20",
      qa_required: true,
      ordered_qty: "2.0000",
      ordered_stock_qty: "50.0000",
      purchase_uom_id: 88001,
      stock_uom_id: 88002,
      price_uom_id: 88002,
      price_unit_label: "kg",
      price_quantity: "5.0000",
      priced_qty: "50.0000",
      discount_amount: "125.0000",
      tax_amount: "75.0000",
    });
    await route.fulfill({ response, json: body });
  });
  await page.route("**/api/v1/supplier-accounts/*?*", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    Object.assign(body.data.party, {
      tax_id: "TAX-01",
      registration_no: "REG-01",
      website: "https://supplier.example",
    });
    await route.fulfill({ response, json: body });
  });
  await page.goto(`/purchase-orders/${orderId}`);
  const print = page.getByTestId("purchase-order-print");
  for (const detail of [
    "Item PIGMENT-01",
    "Supplier code: SUP-PIG-01",
    "Package: Pigment drum · DRUM-25",
    "Expected delivery: 2026-10-20",
    "Quality inspection required",
    "Stock equivalent: 50.0000",
    "Priced quantity: 50.0000 kg",
    "per 5.0000 kg",
    "125.0000",
    "75.0000",
    "TAX-01",
    "REG-01",
    "https://supplier.example",
  ]) {
    await expect(print).toContainText(detail);
  }
});

test("Printing settings keeps Template 1 selected and previews the actual PO renderer", async ({
  page,
}) => {
  await page.goto("/settings/printing");
  await expect(
    page.getByLabel("Default template", { exact: true }),
  ).toHaveValue("template-1");
  await expect(page.getByTestId("purchase-order-print")).toHaveAttribute(
    "data-template",
    "template-1",
  );
  await expect(page.getByTestId("purchase-order-print")).toContainText(
    "Example supplier",
  );
  await page
    .getByLabel("Show purchase order signature lines", { exact: true })
    .uncheck();
  await expect(
    page.getByTestId("purchase-order-print").locator(".po-print-signatures"),
  ).toHaveCount(0);
  await page.reload();
  await expect(
    page.getByLabel("Show purchase order signature lines", { exact: true }),
  ).not.toBeChecked();
  await page
    .getByLabel("Show purchase order signature lines", { exact: true })
    .check();
});

test("Template 1 produces a two-page PO with one closing total", async ({
  page,
}) => {
  await page.route(`**/api/v1/purchase-orders/${orderId}?*`, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const line = body.data.lines[0];
    body.data.lines = Array.from({ length: 18 }, (_, index) => ({
      ...line,
      id: 90000 + index,
      line_number: index + 1,
      description: `Coating material ${index + 1}`,
    }));
    await route.fulfill({ response, json: body });
  });
  await page.goto(`/purchase-orders/${orderId}`);
  const print = page.getByTestId("purchase-order-print");
  await expect(print).toHaveAttribute("data-pagination-ready", "true");
  await expect(print.locator(".po-print-page")).toHaveCount(2);
  await expect(print.locator("tbody tr")).toHaveCount(18);
  await expect(
    print.locator(".po-print-page").first().locator(".po-print-total"),
  ).toHaveCount(0);
  await expect(
    print.locator(".po-print-page").last().locator(".po-print-total"),
  ).toHaveCount(1);
  await expect(print.locator(".po-print-page").last()).toContainText(
    "Page 2 of 2",
  );
  await page.evaluate(() => {
    const paper = document
      .querySelector('[data-testid="purchase-order-print"]')!
      .cloneNode(true);
    document.body.replaceChildren(paper);
    document.body.style.cssText =
      "background:white;margin:0;padding:0;width:794px";
  });
  await page.emulateMedia({ media: "print" });
  await print
    .locator(".po-print-page")
    .first()
    .screenshot({ path: "test-results/po-template-1-page-1.png" });
  await print
    .locator(".po-print-page")
    .last()
    .screenshot({ path: "test-results/po-template-1-page-2.png" });
  const pdf = await page.pdf({
    path: "test-results/po-template-1-two-pages.pdf",
    format: "A4",
    printBackground: true,
    margin: { top: "0", bottom: "0", left: "0", right: "0" },
  });
  expect((pdf.toString("latin1").match(/\/Type \/Page\b/g) ?? []).length).toBe(
    2,
  );
});
