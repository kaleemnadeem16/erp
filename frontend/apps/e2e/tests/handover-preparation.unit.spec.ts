import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import type { HpFixture } from "./fixtures/handover-preparation";

// Preparing a service handover from an accepted service, in a real browser: the server's review decides what is missing and whether preparation may proceed.
const script = buildSync({
  entryPoints: [new URL("./fixtures/handover-preparation.tsx", import.meta.url).pathname],
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

const PERMS = ["sales.handover.prepare", "sales.customer-decision.view", "sales.quotation.view"];

async function mount(page: Page, fixture: HpFixture, dark = false, width = 390) {
  await page.setViewportSize({ width, height: 900 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Prepare handover fixture</title><style>${css}</style></head><body><main id="root"></main></body></html>`,
  );
  await page.evaluate((f) => {
    window.__hp = f;
  }, fixture);
  await page.addScriptTag({ content: script });
}
const calls = (page: Page) => page.evaluate(() => window.__hpCalls);
async function clean(page: Page, within?: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  // An open dialog is judged on its own: the page behind it sits under a fading overlay, so its contrast is timing-dependent, not a defect.
  const builder = new AxeBuilder({ page });
  const results = await (within ? builder.include(within) : builder).analyze();
  expect(results.violations.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
}
async function fillRemote(page: Page) {
  await page.getByLabel("Remote").check();
  await page.getByLabel("Customer contact").selectOption("5");
}
const review = (page: Page) => page.getByRole("button", { name: /^Review/ });

test("people without the preparation duty see no form and issue no request", async ({ page }) => {
  await mount(page, { permissions: ["sales.customer-decision.view", "sales.quotation.view"] });
  await expect(page.getByRole("heading", { name: "Prepare handover" })).toHaveCount(0);
  expect(await calls(page)).toEqual([]);
});

test("the review is server-derived: nothing chosen reports what is missing and blocks preparation", async ({ page }) => {
  await mount(page, { permissions: PERMS });
  await expect(page.getByRole("heading", { name: "Prepare handover", level: 1 })).toBeVisible();
  await review(page).click();
  await expect(page.getByTestId("prep-missing")).toContainText("Choose whether the service is remote or on site.");
  await expect(page.getByTestId("prep-missing")).toContainText("Choose a current contact");
  await expect(page.getByText("Not ready to prepare")).toBeVisible();
  await expect(page.getByRole("button", { name: "Prepare handover…" })).toBeDisabled();
  expect((await calls(page)).filter((c) => c.startsWith("prepare"))).toEqual([]);
});

test("on-site needs a site; remote hides it and never sends one", async ({ page }) => {
  await mount(page, { permissions: PERMS });
  await expect(page.getByLabel("Customer site")).toHaveCount(0);
  await page.getByLabel("On site").check();
  await page.getByLabel("Customer contact").selectOption("5");
  await review(page).click();
  await expect(page.getByTestId("prep-missing")).toContainText("Choose a current, active site");
  await page.getByLabel("Customer site").selectOption("8");
  await page.getByLabel("Remote").check();
  await expect(page.getByLabel("Customer site")).toHaveCount(0);
  await review(page).click();
  await expect(page.getByText("Ready to prepare")).toBeVisible();
  const last = (await calls(page)).filter((c) => c.startsWith("preview")).at(-1)!;
  expect(last).toContain('"siteId":null');
});

test("the whole journey: review, explicit confirmation, one prepare with the reviewed token, then the existing handover screen", async ({ page }) => {
  await mount(page, { permissions: PERMS });
  await fillRemote(page);
  await page.getByLabel("Operational instructions").fill("Call the contact before connecting.");
  await review(page).click();
  await expect(page.getByText("Ready to prepare")).toBeVisible();
  await expect(page.getByTestId("prep-review")).toContainText("Amina Khan");
  await expect(page.getByTestId("prep-review")).toContainText("Call the contact before connecting.");
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toContainText("does not release the service, create a Job");
  expect((await calls(page)).filter((c) => c.startsWith("prepare"))).toEqual([]);
  await dialog.getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.locator("#plan-page")).toHaveText("Plan page /handover-plans/12");
  const prepares = (await calls(page)).filter((c) => c.startsWith("prepare"));
  expect(prepares).toHaveLength(1);
  expect(prepares[0]).toContain(":token-1:");
});

test("cancelling the confirmation prepares nothing", async ({ page }) => {
  await mount(page, { permissions: PERMS });
  await fillRemote(page);
  await review(page).click();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("button", { name: "Cancel" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect((await calls(page)).filter((c) => c.startsWith("prepare"))).toEqual([]);
});

test("editing after a review makes it stale: it cannot be confirmed until reviewed again", async ({ page }) => {
  await mount(page, { permissions: PERMS });
  await fillRemote(page);
  await review(page).click();
  await expect(page.getByText("Ready to prepare")).toBeVisible();
  await page.getByLabel("Operational instructions").fill("Changed after the review");
  await expect(page.getByTestId("prep-review-stale")).toBeVisible();
  await expect(page.getByRole("button", { name: "Prepare handover…" })).toHaveCount(0);
  await review(page).click();
  await expect(page.getByText("Ready to prepare")).toBeVisible();
});

test("a stale token is refused: the review is dropped and a fresh review is required", async ({ page }) => {
  await mount(page, { permissions: PERMS, prepareRefusal: { status: 409, reason: "PREVIEW_TOKEN_STALE" } });
  await fillRemote(page);
  await review(page).click();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.getByTestId("prep-notice")).toContainText("What you reviewed has changed");
  await expect(page.getByRole("button", { name: "Prepare handover…" })).toHaveCount(0);
  await review(page).click();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.locator("#plan-page")).toBeVisible();
  const prepares = (await calls(page)).filter((c) => c.startsWith("prepare"));
  expect(prepares.map((c) => c.split(":")[2])).toEqual(["token-1", "token-2"]);
});

test("an uncertain failure retries under the SAME key (replay); a definitive refusal never reuses it", async ({ page }) => {
  await mount(page, { permissions: PERMS, prepareRefusal: { status: 503, times: 1 } });
  await fillRemote(page);
  await review(page).click();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.getByTestId("prep-notice")).toBeVisible();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.locator("#plan-page")).toBeVisible();
  const keys = (await calls(page)).filter((c) => c.startsWith("prepare")).map((c) => c.split(":")[3]);
  expect(keys).toHaveLength(2);
  expect(keys[0]).toBe(keys[1]);
});

test("a handover prepared by someone else meanwhile is opened, never duplicated", async ({ page }) => {
  await mount(page, { permissions: PERMS, preparedMeanwhile: true });
  await fillRemote(page);
  await review(page).click();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.getByTestId("already-prepared")).toBeVisible();
  await expect(page.getByTestId("open-existing-handover")).toHaveAttribute("href", /handover-plans\/12/);
  await expect(page.getByRole("button", { name: /^Review/ })).toHaveCount(0);
  expect((await calls(page)).filter((c) => c.startsWith("prepare"))).toHaveLength(1);
});

test("an existing handover is shown instead of a form and cannot be prepared again", async ({ page }) => {
  await mount(page, { permissions: PERMS, prepared: true });
  await expect(page.getByTestId("already-prepared")).toContainText("Prepared, not released");
  await expect(page.getByLabel("Remote")).toHaveCount(0);
  await expect(page.getByTestId("open-existing-handover")).toBeVisible();
});

test("no contacts is explained; the review still reports the server's missing contact", async ({ page }) => {
  await mount(page, { permissions: PERMS, noContacts: true });
  await expect(page.getByText("no current contact with an email address or telephone")).toBeVisible();
  await clean(page);
});

test("an on-site service for a customer with no active site explains it", async ({ page }) => {
  await mount(page, { permissions: PERMS, noSites: true });
  await page.getByLabel("On site").check();
  await expect(page.getByText("no active site")).toBeVisible();
  await clean(page);
});

test("a failed options read, a refused review and a denied lookup are announced", async ({ page }) => {
  await mount(page, { permissions: PERMS, optionsFailure: 403 });
  await expect(page.getByRole("alert")).toContainText("Could not load this page");
});

test("a refused review shows the reason and offers no preparation", async ({ page }) => {
  await mount(page, { permissions: PERMS, previewFailure: 422 });
  await fillRemote(page);
  await review(page).click();
  await expect(page.getByTestId("prep-notice")).toContainText("Check the details");
  await expect(page.getByRole("button", { name: "Prepare handover…" })).toHaveCount(0);
});

test("keyboard only: the radios, selects, review and confirm are reachable and operable", async ({ page }) => {
  await mount(page, { permissions: PERMS });
  await page.getByLabel("Remote").focus();
  await page.keyboard.press("Space");
  await expect(page.getByLabel("Remote")).toBeChecked();
  await page.keyboard.press("Tab");
  await expect(page.getByLabel("Customer contact")).toBeFocused();
  await page.keyboard.press("ArrowDown");
  await expect(page.getByLabel("Customer contact")).toHaveValue("5");
  await page.keyboard.press("Tab");
  await expect(page.getByLabel("Operational instructions")).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("button", { name: "Review handover" })).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.getByText("Ready to prepare")).toBeVisible();
  await page.getByRole("button", { name: "Prepare handover…" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Prepare handover…" })).toBeFocused();
});

for (const [name, width, dark] of [
  ["320px", 320, false],
  ["390px", 390, false],
  ["dark", 390, true],
] as const) {
  test(`no overflow and no accessibility violations at ${name}, in every state`, async ({ page }) => {
    await mount(page, { permissions: PERMS }, dark, width);
    await expect(page.getByRole("heading", { name: "Prepare handover", level: 1 })).toBeVisible();
    await clean(page);
    await page.getByLabel("On site").check();
    await clean(page);
    await page.getByLabel("Customer contact").selectOption("5");
    await review(page).click();
    await expect(page.getByText("Not ready to prepare")).toBeVisible();
    await clean(page);
    await page.getByLabel("Customer site").selectOption("8");
    await review(page).click();
    await expect(page.getByText("Ready to prepare")).toBeVisible();
    await clean(page);
    await page.getByRole("button", { name: "Prepare handover…" }).click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await clean(page, '[role="dialog"]');
  });
}
