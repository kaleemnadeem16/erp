import { test, expect, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import AxeBuilder from "@axe-core/playwright";
import { readFileSync, readdirSync } from "node:fs";

const source = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-detail-sheet.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
const metadata = {
  revision_id: 12,
  family_id: 8,
  revision_ordinal: 1,
  state: "DRAFT",
  input_generation: 2,
  recipient_party_id: 23,
  currency_id: 3,
  pricing_date: "2026-10-27",
  tax_estimate_date: null,
  valid_through_local_date: null,
  current_opportunity_id: null,
  line_count: 1,
  created_by: 5,
};
async function mount(page: Page, path: string) {
  const requests: string[] = [];
  await page.route("http://localhost:5178/**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith("/api/")) {
      requests.push(url.pathname);
      if (url.pathname.endsWith("/preview"))
        return route.fulfill({
          status: 404,
          contentType: "application/json",
          body: JSON.stringify({ message: "SECRET_FOREIGN" }),
        });
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: metadata }),
      });
    }
    await route.fulfill({
      contentType: "text/html",
      body: '<!doctype html><html lang="en"><head><title>Quotation detail</title></head><body><div id="root"></div></body></html>',
    });
  });
  await page.goto(`http://localhost:5178${path}`);
  await page.addScriptTag({ content: source });
  return requests;
}
test("detail and review use a side sheet over the retained list and keep filters", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const requests = await mount(page, "/quotations/12?state=DRAFT");
  const assets = new URL("../../main/dist/assets/", import.meta.url);
  const css = readdirSync(assets).find((name) => name.endsWith(".css"));
  if (!css) throw new Error("Build the frontend first");
  await page.addStyleTag({
    content: readFileSync(new URL(css, assets), "utf8"),
  });
  await expect(
    page.getByRole("dialog", { name: "Quotation #12" }),
  ).toBeVisible();
  await expect(page.getByTestId("list")).toBeAttached();
  await expect.poll(() => requests).toEqual(["/api/v1/quotations/12"]);
  const report = await new AxeBuilder({ page }).analyze();
  expect(
    report.violations.filter(
      (v) => v.impact === "serious" || v.impact === "critical",
    ),
  ).toEqual([]);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.getByRole("link", { name: "Review quotation" }).click();
  await expect(
    page.getByRole("dialog", { name: "Quotation #12 review" }),
  ).toBeVisible();
  await expect(
    page.getByText("This quotation is not available."),
  ).toBeVisible();
  await expect(page.locator("body")).not.toContainText("SECRET_FOREIGN");
  await expect
    .poll(() => requests)
    .toEqual(["/api/v1/quotations/12", "/api/v1/quotations/12/preview"]);
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.getByTestId("route")).toHaveText("/quotations?state=DRAFT");
  await expect(page.getByTestId("list")).toBeVisible();
});
test("invalid revision opens the sheet without an API request", async ({
  page,
}) => {
  const requests = await mount(page, "/quotations/abc?state=DRAFT");
  await expect(
    page.getByText("This quotation is not available."),
  ).toBeVisible();
  expect(requests).toEqual([]);
});

test("view-only sheet never requests or links to review", async ({ page }) => {
  const requests = await mount(page, "/quotations/12?fixtureDuty=view");
  await expect(
    page.getByRole("dialog", { name: "Quotation #12" }),
  ).toBeVisible();
  await expect.poll(() => requests).toEqual(["/api/v1/quotations/12"]);
  await expect(
    page.getByRole("link", { name: "Review quotation" }),
  ).toHaveCount(0);
});

test("prepare-only sheet does not fetch or link to view-protected details", async ({
  page,
}) => {
  const requests = await mount(
    page,
    "/quotations/12/review?fixtureDuty=prepare",
  );
  await expect(
    page.getByText("This quotation is not available."),
  ).toBeVisible();
  await expect.poll(() => requests).toEqual(["/api/v1/quotations/12/preview"]);
  await expect(
    page.getByRole("link", { name: "Quotation details" }),
  ).toHaveCount(0);
});
