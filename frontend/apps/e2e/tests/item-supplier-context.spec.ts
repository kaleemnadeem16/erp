import { expect, test } from "@playwright/test";
import { pickCombobox } from "./support";

test("item-scoped supplier create/edit/archive/restore preserves identity and ignores URL filters", async ({
  page,
}) => {
  await page.goto("/items?filter.search=demo-pack-thinner");
  await page
    .locator("tbody tr")
    .filter({ hasText: "demo-pack-thinner" })
    .first()
    .click();
  const itemPath = new URL(page.url()).pathname;
  const itemId = Number(itemPath.split("/").pop());
  let writes = 0;
  const requests: URL[] = [];
  const rows = [
    {
      id: 777001,
      item_id: itemId,
      supplier_account_id: 2,
      supplier_item_code: "LOCAL-OLD",
      priority: 1,
      lead_time_days: 4,
      resolved_lead_time_days: 4,
      minimum_order_qty: "5.0000",
      purchase_uom_id: null,
      active: true,
      supplier_account: { id: 2, code: "supplier-demo" },
    },
  ];
  await page.route("**/api/v1/item-suppliers**", async (route) => {
    const request = route.request();
    if (request.method() === "GET") {
      requests.push(new URL(request.url()));
      await route.fulfill({
        json: {
          data: rows,
          meta: { current_page: 1, last_page: 1, total: rows.length },
          links: {},
        },
      });
    } else if (request.method() === "POST") {
      const input = request.postDataJSON();
      expect(input.item_id).toBe(itemId);
      writes++;
      const row = {
        ...rows[0],
        ...input,
        id: 777002,
        supplier_account: {
          id: input.supplier_account_id,
          code: "new-supplier",
        },
      };
      rows.push(row);
      await route.fulfill({ status: 201, json: { data: row } });
    } else if (request.method() === "PATCH") {
      const input = request.postDataJSON();
      expect(input.item_id).toBeUndefined();
      expect(input.supplier_account_id).toBeUndefined();
      const row = rows.find((row) => request.url().endsWith(String(row.id)))!;
      Object.assign(row, input);
      writes++;
      await route.fulfill({ json: { data: row } });
    } else await route.continue();
  });
  await page.goto(itemPath + "?filter.item_id=999999&tab=suppliers");
  const panel = page.getByRole("region", { name: "Supplier relationships" });
  await expect(panel.getByText("supplier-demo · Active")).toBeVisible();
  await panel
    .getByRole("button", { name: "Add supplier relationship" })
    .click();
  await expect(panel.getByLabel("Item", { exact: true })).toHaveCount(0);
  await pickCombobox(page, "Supplier Account", "Punjab Pigments", { within: panel });
  await panel
    .getByLabel("Supplier Item Code (SKU / Part #)", { exact: true })
    .fill("NEW-PIGMENT");
  await panel.getByRole("button", { name: /^(Create relationship|Save sourcing terms)$/ }).click();
  await expect(panel.getByText("new-supplier · Active")).toBeVisible();
  await panel
    .getByRole("button", {
      name: "Edit relationship supplier-demo",
      exact: true,
    })
    .click();
  // An existing relationship shows its supplier as a locked card, not an editable picker.
  await expect(panel.getByText("Locked").first()).toBeVisible();
  await panel.getByLabel("Lead time override (days)").fill("9");
  await panel.getByRole("button", { name: /^(Create relationship|Save sourcing terms)$/ }).click();
  await expect(
    panel.getByRole("heading", { name: "Edit supplier relationship" }),
  ).toHaveCount(0);
  await panel
    .getByRole("button", {
      name: "Archive relationship supplier-demo",
      exact: true,
    })
    .click();
  await expect(panel.getByText("supplier-demo · Archived")).toBeVisible();
  await panel
    .getByRole("button", {
      name: "Restore relationship supplier-demo",
      exact: true,
    })
    .click();
  await expect(panel.getByText("supplier-demo · Active")).toBeVisible();
  expect(writes).toBe(4);
  expect(requests.length).toBeGreaterThan(0);
  expect(
    requests.every(
      (url) => url.searchParams.get("filter[item_id]") === String(itemId),
    ),
  ).toBe(true);
});

test("read-only supplier access shows relationships without mutation controls", async ({
  page,
}) => {
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    // /user returns the user directly; retain the other modules' navigation capabilities.
    const user = body.data ?? body;
    user.permissions = user.permissions.filter(
      (permission: string) => permission !== "purchasing.item-supplier.manage",
    );
    if (!user.permissions.includes("purchasing.item-supplier.view"))
      user.permissions.push("purchasing.item-supplier.view");
    await route.fulfill({ response, json: body });
  });
  await page.goto("/items?filter.search=demo-pack-thinner");
  await page
    .locator("tbody tr")
    .filter({ hasText: "demo-pack-thinner" })
    .first()
    .click();
  await page.getByRole("tab", { name: "Suppliers & Sourcing" }).click();
  const panel = page.getByRole("region", { name: "Supplier relationships" });
  await expect(
    panel.getByRole("heading", { name: "Supplier relationships" }),
  ).toBeVisible();
  await expect(
    panel.getByRole("button", { name: "Add supplier relationship" }),
  ).toHaveCount(0);
  await expect(
    panel.getByRole("button", {
      name: /Edit relationship|Archive relationship|Restore relationship/,
    }),
  ).toHaveCount(0);
});

test("supplier panel reports failures instead of claiming the item has no suppliers", async ({
  page,
}) => {
  await page.route("**/api/v1/item-suppliers?**", (route) =>
    route.fulfill({
      status: 403,
      json: { message: "Supplier relationships unavailable" },
    }),
  );
  await page.goto("/items?filter.search=demo-pack-thinner");
  await page
    .locator("tbody tr")
    .filter({ hasText: "demo-pack-thinner" })
    .first()
    .click();
  await page.getByRole("tab", { name: "Suppliers & Sourcing" }).click();
  const panel = page.getByRole("region", { name: "Supplier relationships" });
  await expect(panel.getByRole("alert")).toContainText(
    "Supplier relationships unavailable",
  );
  await expect(
    panel.getByText("No supplier relationships for this item."),
  ).toHaveCount(0);
});

test("supplier relationship pagination stays on this item", async ({
  page,
}) => {
  await page.route("**/api/v1/item-suppliers?**", async (route) => {
    const url = new URL(route.request().url());
    const current = Number(url.searchParams.get("page") ?? 1);
    const itemId = Number(url.searchParams.get("filter[item_id]"));
    await route.fulfill({
      json: {
        data: [
          {
            id: 777000 + current,
            item_id: itemId,
            supplier_account_id: current,
            active: true,
            supplier_item_code: null,
            priority: current,
            lead_time_days: null,
            resolved_lead_time_days: null,
            minimum_order_qty: null,
            purchase_uom_id: null,
            supplier_account: { id: current, code: "page-supplier-" + current },
          },
        ],
        meta: { current_page: current, last_page: 2, total: 26 },
        links: {},
      },
    });
  });
  await page.goto("/items?filter.search=demo-pack-thinner");
  await page
    .locator("tbody tr")
    .filter({ hasText: "demo-pack-thinner" })
    .first()
    .click();
  await page.getByRole("tab", { name: "Suppliers & Sourcing" }).click();
  const itemId = new URL(page.url()).pathname.split("/").pop();
  const panel = page.getByRole("region", { name: "Supplier relationships" });
  await expect(panel.getByText("page-supplier-1 · Active")).toBeVisible();
  const response = page.waitForResponse(
    (response) =>
      response.url().includes("/api/v1/item-suppliers?") &&
      new URL(response.url()).searchParams.get("page") === "2",
  );
  await panel.getByRole("button", { name: "Next suppliers" }).click();
  expect(
    new URL((await response).url()).searchParams.get("filter[item_id]"),
  ).toBe(itemId);
  await expect(panel.getByText("page-supplier-2 · Active")).toBeVisible();
  await expect(
    panel.getByRole("button", { name: "Next suppliers" }),
  ).toBeDisabled();
});

test("users without Purchasing read access do not load relationships or offers", async ({
  page,
}) => {
  await page.route("**/api/v1/user", async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    const user = body.data ?? body;
    user.permissions = user.permissions.filter(
      (permission: string) => !permission.startsWith("purchasing."),
    );
    await route.fulfill({ response, json: body });
  });
  const reads: string[] = [];
  page.on("request", (request) => {
    if (/\/api\/v1\/(item-suppliers|supplier-offers)/.test(request.url()))
      reads.push(request.url());
  });
  await page.goto("/items?filter.search=demo-pack-thinner");
  await page
    .locator("tbody tr")
    .filter({ hasText: "demo-pack-thinner" })
    .first()
    .click();
  await page.getByRole("tab", { name: "Suppliers & Sourcing" }).click();
  await expect(
    page.getByRole("heading", { name: "Purchasing packages", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("region", { name: "Supplier relationships" }),
  ).toHaveCount(0);
  expect(reads).toEqual([]);
});
