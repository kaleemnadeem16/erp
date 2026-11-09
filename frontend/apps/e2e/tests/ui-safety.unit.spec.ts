import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readFileSync, readdirSync } from "node:fs";
import { QueryClient } from "@tanstack/react-query";

import { formatDate, formatDateTimeWithZone } from "../../../packages/core-ui/src/lib/format-date";
import { clearPrivateQueryCache } from "../../../packages/core-ui/src/lib/session-cache";
import { completeLookupState, describeLoadError } from "../../../packages/core-ui/src/lib/api-errors";

// Pure rules -------------------------------------------------------------------------------------------------------

test.describe("calendar dates", () => {
  // Pure functions run in Node, so the zone is set on the process (Playwright's timezoneId only reaches browser pages).
  let previousZone: string | undefined;
  test.beforeAll(() => {
    previousZone = process.env.TZ;
    process.env.TZ = "America/Los_Angeles";
  });
  test.afterAll(() => {
    if (previousZone === undefined) delete process.env.TZ;
    else process.env.TZ = previousZone;
  });

  test("a date-only value keeps its day west of UTC", () => {
    expect(formatDate("2026-11-06")).toBe(new Date(2026, 10, 6).toLocaleDateString());
    expect(formatDate("2026-11-06")).toContain("6");
    expect(formatDate("2026-11-06")).not.toContain("5");
    expect(formatDate("2026-01-01")).toBe(new Date(2026, 0, 1).toLocaleDateString());
  });

  test("a full timestamp is still a moment and a missing date stays missing", () => {
    expect(formatDate("2026-11-06T03:00:00Z")).toBe(new Date("2026-11-06T03:00:00Z").toLocaleDateString());
    expect(formatDate(null)).toBeUndefined();
    expect(formatDateTimeWithZone("2026-11-06T03:00:00Z")).toMatch(/PST|GMT-8/);
  });
});

test.describe("session cache", () => {
  test("clearing the session cache removes private results but can spare the identity check", async () => {
    const client = new QueryClient();
    client.setQueryData(["/v1/user"], { id: 1 });
    client.setQueryData(["/v1/quotations"], { secret: true });
    clearPrivateQueryCache(client, ["/v1/user"]);
    expect(client.getQueryData(["/v1/quotations"])).toBeUndefined();
    expect(client.getQueryData(["/v1/user"])).toEqual({ id: 1 });
    clearPrivateQueryCache(client);
    expect(client.getQueryData(["/v1/user"])).toBeUndefined();
  });
});

test.describe("complete lookups", () => {
  const query = (shown: number, total: number) => ({
    isLoading: false,
    isError: false,
    error: null,
    refetch: () => undefined,
    data: { status: 200, data: { data: new Array(shown).fill({}), meta: { total } } },
  });

  test("a first page of a longer list is reported, not offered as the whole list", () => {
    const state = completeLookupState(query(100, 140), "warehouses");
    expect(state.loadError).toBeDefined();
    expect(describeLoadError(state.loadError?.error)).toMatch(/first 100 of 140/);
  });

  test("a complete list has no error", () => {
    expect(completeLookupState(query(3, 3), "units").loadError).toBeUndefined();
  });
});

// Screens ----------------------------------------------------------------------------------------------------------

const origin = "http://ui-safety.test";
const bundle = buildSync({
  entryPoints: [new URL("./fixtures/ui-safety.tsx", import.meta.url).pathname],
  bundle: true,
  write: false,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  define: { "process.env.NODE_ENV": '"production"' },
}).outputFiles[0]!.text;
const assets = new URL("../../main/dist/assets/", import.meta.url);
const css = readdirSync(assets)
  .filter((name) => name.endsWith(".css"))
  .map((name) => readFileSync(new URL(name, assets), "utf8"))
  .join("\n");

type Writes = { method: string; path: string }[];

async function mount(page: Page, path: string, replies: Record<string, { status: number; body: unknown }>) {
  const writes: Writes = [];
  await page.route("**/*", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) return route.abort();
    if (url.pathname.startsWith("/api/")) {
      if (request.method() !== "GET") writes.push({ method: request.method(), path: url.pathname });
      const hit = replies[`${request.method()} ${url.pathname}`];
      const reply = hit ?? { status: 200, body: { data: [], meta: { total: 0 }, links: {} } };
      return route.fulfill({ status: reply.status, contentType: "application/json", body: JSON.stringify(reply.body) });
    }
    return route.fulfill({ contentType: "text/html", body: '<!doctype html><html lang="en"><head><title>UI safety</title></head><body><div id="root"></div></body></html>' });
  });
  await page.goto(origin + path);
  await page.addStyleTag({ content: css });
  await page.addScriptTag({ content: bundle });
  return writes;
}

const cutover = {
  id: 7, status: "APPROVED", mode: "FULL", cutover_at: "2026-11-01T00:00:00Z", source_system: "Legacy", control_quantity: "1", control_value: "1",
  created_by: 1, validated_by: 2, batches: [], negative_openings: [],
};

test.describe("high-impact actions ask first", () => {
  test("committing a cutover needs a confirmation and shows the server's refusal inside it", async ({ page }) => {
    const writes = await mount(page, "/inventory-cutovers/7", {
      "GET /api/v1/inventory-cutovers/7": { status: 200, body: { data: cutover } },
      "POST /api/v1/inventory-cutovers/7/commit": { status: 422, body: { message: "A batch is not ready." } },
    });
    await page.getByRole("button", { name: "Commit opening state" }).click();
    expect(writes).toHaveLength(0);
    const dialog = page.getByRole("dialog");
    await expect(dialog).toContainText("cannot be undone");
    await dialog.getByRole("button", { name: "Not now" }).click();
    expect(writes).toHaveLength(0);
    await page.getByRole("button", { name: "Commit opening state" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Commit opening state" }).click();
    await expect(page.getByRole("dialog").getByRole("alert")).toHaveText("A batch is not ready.");
    expect(writes).toEqual([{ method: "POST", path: "/api/v1/inventory-cutovers/7/commit" }]);
  });

  test("finalising a cutover asks first", async ({ page }) => {
    const writes = await mount(page, "/inventory-cutovers/7", { "GET /api/v1/inventory-cutovers/7": { status: 200, body: { data: cutover } } });
    await page.getByRole("button", { name: "Finalise" }).click();
    await expect(page.getByRole("dialog")).toContainText("Finalise this cutover?");
    expect(writes).toHaveLength(0);
  });

  const count = {
    id: 5, status: "DRAFT", warehouse_id: 1, created_by: 1, reference: "C1",
    lines: [{ id: 1, item_id: 1, location_id: 1, counted_qty: "3", book_qty: "3", grouped: false, stale_reason: null, item: { code: "I", name: "Item" } }],
  };

  test("posting and cancelling a physical count each ask first", async ({ page }) => {
    const writes = await mount(page, "/physical-counts/5", { "GET /api/v1/physical-counts/5": { status: 200, body: { data: count } } });
    await page.getByRole("button", { name: "Post count" }).click();
    await expect(page.getByRole("dialog")).toContainText("Post this count?");
    expect(writes).toHaveLength(0);
    await page.getByRole("button", { name: "Not yet" }).click();
    await page.getByRole("button", { name: "Cancel count" }).click();
    await expect(page.getByRole("dialog")).toContainText("Cancel this count?");
    expect(writes).toHaveLength(0);
  });

  test("executing a recalculation asks first", async ({ page }) => {
    const version = {
      id: 9, version_no: 1, is_stale: false, is_decided: true, has_unexpired_full_approval: true, is_rejected: false, execution: null,
      approvals: [], risk_classification: "LOW", required_approval_count: 1, original_qty_on_hand: "1", original_inventory_value: "1",
      counterfactual_qty_on_hand: "1", counterfactual_inventory_value: "1", valuation_adjustment: "0", regeneration_review: null,
    };
    const request = {
      id: 3, item_id: 1, location_id: 1, movement_kind: "RECEIPT", qty: "1", moved_at: "2026-10-01T00:00:00Z", source_type: "X", source_id: 1,
      horizon_mode: "STANDARD", requested_by: 1, requested_at: "2026-10-01T00:00:00Z", review_due_at: "2026-10-02T00:00:00Z", impact_versions: [version],
    };
    const writes = await mount(page, "/recalculation-requests/3", { "GET /api/v1/recalculation-requests/3": { status: 200, body: { data: request } } });
    await page.getByRole("button", { name: "Execute", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Execute this recalculation?");
    expect(writes).toHaveLength(0);
  });
});

test.describe("material preview lookups", () => {
  test("a denied operator triggers no lookup requests", async ({ page }) => {
    const seen: string[] = [];
    await page.route("**/*", async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname.startsWith("/api/")) {
        seen.push(url.pathname);
        return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data: [], meta: { total: 0 } }) });
      }
      return route.fulfill({ contentType: "text/html", body: '<!doctype html><html lang="en"><head><title>UI safety</title></head><body><div id="root"></div></body></html>' });
    });
    await page.goto(origin + "/material-preview?permissions=sales.view");
    await page.addStyleTag({ content: css });
    await page.addScriptTag({ content: bundle });
    await expect(page.getByRole("alert")).toContainText("do not have permission");
    await page.waitForTimeout(300);
    expect(seen).toEqual([]);
  });

  test("the assembly picker searches the server instead of listing a first page", async ({ page }) => {
    const searches: (string | null)[] = [];
    await page.route("**/*", async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname === "/api/v1/assemblies") {
        searches.push(url.searchParams.get("filter[search]"));
        const rows = url.searchParams.get("filter[search]") === "zeta" ? [{ id: 900, code: "zeta-asm", name: "Beyond page one", versions: [], work_uom_id: 1 }] : [];
        return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data: rows, meta: { total: rows.length } }) });
      }
      if (url.pathname.startsWith("/api/")) return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data: [], meta: { total: 0 } }) });
      return route.fulfill({ contentType: "text/html", body: '<!doctype html><html lang="en"><head><title>UI safety</title></head><body><div id="root"></div></body></html>' });
    });
    await page.goto(origin + "/material-preview");
    await page.addStyleTag({ content: css });
    await page.addScriptTag({ content: bundle });
    await page.getByRole("combobox", { name: "Assembly", exact: true }).click();
    await page.getByPlaceholder("Search assembly…").or(page.getByRole("combobox").last()).first().fill("zeta");
    await expect(page.getByText("zeta-asm — Beyond page one")).toBeVisible();
    expect(searches).toContain("zeta");
  });
});
