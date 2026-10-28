import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import AxeBuilder from "@axe-core/playwright";
import { readFileSync, readdirSync } from "node:fs";
import {
  buildChanges,
  readEditable,
  valuesFromEditable,
} from "../../../packages/core-ui/src/features/quotations/quotation-draft-changes";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";
const source = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-form.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
const editable = {
  revision_id: 12,
  family_id: 8,
  state: "DRAFT",
  input_generation: 3,
  recipient_party_id: null,
  currency_id: 2,
  pricing_date: null,
  tax_estimate_date: null,
  valid_through_local_date: null,
  current_opportunity_id: null,
  lines: [
    {
      id: 11,
      scope_ref: "scope-a",
      section_title: null,
      description: "Survey",
      membership: "BASE",
      choice_group_ref: null,
      is_baseline: false,
      source_kind: "MANUAL",
      item_id: null,
      manual_measure: "job",
      offered_quantity: "1.0000",
      offered_uom_id: null,
      unit_price: null,
      sort_order: 10,
    },
  ],
};

test("minimal changes preserve references and exact decimals", () => {
  const base = readEditable(editable);
  const values = valuesFromEditable(base);
  values.lines[0]!.offered_quantity = "2.0000";
  values.lines[0]!.unit_price = "0";
  expect(buildChanges(base, values)).toEqual({
    expected_generation: 3,
    update_lines: [{ id: 11, offered_quantity: "2.0000", unit_price: "0" }],
  });
  values.removed = [11];
  values.lines = [];
  expect(buildChanges(base, values)).toEqual({
    expected_generation: 3,
    remove_lines: [11],
  });
  expect(() =>
    readEditable({
      ...editable,
      lines: [{ ...editable.lines[0], source_kind: "UNSUPPORTED" }],
    }),
  ).toThrow();
});
test("draft routes stay default-deny", () => {
  expect(
    canAccessScreen("/quotations/new", new Set(["sales.quotation.prepare"])),
  ).toBe(false);
  expect(
    canAccessScreen(
      "/quotations/12/edit",
      new Set(["sales.quotation.prepare"]),
    ),
  ).toBe(false);
});
async function mount(
  page: Page,
  path: string,
  handle: (
    url: URL,
    method: string,
    body: string | null,
    headers: Record<string, string>,
  ) => { status: number; body: unknown },
) {
  const writes: {
    method: string;
    body: string | null;
    key: string | undefined;
  }[] = [];
  await page.route("http://localhost:5178/**", async (route) => {
    const req = route.request(),
      url = new URL(req.url());
    if (url.pathname.startsWith("/api/")) {
      const body = req.postData(),
        headers = req.headers();
      if (req.method() !== "GET")
        writes.push({
          method: req.method(),
          body,
          key: headers["idempotency-key"],
        });
      const result = handle(url, req.method(), body, headers);
      await route.fulfill({
        status: result.status,
        contentType: "application/json",
        body: JSON.stringify(result.body),
      });
    } else
      await route.fulfill({
        contentType: "text/html",
        body: '<!doctype html><html lang="en"><head><title>Quotation draft</title></head><body><div id="root"></div></body></html>',
      });
  });
  await page.goto(`http://localhost:5178${path}`);
  await page.addScriptTag({ content: source });
  return writes;
}
const list = { data: [], links: {}, meta: {} };
test("create retries an uncertain request with the same key and payload", async ({
  page,
}) => {
  let count = 0;
  const writes = await mount(page, "/quotations/new", (url, method) => {
    if (method === "POST") {
      count++;
      return count === 1
        ? { status: 503, body: {} }
        : {
            status: 201,
            body: {
              data: {
                family_id: 8,
                revision_id: 12,
                input_generation: 1,
                replayed: false,
              },
            },
          };
    }
    if (url.pathname.endsWith("/editable"))
      return { status: 200, body: { data: editable } };
    return { status: 200, body: list };
  });
  await page.getByRole("button", { name: "Review draft" }).click();
  await page.getByRole("button", { name: "Create draft", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Retry create" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Retry create" }).click();
  await expect(page.getByText("Edit quotation #12")).toBeVisible();
  expect(writes).toHaveLength(2);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[1]!.key).toBe(writes[0]!.key);
  expect(writes[1]!.body).toBe(writes[0]!.body);
});
test("edit retries frozen generation and payload after an uncertain response", async ({
  page,
}) => {
  let count = 0;
  const writes = await mount(page, "/quotations/12/edit", (url, method) => {
    if (method === "PATCH") {
      count++;
      return count === 1
        ? { status: 503, body: {} }
        : {
            status: 200,
            body: {
              data: {
                family_id: 8,
                revision_id: 12,
                input_generation: 4,
                replayed: true,
              },
            },
          };
    }
    if (url.pathname.endsWith("/editable"))
      return { status: 200, body: { data: editable } };
    return { status: 200, body: list };
  });
  await page.getByLabel("Description", { exact: true }).fill("Revised survey");
  await page.getByRole("button", { name: "Review changes" }).click();
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByRole("button", { name: "Retry save" })).toBeVisible();
  await page.getByRole("button", { name: "Retry save" }).click();
  await expect(page.getByText("Quotation workspace")).toBeVisible();
  expect(writes).toHaveLength(2);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[1]).toEqual(writes[0]);
  expect(JSON.parse(writes[0]!.body!)).toEqual({
    expected_generation: 3,
    update_lines: [{ id: 11, description: "Revised survey" }],
  });
});

test("404 editable read is non-disclosing and sends no write", async ({
  page,
}) => {
  const writes = await mount(page, "/quotations/12/edit", (url) =>
    url.pathname.endsWith("/editable")
      ? { status: 404, body: { message: "hidden detail" } }
      : { status: 200, body: list },
  );
  await expect(
    page.getByText("This quotation is not available."),
  ).toBeVisible();
  await expect(page.getByText("hidden detail")).toHaveCount(0);
  expect(writes).toHaveLength(0);
});
test("stale edit preserves input and requires reload before further save", async ({
  page,
}) => {
  let generation = 3;
  const writes = await mount(page, "/quotations/12/edit", (url, method) => {
    if (method === "PATCH") {
      generation = 4;
      return {
        status: 422,
        body: { reason_code: "QUOTATION_DRAFT_STALE", message: "stale" },
      };
    }
    if (url.pathname.endsWith("/editable"))
      return {
        status: 200,
        body: { data: { ...editable, input_generation: generation } },
      };
    return { status: 200, body: list };
  });
  await page
    .getByLabel("Description", { exact: true })
    .fill("My unsaved change");
  await page.getByRole("button", { name: "Review changes" }).click();
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(
    page.getByRole("heading", { name: "Draft changed" }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Save changes" })).toHaveCount(
    0,
  );
  expect(writes).toHaveLength(1);
});
test("reused key refusal leaves inputs for a new reviewed attempt", async ({
  page,
}) => {
  const writes = await mount(page, "/quotations/12/edit", (url, method) => {
    if (method === "PATCH")
      return { status: 409, body: { reason_code: "REQUEST_KEY_REUSED" } };
    if (url.pathname.endsWith("/editable"))
      return { status: 200, body: { data: editable } };
    return { status: 200, body: list };
  });
  await page
    .getByLabel("Description", { exact: true })
    .fill("My edited description");
  await page.getByRole("button", { name: "Review changes" }).click();
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByRole("alert")).toContainText(
    "request key was already used",
  );
  await expect(page.getByLabel("Description", { exact: true })).toHaveValue(
    "My edited description",
  );
  expect(writes).toHaveLength(1);
});

test("an invalid quantity is refused locally without a write", async ({
  page,
}) => {
  const writes = await mount(page, "/quotations/12/edit", (url) =>
    url.pathname.endsWith("/editable")
      ? { status: 200, body: { data: editable } }
      : { status: 200, body: list },
  );
  await page.getByLabel("Quantity").fill("0");
  await page.getByRole("button", { name: "Review changes" }).click();
  await expect(page.getByRole("alert")).toContainText("quantity");
  expect(writes).toHaveLength(0);
});
test("uncertain attempt survives reload without automatic resubmission", async ({
  page,
}) => {
  let count = 0;
  const writes = await mount(page, "/quotations/new", (url, method) => {
    if (method === "POST") {
      count++;
      return { status: 503, body: {} };
    }
    return { status: 200, body: list };
  });
  await page.getByRole("button", { name: "Review draft" }).click();
  await page.getByRole("button", { name: "Create draft", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Retry create" }),
  ).toBeVisible();
  await page.reload();
  await page.addScriptTag({ content: source });
  await expect(
    page.getByRole("button", { name: "Retry create" }),
  ).toBeVisible();
  expect(count).toBe(1);
  await page.getByRole("button", { name: "Retry create" }).click();
  await expect.poll(() => count).toBe(2);
  expect(writes[1]).toEqual(writes[0]);
});

test("edit form fits a phone viewport and has no serious accessibility findings", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page, "/quotations/12/edit", (url) =>
    url.pathname.endsWith("/editable")
      ? { status: 200, body: { data: editable } }
      : { status: 200, body: list },
  );
  const assets = new URL("../../main/dist/assets/", import.meta.url);
  const css = readdirSync(assets).find((name) => name.endsWith(".css"));
  if (!css) throw new Error("Build the frontend before this test.");
  await page.addStyleTag({
    content: readFileSync(new URL(css, assets), "utf8"),
  });
  await expect(page.getByLabel("Description", { exact: true })).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  const results = await new AxeBuilder({ page }).analyze();
  expect(
    results.violations.filter(
      (v) => v.impact === "serious" || v.impact === "critical",
    ),
  ).toEqual([]);
});
