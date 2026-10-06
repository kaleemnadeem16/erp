import { test, expect } from "@playwright/test";
import { pickCombobox } from "./support";

test("mobile active filters have their own row, animate and retain filters when hidden", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(
    "/purchase-orders?filter.status=DRAFT&filter.approval=PENDING&filter.search=pigment",
  );
  const toggle = page.getByRole("button", { name: /Active filters.*Hide/ });
  await expect(toggle).toBeVisible();
  const chips = page.locator("#po-active-filters");
  await expect(
    chips.getByRole("button", { name: /Remove filter/ }).first(),
  ).toBeVisible();
  const chipBox = await chips.boundingBox();
  const columnsBox = await page
    .getByRole("button", { name: "Columns" })
    .boundingBox();
  expect(chipBox!.width).toBeGreaterThan(300);
  expect(chipBox!.y + chipBox!.height).toBeLessThanOrEqual(columnsBox!.y + 1);
  await page.screenshot({ path: "/tmp/erp-mobile-filters.png" });
  await toggle.click();
  await expect(chips).toHaveAttribute("aria-hidden", "true");
  await expect(chips).toHaveClass(/grid-rows-\[0fr\]/);
  await expect(page).toHaveURL(/filter.status=DRAFT/);
  await page.getByRole("button", { name: /Active filters.*Show/ }).click();
  await expect(chips).toHaveAttribute("aria-hidden", "false");
  await expect(chips).toHaveClass(/grid-rows-\[1fr\]/);
  await page.getByRole("button", { name: /Active filters.*Hide/ }).click();
  await page.setViewportSize({ width: 1280, height: 900 });
  await expect(chips).toHaveAttribute("aria-hidden", "false");
  await expect(
    chips.getByRole("button", { name: /Remove filter/ }).first(),
  ).toBeVisible();
});

test("custom fields settings creates definitions and item form renders typed values and all mappings", async ({
  page,
}) => {
  const definitions: Record<string, unknown>[] = [
    {
      id: 901,
      entity_type: "items",
      code: "manufacturer",
      label: "Manufacturer",
      data_type: "string",
      choices: [],
      is_required: false,
      sort_order: 0,
    },
    {
      id: 902,
      entity_type: "items",
      code: "flammable",
      label: "Flammable",
      data_type: "bool",
      choices: [],
      is_required: false,
      sort_order: 1,
    },
    {
      id: 903,
      entity_type: "items",
      code: "finish",
      label: "Finish",
      data_type: "select",
      choices: ["Matt", "Gloss"],
      is_required: false,
      sort_order: 2,
    },
  ];
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const json = await response.json();
    json.data.permissions = [
      ...json.data.permissions,
      "administration.custom-field.manage",
    ];
    await route.fulfill({ response, json });
  });
  let itemPayload: Record<string, unknown> | undefined;
  let savedItem: Record<string, unknown> = {};
  await page.route("**/api/v1/items", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    itemPayload = route.request().postDataJSON();
    savedItem = {
      ...itemPayload,
      id: 918,
      valuation_method: "FIFO",
      avco_pool_scope: null,
      tracking: "NONE",
      status: "ACTIVE",
    };
    await route.fulfill({ status: 201, json: { data: savedItem } });
  });
  await page.route("**/api/v1/items/918**", (route) =>
    route.fulfill({ json: { data: savedItem } }),
  );
  await page.route("**/api/v1/custom-field-definitions**", async (route) => {
    if (route.request().method() === "POST") {
      const input = route.request().postDataJSON();
      expect(input.entity_type).toBe("items");
      expect(input.code).toBe("supplier_grade");
      const field = { ...input, id: 904, choices: [], sort_order: 3 };
      definitions.push(field);
      await route.fulfill({ status: 201, json: { data: field } });
    } else await route.fulfill({ json: { data: definitions } });
  });
  await page.goto("/settings/custom-fields");
  await expect(
    page.getByRole("heading", { name: "Custom fields", exact: true }),
  ).toBeVisible();
  await page.getByLabel("Label", { exact: true }).fill("Supplier grade");
  await page.getByLabel("Code", { exact: true }).fill("supplier_grade");
  await page.getByRole("button", { name: "Add field" }).click();
  await expect(page.getByRole("status")).toContainText("Field added");
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/items/new");
  await expect(page.getByLabel("Manufacturer")).toBeVisible();
  await page.getByLabel("Manufacturer").fill("Example manufacturer");
  await page.getByLabel("Flammable").check();
  await expect(page.getByLabel("Income account")).toBeVisible();
  await expect(page.getByLabel("Expense account")).toBeVisible();
  await expect(page.getByLabel("Inventory account")).toBeVisible();
  await expect(page.getByLabel("Status", { exact: true })).toBeVisible();
  const colors = await page
    .locator("form .detail-section")
    .first()
    .evaluate((el) => ({
      border: getComputedStyle(el).borderTopWidth,
      title: getComputedStyle(el.querySelector("h2")!).color,
    }));
  expect(colors.border).toBe("3px");
  expect(colors.title).not.toBe("");
  await page
    .getByRole("heading", { name: "New item", exact: true })
    .scrollIntoViewIfNeeded();
  await page.screenshot({ path: "/tmp/erp-mobile-item-form.png" });
  await page.getByLabel("Code", { exact: true }).fill("ui-custom-item");
  await page.getByLabel("Name", { exact: true }).fill("Custom-field item");
  await pickCombobox(page, "Category", /./);
  await pickCombobox(page, "Stock unit of measure", /./);
  await page.getByRole("button", { name: "Create item", exact: true }).click();
  await expect(page).toHaveURL("/items/918");
  expect(itemPayload?.custom_fields).toEqual({
    manufacturer: "Example manufacturer",
    flammable: true,
  });
  await expect(
    page.getByRole("heading", { name: "Custom-field item", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Example manufacturer", { exact: true }),
  ).toBeVisible();
});

test("supplier registration preserves a newly saved trading partner after supplier validation fails", async ({
  page,
}) => {
  let partyWrites = 0;
  let supplierWrites = 0;
  await page.route("**/api/v1/parties", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    partyWrites++;
    await route.fulfill({
      status: 201,
      json: { data: { id: 909, ...route.request().postDataJSON() } },
    });
  });
  await page.route("**/api/v1/parties/909**", (route) =>
    route.fulfill({
      json: { data: { id: 909, code: "ui-partner", name: "UI supplier" } },
    }),
  );
  await page.route("**/api/v1/supplier-accounts", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    supplierWrites++;
    expect(route.request().postDataJSON().party_id).toBe(909);
    if (supplierWrites === 1)
      await route.fulfill({
        status: 422,
        json: {
          message: "Supplier code already exists.",
          errors: { code: ["Supplier code already exists."] },
        },
      });
    else await route.fulfill({ status: 201, json: { data: { id: 910 } } });
  });
  await page.goto("/suppliers/new");
  await page
    .getByRole("button", { name: "Register a new trading partner" })
    .click();
  await page.getByLabel("Trading partner code").fill("ui-partner");
  await page.getByLabel("Business name").fill("UI supplier");
  await page.getByLabel("Supplier code", { exact: true }).fill("ui-supplier");
  await pickCombobox(page, "Payable account", /./);
  await page
    .getByRole("button", { name: "Register supplier", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText(
    "Supplier code already exists.",
  );
  await expect(
    page.getByRole("combobox", { name: "Existing trading partner" }),
  ).toContainText("UI supplier");
  await page.getByLabel("Supplier code", { exact: true }).fill("ui-supplier-2");
  await page
    .getByRole("button", { name: "Register supplier", exact: true })
    .click();
  await expect(page).toHaveURL("/suppliers");
  expect(partyWrites).toBe(1);
  expect(supplierWrites).toBe(2);
});

test("a fully allocated draft receipt remains editable using its saved order beyond the first lookup page", async ({
  page,
}) => {
  let writes = 0;
  const receipt = {
    id: 909,
    number: null,
    status: "DRAFT",
    supplier_account_id: 1,
    physical_arrival_at: "2026-10-06T09:30:00Z",
    posting_date: "2026-10-06",
    lines: [
      {
        id: 9901,
        purchase_order_id: 901,
        purchase_order_line_id: 9911,
        item_id: 1,
        location_id: 1,
        received_qty: "2.0000",
        received_uom_id: 1,
        stock_qty: "2.0000",
        stock_uom_id: 1,
        uom_factor: "1.000000000",
        item_packaging_id: null,
        batch_no: null,
        serial_no: null,
        qa_unallocated_qty: null,
        qa_unresolved_qty: null,
      },
    ],
  };
  await page.route("**/api/v1/purchase-orders?**", (route) =>
    route.fulfill({
      json: {
        data: [],
        meta: { current_page: 1, last_page: 1, total: 0 },
        links: {},
      },
    }),
  );
  await page.route("**/api/v1/purchase-orders/901?**", (route) =>
    route.fulfill({
      json: {
        data: {
          id: 901,
          number: "PO-OLD-901",
          status: "ISSUED",
          supplier_account_id: 1,
          currency_id: 1,
          lines: [
            {
              id: 9911,
              item_id: 1,
              item_code: "DEMO",
              description: "Previously allocated material",
              outstanding_stock_qty: "0.0000",
              purchase_uom_id: 1,
              stock_uom_id: 1,
              uom_factor: "1.000000000",
              item_packaging_id: null,
            },
          ],
        },
      },
    }),
  );
  await page.route("**/api/v1/goods-receipts/909", async (route) => {
    if (route.request().method() === "PATCH") {
      const payload = route.request().postDataJSON();
      expect(payload.lines[0].id).toBe(9901);
      expect(payload.lines[0].purchase_order_line_id).toBe(9911);
      expect(payload.lines[0].received_qty).toBe("1.0000");
      expect(payload.lines[0].fx_rate).toBeUndefined();
      writes++;
      receipt.lines[0].received_qty = payload.lines[0].received_qty;
    }
    await route.fulfill({ json: { data: receipt } });
  });
  await page.goto("/goods-receipts/909/edit");
  await expect(
    page.getByRole("combobox", { name: "Purchase-order line" }),
  ).toContainText("PO-OLD-901");
  await page.getByLabel("Received quantity").fill("1.0000");
  await page.getByRole("button", { name: "Save draft receipt" }).click();
  await expect(page).toHaveURL("/goods-receipts/909");
  expect(writes).toBe(1);
});

test("bookmarked new screens enforce duties and item forms hide Finance fields without access", async ({
  page,
}) => {
  const denied = new Set([
    "administration.custom-field.manage",
    "parties.payment-terms.manage",
    "accounting.account-mapping.manage",
    "accounting.account-mapping.view",
    "purchasing.goods-receipt.create",
  ]);
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const json = await response.json();
    json.data.permissions = json.data.permissions.filter(
      (permission: string) => !denied.has(permission),
    );
    await route.fulfill({ response, json });
  });
  for (const path of [
    "/suppliers/new",
    "/settings/custom-fields",
    "/goods-receipts/909/edit",
  ]) {
    await page.goto(path);
    await expect(
      page.getByRole("heading", { name: "You don’t have access" }),
    ).toBeVisible();
  }
  await page.goto("/items/new");
  await expect(
    page.getByRole("heading", { name: "New item", exact: true }),
  ).toBeVisible();
  await expect(page.getByLabel("Income account")).toHaveCount(0);
  await expect(page.getByLabel("Expense account")).toHaveCount(0);
  await expect(page.getByLabel("Inventory account")).toHaveCount(0);
});
