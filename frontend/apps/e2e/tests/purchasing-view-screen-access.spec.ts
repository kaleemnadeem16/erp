import { expect, test } from "@playwright/test";

test("a PO view-only member can browse POs without purchasing actions", async ({
  page,
}) => {
  await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    await route.fulfill({
      response,
      body: JSON.stringify({
        ...body,
        data: {
          ...body.data,
          permissions: ["purchasing.purchase-order.view"],
        },
      }),
    });
  });

  await page.goto("/");
  await expect(page).toHaveURL("/purchase-orders");
  await expect(
    page
      .getByRole("navigation")
      .getByRole("link", { name: "Purchase Orders" })
      .first(),
  ).toBeVisible();
  await expect(
    page.getByRole("navigation").getByRole("link", { name: "Goods Receipts" }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "New purchase order" }),
  ).toHaveCount(0);
  await page.goto("/purchase-orders/new");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
  await page.goto("/purchase-orders/123/edit");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
});
