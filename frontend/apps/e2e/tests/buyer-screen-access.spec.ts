import { expect, test } from "@playwright/test";

const buyerPermissions = [
  "purchasing.purchase-order.create",
  "purchasing.item-supplier.manage",
];

test.beforeEach(async ({ page }) => {
  // Keep the real authenticated session, but present exactly the starter
  // Buyer's effective permissions from /user to the SPA.
  await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    await route.fulfill({
      response,
      body: JSON.stringify({
        ...body,
        data: { ...body.data, permissions: buyerPermissions },
      }),
    });
  });
});

test("a Buyer lands in purchasing and cannot open unrelated screens by URL", async ({
  page,
}) => {
  await page.goto("/");
  await expect(page).toHaveURL("/purchase-orders");
  await expect(
    page
      .getByRole("navigation")
      .getByRole("link", { name: "Purchase Orders" })
      .first(),
  ).toBeVisible();
  await expect(
    page
      .getByRole("navigation")
      .getByRole("link", { name: "Item Suppliers" })
      .first(),
  ).toBeVisible();
  await expect(
    page.getByRole("navigation").getByRole("link", { name: "Dashboard" }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("navigation").getByRole("link", { name: "Goods Receipts" }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("navigation").getByRole("link", { name: "Items" }),
  ).toHaveCount(0);

  let receiptRequests = 0;
  page.on("request", (request) => {
    if (/\/api\/v1\/goods-receipts(?:\?|$)/.test(request.url()))
      receiptRequests += 1;
  });

  await page.goto("/goods-receipts");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
  expect(receiptRequests).toBe(0);
  await page.goto("/settings/members");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
  await page.goto("/settings/printing");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
});

test("the Buyer mobile tabs contain only allowed work areas", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");
  await expect(page).toHaveURL("/purchase-orders");
  const bottom = page.locator("nav.fixed");
  await expect(
    bottom.getByRole("link", { name: "Purchase Orders" }),
  ).toBeVisible();
  await expect(bottom.getByRole("link", { name: "Dashboard" })).toHaveCount(0);
  await expect(bottom.getByRole("link", { name: "Items" })).toHaveCount(0);
  await expect(
    bottom.getByRole("link", { name: "Goods Receipts" }),
  ).toHaveCount(0);
  await bottom.getByRole("button", { name: "More" }).click();
  await expect(
    page.getByRole("link", { name: "Item Suppliers" }),
  ).toBeVisible();
  await expect(page.getByRole("link", { name: "Members" })).toHaveCount(0);
});
