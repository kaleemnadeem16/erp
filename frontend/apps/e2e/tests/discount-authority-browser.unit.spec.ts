import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import type { DaFixture } from "./fixtures/discount-authority";

// The discount authority screens in a real browser: phone width, light and dark, keyboard operation and the guarded writes.
const script = buildSync({
  entryPoints: [new URL("./fixtures/discount-authority.tsx", import.meta.url).pathname],
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

const CONFIGURE = ["sales.financial.configure"];
const EVIDENCE = ["sales.quotation.view", "sales.discount-authority.evidence.view"];

async function mount(page: Page, fixture: DaFixture, dark = false, width = 390) {
  await page.setViewportSize({ width, height: 844 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Discount authority fixture</title><style>${css}</style></head><body><div id="root"></div></body></html>`,
  );
  await page.evaluate((f) => { window.__da = f; }, fixture);
  await page.addScriptTag({ content: script });
}
// Shared controls keep their own sizing: a checkbox sits in a 44px row with a clickable label, and the date picker button is the shared DateField.
async function expectPhoneSafe(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const small = await page.evaluate(() =>
    [...document.querySelectorAll("button, a[href]")]
      .filter((el) => (el as HTMLElement).offsetParent !== null)
      .filter((el) => el.getAttribute("role") !== "checkbox" && el.getAttribute("aria-label") !== "Choose date")
      .filter((el) => el.getBoundingClientRect().height < 44)
      .map((el) => (el.textContent ?? "").trim() || el.getAttribute("aria-label") || el.outerHTML.slice(0, 120)),
  );
  expect(small).toEqual([]);
}

for (const dark of [false, true])
  test(`configuration is readable at 320px and 390px (${dark ? "dark" : "light"})`, async ({ page }) => {
    for (const width of [320, 390]) {
      await mount(page, { screen: "settings", permissions: CONFIGURE }, dark, width);
      await expect(page.getByRole("heading", { name: "Policy versions" })).toBeVisible();
      await expect(page.getByText("not active").first()).toBeVisible();
      await expect(page.getByText("Demo only").first()).toBeVisible();
      await expectPhoneSafe(page);
      await page.getByRole("button", { name: "New policy version" }).click();
      await expect(page.getByRole("dialog")).toBeVisible();
      await expectPhoneSafe(page);
    }
  });

test("a person without the duty sees nothing of the configuration", async ({ page }) => {
  await mount(page, { screen: "settings", permissions: EVIDENCE });
  await expect(page.getByText("You don’t have access")).toBeVisible();
  await expect(page.getByText("Policy versions")).toHaveCount(0);
});

test("a policy version is validated, saved with the current generation, and not activated by saving", async ({ page }) => {
  await mount(page, { screen: "settings", permissions: CONFIGURE });
  await page.getByRole("button", { name: "New policy version" }).click();
  await page.getByRole("button", { name: "Save policy version" }).click();
  await expect(page.getByText("Use lower-case letters").first()).toBeVisible();
  await expect(page.getByText("Give a reason.")).toBeVisible();
  await page.getByLabel("Policy code").fill("sales-test");
  await page.getByLabel("Level name").fill("Level one");
  await page.getByLabel("Line discount limit (%)").fill("5");
  await page.getByLabel("Document discount limit (%)").fill("5");
  await page.getByLabel("Reason", { exact: true }).fill("Test version");
  await page.getByRole("button", { name: "Save policy version" }).click();
  await expect(page.getByText("Policy version saved. It has not been activated.")).toBeVisible();
  expect(await page.evaluate(() => window.__daWrites)).toEqual(["policy:sales-test:4"]);
  await expect(page.getByRole("heading", { name: "sales-test · version 1" })).toBeVisible();
});

test("activating and revoking need a reason and are keyboard operable", async ({ page }) => {
  await mount(page, { screen: "settings", permissions: CONFIGURE });
  await page.getByRole("button", { name: "Activate this version" }).focus();
  await page.keyboard.press("Enter");
  const confirm = page.getByRole("button", { name: "Activate version" });
  await expect(confirm).toBeDisabled();
  await page.getByLabel("Reason").fill("Reviewed");
  await confirm.click();
  await expect(page.getByText("Policy version activated.")).toBeVisible();
  await page.getByRole("button", { name: "Revoke", exact: true }).click();
  await page.getByLabel("Reason").fill("Left the team");
  await page.getByRole("button", { name: "Revoke assignment" }).click();
  await expect(page.getByText("Assignment revoked.")).toBeVisible();
  await expect(page.getByText("Revoked", { exact: true }).first()).toBeVisible();
  expect(await page.evaluate(() => window.__daWrites)).toEqual(["activate:3", "revoke:7"]);
});

test("nothing configured says so and offers only the first step", async ({ page }) => {
  await mount(page, { screen: "settings", permissions: CONFIGURE, empty: true });
  await expect(page.getByText("No authority levels are set up yet")).toBeVisible();
  await expect(page.getByRole("button", { name: "Assign authority" })).toBeDisabled();
});

for (const [panel, label] of [["needs", "Needs approval"], ["within", "Within authority"], ["off", "Not active"], ["none", "No authority levels set up"]] as const)
  test(`the quotation panel shows "${label}" in light and dark at phone width`, async ({ page }) => {
    for (const dark of [false, true]) {
      await mount(page, { screen: "panel", permissions: EVIDENCE, panel }, dark);
      await expect(page.getByText(label).first()).toBeVisible();
      await expectPhoneSafe(page);
    }
  });

test("the quotation panel renders nothing without the evidence duty", async ({ page }) => {
  await mount(page, { screen: "panel", permissions: ["sales.quotation.view"] });
  await expect(page.getByText("Discount authority")).toHaveCount(0);
});
