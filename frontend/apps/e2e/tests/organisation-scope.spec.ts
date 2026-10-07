import { expect, test, type Page } from "@playwright/test";

/**
 * ADR 0089 (Q26): the Organisation screen builds the company's own tree and says which warehouses each unit owns; an assignment limited
 * to "only my units" then reaches only those warehouses. This runs against the seeded demo (OrganisationDemoSeeder): Head office >
 * Main site (owns Main Warehouse) and Karachi site (owns Karachi Depot), and a Warehouse clerk limited to each. The clerk signs in through
 * the real login form in a fresh browser context.
 */
const KARACHI_CLERK = "demo-clerk-khi@erp.x1techs.com";
const PASSWORD = process.env.E2E_USER_PASSWORD ?? "!User@Erp@123";

async function signIn(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Password", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

/** GET an API path with this browser's own session, so the answer is exactly what that signed-in person receives. */
async function get(page: Page, path: string) {
  return page.evaluate(async ({ url, origin }) => {
    // Match the configured test stack, or derive the default API host as the SPA does.
    const response = await fetch(
      `${origin ?? `http://${window.location.hostname}:8090`}${url}`,
      {
        credentials: "include",
        headers: { Accept: "application/json" },
      },
    );
    return {
      status: response.status,
      body: response.ok ? await response.json() : null,
    };
  }, { url: path, origin: process.env.E2E_API_URL ? new URL(process.env.E2E_API_URL).origin : null });
}

async function warehousesOfLayers(page: Page): Promise<string[]> {
  const layers = await get(page, "/api/v1/stock-layers?per_page=100");
  const locations = await get(page, "/api/v1/locations?per_page=100");
  const warehouses = await get(page, "/api/v1/warehouses?per_page=100");
  const warehouseName = new Map<number, string>(
    warehouses.body.data.map((w: { id: number; name: string }) => [
      w.id,
      w.name,
    ]),
  );
  const locationWarehouse = new Map<number, number>(
    locations.body.data.map((l: { id: number; warehouse_id: number }) => [
      l.id,
      l.warehouse_id,
    ]),
  );
  const names = new Set<string>(
    layers.body.data.map(
      (l: { location_id: number }) =>
        warehouseName.get(
          locationWarehouse.get(l.location_id) as number,
        ) as string,
    ),
  );
  return [...names].sort();
}

test("the administrator sees the tree, who is in each unit and which warehouse each owns", async ({
  page,
}) => {
  await page.goto("/settings/organisation");
  await expect(
    page.getByRole("heading", { name: "Organisation" }),
  ).toBeVisible();
  const tree = page.getByRole("list", { name: "Organisation units" });
  await expect(tree.getByText("Head office")).toBeVisible();
  await expect(tree.getByText("Main site")).toBeVisible();
  await expect(tree.getByText("Karachi site")).toBeVisible();

  await tree.getByRole("button", { name: /Karachi site/ }).click();
  const panel = page.getByRole("region", { name: "Unit Karachi site" });
  await expect(
    panel.getByRole("checkbox", { name: /Karachi Depot/ }),
  ).toBeChecked();
  await expect(
    panel.getByRole("checkbox", { name: /Demo Karachi Clerk/ }),
  ).toBeChecked();
  // A warehouse belongs to one unit: Main Warehouse is Main site's, so it cannot also be ticked here.
  const main = panel.getByRole("checkbox", { name: /Main Warehouse/ });
  await expect(main).toBeDisabled();
  await expect(panel.getByText("belongs to Main site")).toBeVisible();
});

test("a member whose role is limited shows where it applies", async ({
  page,
}) => {
  await page.goto("/settings/members");
  await page
    .getByPlaceholder(/Search/i)
    .first()
    .fill("Demo Karachi Clerk");
  await page.getByText("Demo Karachi Clerk").first().click();
  await page.getByRole("button", { name: "Edit user" }).click();
  const where = page.getByLabel("Where each role applies");
  await expect(where).toBeVisible();
  await expect(where.getByText(/Belongs to: Karachi site/)).toBeVisible();
  await expect(
    where.getByRole("checkbox", { name: /Warehouse clerk: only my units/ }),
  ).toBeChecked();
});

test.describe("a Karachi warehouse clerk (role limited to only my units)", () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test("reaches only Karachi's stock, and the company-wide overview refuses them", async ({
    page,
  }) => {
    await signIn(page, KARACHI_CLERK);

    expect(await warehousesOfLayers(page)).toEqual(["Karachi Depot"]);
    expect((await get(page, "/api/v1/inventory-overview")).status).toBe(403);
    expect((await get(page, "/api/v1/avco-pools")).status).toBe(403);

    // The ledger still works for them: it lists movements that touch Karachi.
    await page.goto("/stock-moves");
    await expect(
      page.getByRole("heading", { name: /Stock Ledger|Stock moves/i }).first(),
    ).toBeVisible();
    const moves = await get(page, "/api/v1/stock-moves?per_page=100");
    expect(moves.status).toBe(200);
    expect(moves.body.data.length).toBeGreaterThan(0);
  });
});

test("the administrator, with no limit, reaches every warehouse's stock", async ({
  page,
}) => {
  await page.goto("/");
  expect((await warehousesOfLayers(page)).length).toBeGreaterThanOrEqual(2);
});
