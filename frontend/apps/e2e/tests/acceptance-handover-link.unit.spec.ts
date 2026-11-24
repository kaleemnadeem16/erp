import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import type { AhlFixture } from "./fixtures/acceptance-handover-link";

// Customer Acceptance -> Prepared Handover navigation (the acceptance panel), in a real browser: the server's status read decides what is offered.
const script = buildSync({
  entryPoints: [new URL("./fixtures/acceptance-handover-link.tsx", import.meta.url).pathname],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
const assets = new URL("../../main/dist/assets/", import.meta.url);
const css = readdirSync(assets)
  .filter((name) => name.endsWith(".css"))
  .map((name) => readFileSync(new URL(name, assets), "utf8"))
  .join("\n");

const READ = ["sales.customer-decision.view", "sales.quotation.view"];

async function mount(page: Page, fixture: AhlFixture, dark = false, width = 390) {
  await page.setViewportSize({ width, height: 800 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Acceptance handover fixture</title><style>${css}</style></head><body><main id="root"></main></body></html>`,
  );
  await page.evaluate((f) => {
    window.__ahl = f;
  }, fixture);
  await page.addScriptTag({ content: script });
}
const calls = (page: Page) => page.evaluate(() => window.__ahlCalls);
async function clean(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
}

test("people without the read duties see nothing and issue no request", async ({ page }) => {
  await mount(page, { permissions: ["sales.customer-decision.view"] });
  await expect(page.getByRole("heading", { name: "Handover", exact: true })).toHaveCount(0);
  expect(await calls(page)).toEqual([]);
});

test("a prepared, unreleased handover links to its review, by keyboard too", async ({ page }) => {
  await mount(page, { permissions: READ });
  await expect(page.getByTestId("handover-prepared")).toContainText("Prepared, not released");
  const link = page.getByTestId("handover-link");
  await expect(link).toHaveText("Open the prepared handover and release review");
  await link.focus();
  await page.keyboard.press("Enter");
  await expect(page.locator("#target")).toHaveText("Opened /handover-plans/12");
  expect(await calls(page)).toEqual(["status:55"]);
});

test("an already released handover says so and still opens", async ({ page }) => {
  await mount(page, { permissions: READ, status: { release_performed: true } });
  await expect(page.getByTestId("handover-prepared")).toContainText("Released");
  await expect(page.getByTestId("handover-link")).toHaveText("Open the released handover");
});

test("nothing prepared: no link and no way to create one here", async ({ page }) => {
  await mount(page, { permissions: READ, status: { prepared: false, plan_id: null, stage_id: null } });
  await expect(page.getByTestId("handover-not-prepared")).toBeVisible();
  await expect(page.getByRole("link")).toHaveCount(0);
  await expect(page.getByRole("button")).toHaveCount(0);
});

test("an inaccessible acceptance is explained without retry; a failure is retryable", async ({ page }) => {
  await mount(page, { permissions: READ, failure: 404 });
  await expect(page.getByTestId("handover-link-error")).toContainText("not available");
  await expect(page.getByRole("button", { name: "Try again" })).toHaveCount(0);
  await mount(page, { permissions: READ, failure: 403 });
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByTestId("handover-link")).toBeVisible();
});

test("320px and desktop, light and dark: no overflow and no accessibility violations", async ({ page }) => {
  for (const [dark, width] of [[false, 320], [true, 320], [false, 1280], [true, 1280]] as const) {
    await mount(page, { permissions: READ }, dark, width);
    await expect(page.getByTestId("handover-link")).toBeVisible();
    await clean(page);
  }
});

const PREPARE = [...READ, "sales.handover.prepare"];

test("an accepted service with no handover offers Prepare handover, by keyboard, only to those who may prepare", async ({ page }) => {
  await mount(page, { permissions: PREPARE, serviceItem: true, status: { prepared: false, plan_id: null, stage_id: null } });
  await expect(page.getByTestId("handover-not-prepared")).toBeVisible();
  const link = page.getByTestId("prepare-handover-link");
  await expect(link).toHaveText("Prepare handover");
  await link.focus();
  await page.keyboard.press("Enter");
  await expect(page.locator("#target")).toHaveText("Opened /customer-acceptances/55/prepare-handover");
});

test("no Prepare handover link without the preparation duty, for goods, or once a handover exists", async ({ page }) => {
  const unprepared = { prepared: false, plan_id: null, stage_id: null };
  await mount(page, { permissions: READ, serviceItem: true, status: unprepared });
  await expect(page.getByTestId("handover-not-prepared")).toBeVisible();
  await expect(page.getByTestId("prepare-handover-link")).toHaveCount(0);
  await mount(page, { permissions: PREPARE, serviceItem: false, status: unprepared });
  await expect(page.getByTestId("handover-not-prepared")).toBeVisible();
  await expect(page.getByTestId("prepare-handover-link")).toHaveCount(0);
  await mount(page, { permissions: PREPARE, serviceItem: true });
  await expect(page.getByTestId("handover-prepared")).toBeVisible();
  await expect(page.getByTestId("prepare-handover-link")).toHaveCount(0);
});

test("the prepare link is clean at 320px", async ({ page }) => {
  await mount(page, { permissions: PREPARE, serviceItem: true, status: { prepared: false, plan_id: null, stage_id: null } }, false, 320);
  await expect(page.getByTestId("prepare-handover-link")).toBeVisible();
  await clean(page);
});
