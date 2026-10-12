import { expect, test, type Page } from "@playwright/test";

/**
 * ADR 0086: a recipe (formulation inputs and quantities, application rates, assembly components) is read through
 * `estimating.recipe.view`, a capability an administrator grants through a role. The demo company has a "Production planner" role
 * that holds only that capability; the Buyer persona holds none. Each signs in through the real login form in a fresh browser
 * context, so nothing is borrowed from the administrator's session.
 */
const PLANNER = "demo-planner@erp.x1techs.com";
const BUYER = "demo-buyer@erp.x1techs.com";
const STEWARD = "demo-steward@erp.x1techs.com";
const ITEM_WITH_RECIPE = "demo-paint-white";
const PASSWORD = process.env.E2E_USER_PASSWORD ?? "!User@Erp@123";

async function signIn(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Password", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

test.describe("a Production planner (recipe capability only)", () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test("reads formulations and application rates but cannot change them", async ({
    page,
  }) => {
    await signIn(page, PLANNER);

    await page.goto("/formulations");
    await expect(
      page.getByRole("heading", { name: "Formulations" }),
    ).toBeVisible();
    await expect(page.getByRole("row").nth(1)).toBeVisible();
    await expect(
      page.getByRole("button", { name: "New formulation" }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("link", { name: "New formulation" }),
    ).toHaveCount(0);

    await page.goto("/application-rates");
    await expect(
      page.getByRole("heading", { name: "Application Rates" }),
    ).toBeVisible();
    await expect(page.getByRole("row").nth(1)).toBeVisible();
  });

  test("has no cost, rate or margin screens through the recipe capability", async ({
    page,
  }) => {
    await signIn(page, PLANNER);
    await page.goto("/resource-rates");
    await expect(
      page.getByRole("heading", { name: "You don’t have access" }),
    ).toBeVisible();
  });
});

test.describe("a Buyer (no recipe access)", () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test("is stopped at the recipe screens without asking the server for a recipe", async ({
    page,
  }) => {
    const recipeRequests: string[] = [];
    page.on("request", (request) => {
      if (/\/api\/v1\/(formulations|application-rates)/.test(request.url()))
        recipeRequests.push(request.url());
    });
    await signIn(page, BUYER);

    for (const path of ["/formulations", "/application-rates"]) {
      await page.goto(path);
      await expect(
        page.getByRole("heading", { name: "You don’t have access" }),
      ).toBeVisible();
    }

    expect(recipeRequests).toEqual([]);
  });
});

// The item screen is tabbed; formulations live on the "recipes" tab, which a person without recipe access must not be shown.
async function openItemRecipesTab(page: Page, code: string) {
  await page.goto(`/items?filter.search=${code}`);
  await page.locator("tbody tr").filter({ hasText: code }).first().click();
  await expect(page).toHaveURL(/\/items\/\d+/);
  await expect(
    page.getByRole("tab", { name: "Packaging & Units" }),
  ).toBeVisible();
  await page.goto(`${new URL(page.url()).pathname}?tab=recipes`);
  await expect(
    page.getByRole("tab", { name: "Packaging & Units" }),
  ).toBeVisible();
}

test.describe("a Master data steward (items, no recipe access)", () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test("opens an item that has a recipe without being shown it or asking the server for it", async ({
    page,
  }) => {
    const recipeRequests: string[] = [];
    page.on("request", (request) => {
      if (/\/api\/v1\/formulations/.test(request.url()))
        recipeRequests.push(request.url());
    });
    await signIn(page, STEWARD);

    await openItemRecipesTab(page, ITEM_WITH_RECIPE);
    await page.waitForLoadState("networkidle");
    await expect(
      page.locator('a[href^="/formulations/"][href$="/edit"]'),
    ).toHaveCount(0);
    expect(recipeRequests).toEqual([]);

    await page.goto("/formulations");
    await expect(
      page.getByRole("heading", { name: "You don’t have access" }),
    ).toBeVisible();
  });
});

test("the administrator sees the same item with its formulations", async ({
  page,
}) => {
  await openItemRecipesTab(page, ITEM_WITH_RECIPE);
  // The card lists each formulation as a link to its edit screen; the sidebar link to the list does not match.
  await expect(
    page.locator('a[href^="/formulations/"][href$="/edit"]').first(),
  ).toBeVisible();
});
