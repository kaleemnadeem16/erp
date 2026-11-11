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
const APPROVER = ["sales.pricing.reference.approve"];
const PREPARE = ["sales.quotation.view", "sales.quotation.prepare"];

async function mount(page: Page, fixture: DaFixture, dark = false, width = 390) {
  await page.setViewportSize({ width, height: 844 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Discount authority fixture</title><style>${css}</style></head><body><div id="root"></div></body></html>`,
  );
  await page.evaluate((f) => { window.__da = f; }, fixture);
  await page.addScriptTag({ content: script });
}
// Shared controls keep their own sizing: a checkbox sits in a 44px row with a clickable label, the date picker button is the shared DateField, and lookup comboboxes take the ERP Blue control height.
async function expectPhoneSafe(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const small = await page.evaluate(() =>
    [...document.querySelectorAll("button, a[href]")]
      .filter((el) => (el as HTMLElement).offsetParent !== null)
      .filter((el) => el.getAttribute("role") !== "checkbox" && el.getAttribute("role") !== "combobox" && el.getAttribute("aria-label") !== "Choose date")
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

test("the assignment form offers named roles and units, and falls back to reference numbers without access", async ({ page }) => {
  await mount(page, { screen: "settings", permissions: CONFIGURE });
  await page.getByRole("button", { name: "Assign authority" }).click();
  await page.getByLabel("Person").selectOption("21");
  await page.getByLabel("Authority level", { exact: true }).selectOption({ index: 1 });
  await page.getByLabel("Only in role (optional)").selectOption("8");
  await page.getByLabel("Only in unit (optional)").selectOption("9");
  await page.getByLabel("Reason", { exact: true }).fill("Role and unit test");
  await page.getByRole("button", { name: "Assign authority" }).last().click();
  await expect.poll(() => page.evaluate(() => window.__daWrites)).toEqual(["assign:21:8:9:4"]);

  await mount(page, { screen: "settings", permissions: CONFIGURE, noLookups: true });
  await page.getByRole("button", { name: "Assign authority" }).click();
  await expect(page.getByText("The list of roles is not available to you.")).toBeVisible();
  await expect(page.getByText("The list of units is not available to you.")).toBeVisible();
  await expect(page.getByLabel("Only in role (optional)")).toHaveJSProperty("tagName", "INPUT");
});

for (const dark of [false, true])
  test(`price references are readable at 320px and 390px (${dark ? "dark" : "light"})`, async ({ page }) => {
    for (const width of [320, 390]) {
      await mount(page, { screen: "references", permissions: CONFIGURE }, dark, width);
      await expect(page.getByRole("heading", { name: "Price references", exact: true }).first()).toBeVisible();
      await expect(page.getByText("Awaiting approval").first()).toBeVisible();
      await expect(page.getByText("Approved", { exact: true }).first()).toBeVisible();
      await expectPhoneSafe(page);
      await page.getByRole("button", { name: "Propose a price reference" }).click();
      await expect(page.getByRole("dialog")).toBeVisible();
      await expectPhoneSafe(page);
    }
  });

test("a reference is validated, then saved with the current generation and not approved by saving", async ({ page }) => {
  await mount(page, { screen: "references", permissions: CONFIGURE });
  await page.getByRole("button", { name: "Propose a price reference" }).focus();
  await page.keyboard.press("Enter");
  await page.getByRole("button", { name: "Save reference" }).click();
  await expect(page.getByText("Use lower-case letters").first()).toBeVisible();
  await expect(page.getByText("Choose a currency.")).toBeVisible();
  await expect(page.getByText("Give a reason.")).toBeVisible();
  await page.getByLabel("Reference code").fill("sales-baseline");
  await page.getByLabel("Source", { exact: true }).selectOption("PROJECT_BASELINE");
  await page.getByLabel("Source document").fill("Baseline A");
  await page.getByLabel("Source version").fill("1");
  await page.getByLabel("Priced by").selectOption("manual");
  await page.getByLabel("Measure").fill("per square metre");
  await page.getByLabel("Quotation family", { exact: true }).fill("3");
  await page.getByLabel("Currency").selectOption("1");
  await page.getByLabel("Unit price").fill("0");
  await page.getByRole("button", { name: "Save reference" }).click();
  await expect(page.getByText("must be more than zero")).toBeVisible();
  await page.getByLabel("Unit price").fill("250.5");
  await page.getByLabel("Reason", { exact: true }).fill("Test baseline");
  await page.getByRole("button", { name: "Save reference" }).click();
  await expect(page.getByText("Price reference saved. It needs approval by someone else before it counts.")).toBeVisible();
  expect(await page.evaluate(() => window.__daWrites)).toEqual(["propose:sales-baseline:per square metre:3:250.5:6"]);
  await expect(page.getByRole("heading", { name: "sales-baseline · version 1" })).toBeVisible();
});

test("approving a reference needs a reason, is keyboard operable, and is not offered for your own proposal", async ({ page }) => {
  await mount(page, { screen: "references", permissions: APPROVER });
  await expect(page.getByRole("button", { name: "Propose a price reference" })).toHaveCount(0);
  await page.getByRole("button", { name: "Approve this reference" }).focus();
  await page.keyboard.press("Enter");
  const confirm = page.getByRole("button", { name: "Approve reference" });
  await expect(confirm).toBeDisabled();
  await page.getByLabel("Reason").fill("Checked against the source");
  await confirm.click();
  await expect(page.getByText("Price reference approved.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Approve this reference" })).toHaveCount(0);
  expect(await page.evaluate(() => window.__daWrites)).toEqual(["approve:12:6"]);
});

test("a person without a reference duty sees nothing", async ({ page }) => {
  await mount(page, { screen: "references", permissions: EVIDENCE });
  await expect(page.getByText("You don’t have access")).toBeVisible();
  await expect(page.getByText("Price references")).toHaveCount(0);
});

for (const dark of [false, true])
  test(`the recorded history is readable and pages at 320px (${dark ? "dark" : "light"})`, async ({ page }) => {
    await mount(page, { screen: "history", permissions: EVIDENCE, historyTotal: 30 }, dark, 320);
    await expect(page.getByRole("heading", { name: "Recorded results" })).toBeVisible();
    await expect(page.getByText("Needs approval").first()).toBeVisible();
    await expect(page.getByText("Setting then: Record only").first()).toBeVisible();
    await expect(page.getByText("Page 1 of 2")).toBeVisible();
    await expectPhoneSafe(page);
    await page.getByRole("button", { name: "Older" }).focus();
    await page.keyboard.press("Enter");
    await expect(page.getByText("Page 2 of 2")).toBeVisible();
    await expect(page.getByRole("button", { name: "Older" })).toBeDisabled();
  });

test("the history is denied without the evidence duty", async ({ page }) => {
  await mount(page, { screen: "history", permissions: ["sales.quotation.view"] });
  await expect(page.getByText("You don’t have access")).toBeVisible();
  await expect(page.getByText("Recorded results")).toHaveCount(0);
});

test("the panel links to the recorded history", async ({ page }) => {
  await mount(page, { screen: "panel", permissions: EVIDENCE });
  await expect(page.getByRole("link", { name: "Recorded results for this quotation" })).toHaveAttribute("href", "/quotations/12/discount-authority-history");
});

test("capturing references is confirmed, sends the reviewed generation and reports the lines", async ({ page }) => {
  await mount(page, { screen: "bind", permissions: PREPARE });
  await expectPhoneSafe(page);
  await page.getByRole("button", { name: "Capture approved references" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByRole("button", { name: "Capture references" }).click();
  await expect(page.getByText("References captured for 2 lines.")).toBeVisible();
  expect(await page.evaluate(() => window.__daWrites)).toEqual(["bind:7"]);
});

test("a refused capture says why in plain words and can be cancelled", async ({ page }) => {
  await mount(page, { screen: "bind", permissions: PREPARE, bindRefusal: "REFERENCE_MISSING" }, true, 320);
  await page.getByRole("button", { name: "Capture approved references" }).click();
  await page.getByRole("button", { name: "Capture references" }).click();
  await expect(page.getByRole("alert")).toContainText("No independently approved price reference");
  await expectPhoneSafe(page);
  await page.getByRole("button", { name: "Cancel" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
});

test("capturing references is not offered without prepare", async ({ page }) => {
  await mount(page, { screen: "bind", permissions: ["sales.quotation.view", ...EVIDENCE] });
  await expect(page.getByRole("button", { name: "Capture approved references" })).toHaveCount(0);
});
