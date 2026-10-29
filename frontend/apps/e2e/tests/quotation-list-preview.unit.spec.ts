import { test, expect } from "@playwright/test";
import { buildSync } from "esbuild";
import AxeBuilder from "@axe-core/playwright";
import { readFileSync, readdirSync } from "node:fs";
import { readQuotationListSummaries } from "../../../packages/core-ui/src/features/quotations/quotation-list-contract";
const data = {
  revision_id: 12,
  family_id: 8,
  revision_ordinal: 1,
  state: "DRAFT",
  input_generation: 2,
  recipient_party_id: null,
  currency_id: 4,
  pricing_date: null,
  tax_estimate_date: null,
  valid_through_local_date: null,
  current_opportunity_id: null,
  line_count: 0,
  created_by: 5,
};
const source = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-list.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
test("safe summary rejects malformed rows and strips financial extras", () => {
  expect(
    readQuotationListSummaries([{ ...data, cost: "SECRET_COST" }]),
  ).toEqual([data]);
  expect(() =>
    readQuotationListSummaries([{ ...data, revision_id: 0 }]),
  ).toThrow();
});
async function mount(page: import("@playwright/test").Page, path: string) {
  const requests: string[] = [];
  await page.route("http://localhost:5178/**", async (route) => {
    requests.push(route.request().url());
    await route.fulfill({
      contentType: "text/html",
      body: '<!doctype html><html lang="en"><head><title>Quotations</title></head><body><main id="root"></main></body></html>',
    });
  });
  await page.goto(`http://localhost:5178/${path}`);
  await page.addScriptTag({ content: source });
  return requests;
}
test("screen gate remains disabled with no network requests", async ({
  page,
}) => {
  const requests = await mount(page, "?gated=1");
  await expect(
    page.getByRole("heading", { name: /you don.t have access/i }),
  ).toBeVisible();
  expect(requests).toHaveLength(1);
});
test("mock presentation is keyboard accessible, shows safe rows and fits a phone", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  page.on("pageerror", (error) =>
    process.stderr.write(`PAGEERROR: ${error.message}\n`),
  );
  const requests = await mount(page, "");
  const assets = new URL("../../main/dist/assets/", import.meta.url);
  const css = readdirSync(assets).find((name) => name.endsWith(".css"));
  if (!css) throw new Error("Build frontend first");
  await page.addStyleTag({
    content: readFileSync(new URL(css, assets), "utf8"),
  });
  await expect(page.getByRole("link", { name: "Revision #12" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Review" })).toBeVisible();
  await expect(page.locator("body")).not.toContainText("SECRET_COST");
  await expect(page.locator("body")).not.toContainText("SECRET_MARGIN");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  const report = await new AxeBuilder({ page }).analyze();
  expect(
    report.violations.filter(
      (v) => v.impact === "serious" || v.impact === "critical",
    ),
  ).toEqual([]);
  const link = page.getByRole("link", { name: "Revision #12" });
  await link.focus();
  await expect(link).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.getByTestId("route")).toHaveText("/quotations/12");
  expect(requests).toHaveLength(1);
});
