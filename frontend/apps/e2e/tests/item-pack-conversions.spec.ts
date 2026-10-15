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
  await page.getByRole("tab", { name: "Packaging & Units" }).click();
  await expect(page.getByRole("heading", { name: "Pack conversions", exact: true })).toBeVisible();
  await expect(page.locator("tbody tr").filter({ hasText: "1 drum = 200" }).first()).toBeVisible();
  const response = page.waitForResponse((r) =>
    r.url().includes("/api/v1/item-quantity-units?"),
  );
  await page.goto(`${itemPath}?filter.item_id=999999&tab=packaging`);
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
  await page.getByRole("tab", { name: "Packaging & Units" }).click();
  await page.route("**/api/v1/item-quantity-units?**", (route) =>
    route.fulfill({
      status: 403,
      contentType: "application/json",
      body: JSON.stringify({ message: "Conversion access denied" }),
    }),
  );
  await page.reload();
  await page.getByRole("tab", { name: "Packaging & Units" }).click();
  await expect(
    page.getByText("Conversion access denied", { exact: false }).first(),
  ).toBeVisible();
  await expect(
    page.getByText("No pack conversions yet.", { exact: true }),
  ).toHaveCount(0);
});

test("pack conversions have an item filter with a chip, selection with bulk delete, printing, and side panels", async ({ page }) => {
  test.setTimeout(240_000);
  const stamp = Date.now().toString(36);
  await page.goto("/item-quantity-units");
  await expect(page.getByTestId("pack-conversions-list")).toBeVisible();
  await expect(page.getByRole("button", { name: "Print", exact: true }).first()).toBeVisible();
  // What each conversion means is shown in the buyer's words.
  await expect(page.getByRole("row").filter({ hasText: "1 drum = 200" }).first()).toBeVisible();

  // Add one in the side panel: a unit that is free for this item.
  await page.getByRole("button", { name: "New pack conversion" }).click();
  await expect(page.getByRole("dialog")).toContainText("New pack conversion");
  await page.getByRole("combobox", { name: "Item" }).click();
  await page.locator("[cmdk-input]").fill("demo-sanding");
  await page.getByRole("option", { name: /demo-sanding/ }).first().click();
  await page.getByRole("combobox").nth(1).click();
  await page.locator("[cmdk-input]").fill("roll");
  const option = page.getByRole("option", { name: /Roll/ }).first();
  await option.click();
  await page.getByLabel("Factor").fill("7");
  await page.getByTestId("pack-save").click();
  const detail = page.getByTestId("pack-conversion-detail");
  await expect(detail).toContainText("= 7");
  await expect(page.getByTestId("pack-print")).toBeVisible();

  // The item filter shows as a chip.
  await page.goto("/item-quantity-units");
  await page.getByRole("combobox", { name: "Filter by item" }).click();
  await page.locator("[cmdk-input]").fill("demo-sanding");
  await page.getByRole("option", { name: /demo-sanding/ }).first().click();
  await expect(page.getByTestId("active-filters")).toContainText("demo-sanding");

  // Tick the one we added and delete it from the bulk bar.
  const row = page.getByRole("row").filter({ hasText: "= 7" }).first();
  await row.getByRole("checkbox").check();
  const bar = page.getByTestId("pack-bulk-bar");
  await expect(bar.getByRole("button", { name: "Print selected" })).toBeVisible();
  await bar.getByTestId("bulk-delete").click();
  const dialog = page.getByTestId("bulk-dialog");
  await dialog.getByTestId("bulk-confirm").click();
  await expect(dialog.getByTestId("bulk-result")).toContainText("1 changed");
  await dialog.getByRole("button", { name: "Done" }).click();
  await expect(page.getByRole("row").filter({ hasText: "= 7" })).toHaveCount(0);
});
