import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";
const script = buildSync({
  entryPoints: [new URL("./fixtures/jobs.tsx", import.meta.url).pathname],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
const assets = new URL("../../main/dist/assets/", import.meta.url);
const css = readdirSync(assets)
  .filter((n) => n.endsWith(".css"))
  .map((n) => readFileSync(new URL(n, assets), "utf8"))
  .join("\n");
const time = "2026-11-16T10:00:00.000000Z";
const summary = {
  id: 7,
  number: "JOB-007",
  description: "Inspect roof",
  state: "CLOSED",
  responsible_member: { id: 3, name: "Sam Surveyor" },
  has_open_hold: false,
  created_at: time,
  released_at: time,
  started_at: time,
};
const detail = {
  ...summary,
  baseline: {
    version: 1,
    scope_description: "Inspect the agreed roof area",
    deliverable: "A roof survey",
    agreed_conditions: "During daylight",
    recorded_at: time,
  },
  assignment: {
    member_id: 3,
    member_name: "Sam Surveyor",
    intended_from: null,
    intended_until: null,
    recorded_at: time,
  },
  default_activity: { id: 2, description: "Roof inspection" },
  open_holds: [],
  completion: {
    statement: "Delivered in full.",
    recorded_at: time,
    claimed_occurred_at: null,
    occurrence_precision: "UNKNOWN",
  },
  closeout: {
    statement: "Operationally closed.",
    recorded_at: time,
    claimed_occurred_at: null,
    occurrence_precision: "UNKNOWN",
  },
};
const operations = [
  "RELEASE",
  "ASSIGN",
  "START",
  "HOLD",
  "RESOLVE_HOLD",
  "COMPLETE",
  "CLOSE",
];
async function mount(
  page: Page,
  opts: {
    path?: string;
    width?: number;
    dark?: boolean;
    state?: "empty" | "error" | "loading" | "forbidden" | "missing";
  } = {},
) {
  const requests: URL[] = [];
  await page.setViewportSize({ width: opts.width ?? 1280, height: 900 });
  await page.route("**/*", async (route) => {
    const url = new URL(route.request().url());
    if (route.request().resourceType() === "document")
      return route.fulfill({
        contentType: "text/html",
        body: '<!doctype html><html lang="en"><head><title>Jobs test</title></head><body><div id="root"></div></body></html>',
      });
    if (url.pathname.startsWith("/api/v1/jobs")) {
      requests.push(url);
      if (opts.state === "loading") return new Promise(() => {});
      if (
        opts.state === "error" ||
        opts.state === "forbidden" ||
        opts.state === "missing"
      )
        return route.fulfill({
          status:
            opts.state === "error" ? 500 : opts.state === "missing" ? 404 : 403,
          json: {
            message:
              opts.state === "missing"
                ? "The Job was not found."
                : "Request refused",
          },
        });
      if (url.pathname.endsWith("/history")) {
        const second = url.searchParams.get("page") === "2";
        const data = (second ? ["CLOSE"] : operations.slice(0, 6)).map(
          (operation, i) => ({
            id: second ? 7 : i + 1,
            sequence: second ? 7 : i + 1,
            operation,
            from_state: "ACTIVE",
            to_state: operation === "CLOSE" ? "CLOSED" : "COMPLETED",
            recorded_at: time,
            claimed_occurred_at: null,
            occurrence_precision: "UNKNOWN",
            actor: { id: 3, name: "Sam Surveyor" },
            reason: null,
            responsible_member_id: null,
            hold_id: null,
          }),
        );
        return route.fulfill({
          json: {
            data,
            meta: {
              current_page: second ? 2 : 1,
              per_page: 25,
              total: 26,
              last_page: 2,
            },
          },
        });
      }
      if (url.pathname === "/api/v1/jobs/7")
        return route.fulfill({ json: { data: detail } });
      const empty = opts.state === "empty";
      return route.fulfill({
        json: {
          data: empty ? [] : [summary],
          meta: {
            current_page: Number(url.searchParams.get("page") ?? 1),
            per_page: 25,
            total: empty ? 0 : 51,
            last_page: empty ? 1 : 3,
          },
        },
      });
    }
    return route.abort();
  });
  await page.goto(`http://jobs.test:48888${opts.path ?? "/jobs"}`);
  await page.addStyleTag({ content: css });
  if (opts.dark)
    await page.evaluate(() => {
      localStorage.setItem("erp.appearance", JSON.stringify({ mode: "dark" }));
      document.documentElement.classList.add("dark");
    });
  await page.addScriptTag({ content: script });
  return requests;
}
test("exact read duty only; inactive duty produces no navigation, screen or requests", async ({
  page,
}) => {
  expect(canAccessScreen("/jobs", new Set(["job.close"]))).toBe(false);
  expect(canAccessScreen("/jobs/7", new Set(["job.view"]))).toBe(true);
  const requests = await mount(page, { path: "/jobs?denied=1" });
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Jobs", exact: true }),
  ).toHaveCount(0);
  expect(requests).toHaveLength(0);
});
test("server search, filters, sorting, pagination and sheet keyboard context", async ({
  page,
}) => {
  const requests = await mount(page);
  await expect(page.getByText("Sam Surveyor", { exact: true })).toBeVisible();
  await page
    .getByRole("searchbox", { name: "Search Jobs by number or description" })
    .fill("roof");
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("filter[search]"))
    .toBe("roof");
  const state = page.getByRole("combobox", { name: "Job status", exact: true });
  await state.click();
  await page
    .getByRole("option", { name: "Closed", exact: true })
    .press("Enter");
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("filter[state]"))
    .toBe("CLOSED");
  await page.getByRole("button", { name: "Next page", exact: true }).click();
  await expect.poll(() => requests.at(-1)?.searchParams.get("page")).toBe("2");
  await page.getByRole("button", { name: "Job number", exact: true }).click();
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("sort"))
    .toBe("number");
  await expect.poll(() => requests.at(-1)?.searchParams.get("page")).toBe("1");
  await page.getByRole("button", { name: /More filters/ }).click();
  await page
    .getByRole("region", { name: "Advanced filters" })
    .getByLabel("Responsible employee ID", { exact: true })
    .fill("3");
  await expect
    .poll(() =>
      requests.at(-1)?.searchParams.get("filter[responsible_member_id]"),
    )
    .toBe("3");
  const row = page.getByRole("row").filter({ hasText: "JOB-007" });
  await row.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("dialog")).toBeVisible();
  await expect(page.getByText("Inspect the agreed roof area")).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Completion", exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Delivered in full.")).toBeVisible();
  await expect(page.getByText("Completed", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Next history page" }).click();
  await expect(page.getByText("Closed", { exact: true }).last()).toBeVisible();
  await expect
    .poll(() =>
      requests
        .filter((u) => u.pathname.endsWith("/history"))
        .at(-1)
        ?.searchParams.get("page"),
    )
    .toBe("2");
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page).toHaveURL(/filter.state=CLOSED/);
});
for (const dark of [false, true])
  test(`phone detail and list accessibility ${dark ? "dark" : "light"}`, async ({
    page,
  }) => {
    await mount(page, { width: 390, dark });
    await expect(page.getByText("JOB-007", { exact: true })).toBeVisible();
    let results = await new AxeBuilder({ page }).analyze();
    expect(
      results.violations.filter((v) =>
        ["serious", "critical"].includes(v.impact ?? ""),
      ),
    ).toEqual([]);
    await page.getByText("JOB-007", { exact: true }).click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(page.getByText("Operationally closed.")).toBeVisible();
    if (dark) await expect(page.locator("html")).toHaveClass(/dark/);
    await expect
      .poll(async () => (await page.getByRole("dialog").boundingBox())?.x)
      .toBeLessThanOrEqual(1);
    expect(
      (await page.getByRole("dialog").boundingBox())?.width,
    ).toBeLessThanOrEqual(390);
    await page.screenshot({
      path: `/tmp/erp-jobs-${dark ? "dark" : "light"}.png`,
    });
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth - innerWidth,
      ),
    ).toBeLessThanOrEqual(0);
    results = await new AxeBuilder({ page }).analyze();
    expect(
      results.violations.filter((v) =>
        ["serious", "critical"].includes(v.impact ?? ""),
      ),
    ).toEqual([]);
    await expect(
      page.getByRole("button", {
        name: /assign|start job|complete job|close job|place hold/i,
      }),
    ).toHaveCount(0);
  });
for (const state of ["empty", "error", "loading"] as const)
  test(`list ${state} state`, async ({ page }) => {
    await mount(page, { state });
    if (state === "empty")
      await expect(
        page.getByText("No Jobs have been released yet."),
      ).toBeVisible();
    else if (state === "error")
      await expect(
        page.getByRole("button", { name: "Try again" }),
      ).toBeVisible();
    else {
      await expect(
        page.getByRole("heading", { name: "Jobs", exact: true }),
      ).toBeVisible();
      await expect(page.locator("main .animate-pulse").first()).toBeVisible();
      await expect(page.getByText("JOB-007", { exact: true })).toHaveCount(0);
    }
  });
for (const state of ["forbidden", "missing"] as const)
  test(`direct detail ${state} state`, async ({ page }) => {
    await mount(page, { path: "/jobs/7", state });
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await expect(
      dialog.getByText(
        state === "forbidden"
          ? "You don’t have access"
          : "Couldn’t load this Job.",
      ),
    ).toBeVisible();
  });

test("phone filters send hold and date boundaries; clearing restores the unfiltered list", async ({
  page,
}) => {
  const requests = await mount(page, { width: 390 });
  await expect(page.getByText("JOB-007", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Open filters", exact: true }).click();
  const sheet = page.getByRole("dialog");
  await sheet.getByRole("combobox", { name: "Open hold", exact: true }).click();
  await page
    .getByRole("option", { name: "Has open hold", exact: true })
    .press("Enter");
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("filter[has_open_hold]"))
    .toBe("true");
  const from = sheet.getByRole("group", { name: "Created from", exact: true });
  await from.getByRole("spinbutton", { name: "Month", exact: true }).fill("11");
  await from.getByRole("spinbutton", { name: "Day", exact: true }).fill("01");
  await from
    .getByRole("spinbutton", { name: "Year", exact: true })
    .fill("2026");
  await from
    .getByRole("spinbutton", { name: "Year", exact: true })
    .press("Tab");
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("filter[created_from]"))
    .toBe("2026-11-01");
  await sheet
    .getByRole("button", { name: "Clear filters", exact: true })
    .click();
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("filter[has_open_hold]"))
    .toBe(null);
  await expect
    .poll(() => requests.at(-1)?.searchParams.get("filter[created_from]"))
    .toBe(null);
  await sheet.getByRole("button", { name: "Done", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
});

test("desktop list and detail have no serious accessibility violations", async ({
  page,
}) => {
  await mount(page);
  await expect(page.getByText("JOB-007", { exact: true })).toBeVisible();
  let results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) =>
      ["serious", "critical"].includes(v.impact ?? ""),
    ),
  ).toEqual([]);
  await page.getByRole("row").filter({ hasText: "JOB-007" }).press("Enter");
  await expect(page.getByRole("dialog")).toBeVisible();
  results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter((v) =>
      ["serious", "critical"].includes(v.impact ?? ""),
    ),
  ).toEqual([]);
});
