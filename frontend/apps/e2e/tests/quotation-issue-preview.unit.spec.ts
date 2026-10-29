import { test, expect, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import AxeBuilder from "@axe-core/playwright";
import { readFileSync, readdirSync } from "node:fs";
import { readIssueReadiness } from "../../../packages/core-ui/src/features/quotations/quotation-issue-contract";
const fixture = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-issue-preview.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
test("readiness refuses inconsistent and malformed results and strips private evidence", () => {
  const base = {
    revision_id: 12,
    input_generation: 3,
    issue_ready: false,
    blockers: [{ code: "TAX_NOT_DETERMINED", scope: "TAX", line_id: null }],
  };
  expect(readIssueReadiness({ ...base, cost: "SECRET" })).toEqual(base);
  expect(() => readIssueReadiness({ ...base, issue_ready: true })).toThrow();
  expect(() =>
    readIssueReadiness({
      ...base,
      blockers: [{ code: "X", scope: "LINE", line_id: null }],
    }),
  ).toThrow();
});
async function mount(page: Page, query: string) {
  const requests: string[] = [];
  await page.route("http://localhost:5178/**", async (route) => {
    requests.push(route.request().url());
    await route.fulfill({
      contentType: "text/html",
      body: '<!doctype html><html lang="en"><head><title>Quotation issue</title></head><body><main id="root"></main></body></html>',
    });
  });
  await page.goto(`http://localhost:5178/${query}`);
  await page.addScriptTag({ content: fixture });
  return requests;
}
test("both screens remain gated and send no requests", async ({ page }) => {
  const requests = await mount(page, "?gated=1");
  await expect(
    page.getByRole("heading", { name: /you don.t have access/i }),
  ).toBeVisible();
  await page.goto("http://localhost:5178/?gated=1&approval=1");
  await page.addScriptTag({ content: fixture });
  await expect(
    page.getByRole("heading", { name: /you don.t have access/i }),
  ).toBeVisible();
  expect(requests).toHaveLength(2);
});
test("readiness explains blockers without exposing codes or evidence at phone width", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const requests = await mount(page, "");
  const assets = new URL("../../main/dist/assets/", import.meta.url);
  const css = readdirSync(assets).find((name) => name.endsWith(".css"));
  if (!css) throw new Error("Build frontend first");
  await page.addStyleTag({
    content: readFileSync(new URL(css, assets), "utf8"),
  });
  await expect(page.getByText("Tax has not been determined.")).toBeVisible();
  await expect(
    page.getByText("A required financial control could not be evaluated."),
  ).toBeVisible();
  await expect(page.locator("body")).not.toContainText("SECRET_");
  await expect(page.locator("body")).not.toContainText("UNKNOWN_SECRET_CODE");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  const report = await new AxeBuilder({ page }).analyze();
  expect(
    report.violations.filter(
      (v) => v.impact === "serious" || v.impact === "critical",
    ),
  ).toEqual([]);
  const link = page.getByRole("link", { name: "Return to draft" });
  await link.focus();
  await expect(link).toBeFocused();
  expect(requests).toHaveLength(1);
});
test("approval preview never issues even when the mock assessment passes", async ({
  page,
}) => {
  const requests = await mount(page, "?approval=1&ready=1");
  await expect(
    page.getByRole("button", { name: "Issue quotation" }),
  ).toBeDisabled();
  await expect(
    page.getByText("Approval and issue are not available in this preview."),
  ).toBeVisible();
  expect(requests).toHaveLength(1);
});
