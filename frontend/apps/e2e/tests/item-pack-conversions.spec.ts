import { expect, test } from "@playwright/test";

test("item details keep pack conversions scoped even with a conflicting URL filter", async ({
  page,
}) => {
  await page.goto("/items?filter.search=demo-thinner");
  await page
    .locator("tbody tr").filter({ hasText: "demo-thinner" })
    .first()
    .click();
  await expect(page).toHaveURL(/\/items\/\d+/);
  const itemPath = new URL(page.url()).pathname;
  const itemId = Number(itemPath.split("/").pop());
  await expect(page.getByRole("heading", { name: "Pack conversions", exact: true })).toBeVisible();
  await expect(page.locator("tbody tr").filter({ hasText: "200.000000000" }).first()).toBeVisible();
  const response = page.waitForResponse((r) =>
    r.url().includes("/api/v1/item-quantity-units?"),
  );
  await page.goto(`${itemPath}?filter.item_id=999999`);
  const conversions = await response;
  expect(conversions.status()).toBe(200);
  expect(new URL(conversions.url()).searchParams.get("filter[item_id]")).toBe(
    String(itemId),
  );
  const body = await conversions.json();
  expect(body.data.length).toBeGreaterThan(0);
  expect(
    body.data.every((row: { item_id: number }) => row.item_id === itemId),
  ).toBe(true);
  await expect(
    page.getByRole("heading", { name: "Pack conversions", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("1 pack unit = factor", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: "Filter by item" }),
  ).toHaveCount(0);
  await expect(page.getByLabel("Item", { exact: true })).toHaveCount(0);
  await page.getByRole("link", { name: "Edit", exact: true }).first().click();
  await expect(
    page.getByLabel("Stock quantity increment (optional)"),
  ).toBeVisible();
});

test("conversion panel reports API failure instead of claiming there are no conversions", async ({
  page,
}) => {
  await page.goto("/items?filter.search=demo-thinner");
  await page
    .locator("tbody tr").filter({ hasText: "demo-thinner" })
    .first()
    .click();
  await page.route("**/api/v1/item-quantity-units?**", (route) =>
    route.fulfill({
      status: 403,
      contentType: "application/json",
      body: JSON.stringify({ message: "Conversion access denied" }),
    }),
  );
  await page.reload();
  await expect(
    page.getByText("Conversion access denied", { exact: false }).first(),
  ).toBeVisible();
  await expect(
    page.getByText("No pack conversions yet.", { exact: true }),
  ).toHaveCount(0);
});
