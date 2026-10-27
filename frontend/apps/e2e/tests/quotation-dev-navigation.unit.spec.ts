import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readFileSync, readdirSync } from "node:fs";
const script = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-dev-navigation.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
async function mount(page: Page, params: string, hostname = "localhost") {
  const unexpected: string[] = [];
  await page.route("**/*", async (route) => {
    if (route.request().resourceType() === "document")
      await route.fulfill({
        contentType: "text/html",
        body: '<!doctype html><html lang="en"><head><title>Quotations fixture</title></head><body><div id="root"></div></body></html>',
      });
    else {
      unexpected.push(route.request().url());
      await route.abort();
    }
  });
  await page.goto(`http://${hostname}:48888/quotations?${params}`);
  const assets = new URL("../../main/dist/assets/", import.meta.url);
  await page.addStyleTag({
    content: readdirSync(assets)
      .filter((name) => name.endsWith(".css"))
      .map((name) => readFileSync(new URL(name, assets), "utf8"))
      .join("\n"),
  });
  await page.addScriptTag({ content: script });
  return unexpected;
}
for (const params of [
  "build=prod&flag=1&duty=view",
  "build=dev&duty=view",
  "build=dev&flag=1&duty=none",
])
  test(`navigation and direct workspace denied for ${params}`, async ({
    page,
  }) => {
    const requests = await mount(page, params);
    await expect(
      page.getByRole("heading", { name: "You don’t have access" }),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Quotations", exact: true }),
    ).toHaveCount(0);
    expect(requests).toEqual([]);
  });
test("dev opt-in on a remote host still denies the real workspace", async ({
  page,
}) => {
  await mount(page, "build=dev&flag=1&duty=view", "quotation.test");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Quotations", exact: true }),
  ).toHaveCount(0);
});
test("localhost view holder can use navigation and open a validated revision by keyboard", async ({
  page,
}) => {
  const requests = await mount(page, "build=dev&flag=1&duty=view");
  await expect(
    page
      .locator("aside")
      .getByRole("link", { name: "Quotations", exact: true }),
  ).toBeVisible();
  await page.getByLabel("Revision number").fill("abc");
  await page.getByLabel("Revision number").press("Enter");
  await expect(page.getByRole("alert")).toHaveText(
    "Enter a positive whole revision number.",
  );
  expect(requests).toEqual([]);
  await page.getByLabel("Revision number").fill("12");
  await page.getByLabel("Revision number").press("Enter");
  await expect(
    page.getByRole("heading", { name: "Opened /quotations/12" }),
  ).toBeVisible();
  expect(requests).toEqual([]);
});
test("phone More navigation exposes only the entitled review entry", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page, "build=dev&flag=1&duty=prepare");
  await page.getByRole("button", { name: "More", exact: true }).click();
  await expect(
    page
      .getByRole("dialog")
      .getByRole("link", { name: "Quotations", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("dialog")
    .getByRole("link", { name: "Quotations", exact: true })
    .click();
  await expect(page.getByRole("button", { name: "Open details" })).toHaveCount(
    0,
  );
  await page.getByLabel("Revision number").fill("15");
  await page.getByRole("button", { name: "Review quotation" }).click();
  await expect(
    page.getByRole("heading", { name: "Opened /quotations/15/review" }),
  ).toBeVisible();
});
