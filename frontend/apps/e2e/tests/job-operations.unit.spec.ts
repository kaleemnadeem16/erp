import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import {
  JobCommandAttempt,
  parseJobReference,
  jobOperations,
} from "../../../packages/core-ui/src/features/jobs/job-operation-contract";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";

const script = buildSync({
  entryPoints: [
    new URL("./fixtures/job-operations.tsx", import.meta.url).pathname,
  ],
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
type Op = keyof typeof jobOperations;
const allDuties = [...new Set(Object.values(jobOperations).map((o) => o.duty))];

async function mount(
  page: Page,
  opts: {
    duties?: string[];
    operation?: Op;
    width?: number;
    dark?: boolean;
    state?: string;
    holds?: number;
    assigned?: boolean;
    uncertain?: boolean;
    concurrent?: boolean;
    closeBlocked?: boolean;
    stale?: boolean;
    revoked?: boolean;
    sourceDenied?: boolean;
    contextRevoked?: boolean;
    blocked?: boolean;
    delayed?: boolean;
  } = {},
) {
  const requests: {
    method: string;
    path: string;
    body: Record<string, unknown> | null;
    search: URLSearchParams;
  }[] = [];
  const applied = new Map<string, object>();
  let generation = 1;
  let state = opts.state ?? "RELEASED";
  let assigned = opts.assigned ?? false;
  let first = true;
  let holds = Array.from({ length: opts.holds ?? 0 }, (_, i) => ({
    id: i + 11,
    reason: `Independent hold ${i + 1}`,
    recorded_at: "2026-11-24T09:00:00Z",
  }));
  let duties = opts.duties ?? allDuties;
  let eventId = 50;
  await page.setViewportSize({ width: opts.width ?? 1280, height: 900 });
  await page.route("**/*", async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    if (route.request().resourceType() === "document")
      return route.fulfill({
        contentType: "text/html",
        body: '<!doctype html><html lang="en"><head><title>Job operations test</title></head><body><div id="root"></div></body></html>',
      });
    if (url.pathname === "/api/v1/user")
      return route.fulfill({
        json: {
          data: {
            id: 1,
            name: "Operator",
            email: "operator@example.test",
            must_change_password: false,
            permissions: duties,
          },
        },
      });
    if (url.pathname.startsWith("/api/v1/jobs")) {
      const body =
        method === "POST"
          ? (route.request().postDataJSON() as Record<string, unknown>)
          : null;
      requests.push({
        method,
        path: url.pathname,
        body,
        search: url.searchParams,
      });
      if (url.pathname.endsWith("/operation-context")) {
        if (opts.contextRevoked) {
          duties = [];
          return route.fulfill({
            status: 403,
            json: { message: "Not permitted." },
          });
        }
        if (opts.sourceDenied)
          return route.fulfill({
            status: 404,
            json: { message: "The Job was not found." },
          });
        const operation = url.searchParams.get("operation");
        const extra =
          operation === "ASSIGN"
            ? {
                responsibility_id: assigned ? 5 : null,
                has_responsibility: assigned,
              }
            : operation === "START"
              ? { has_responsibility: assigned, open_hold_count: holds.length }
              : operation === "RESOLVE_HOLD"
                ? { open_holds: holds }
                : operation === "COMPLETE"
                  ? { open_hold_count: holds.length }
                  : ["CLOSE", "ASSESS_CLOSEOUT"].includes(operation ?? "")
                    ? {
                        open_hold_count: holds.length,
                        completion_recorded_at:
                          state === "COMPLETED" ? "2026-11-24T09:00:00Z" : null,
                      }
                    : {};
        return route.fulfill({
          json: {
            data: {
              job_id: 7,
              number: "JOB-007",
              state,
              generation,
              operation,
              ...extra,
            },
          },
        });
      }
      if (url.pathname.endsWith("/eligible-assignees")) {
        const pageNumber = Number(url.searchParams.get("page") ?? 1);
        const query = url.searchParams.get("search") ?? "";
        const data = query.toLowerCase().includes("sam")
          ? [
              {
                id: pageNumber === 1 ? 3 : 103,
                name: pageNumber === 1 ? "Sam Employee" : "Sam Last Page",
              },
            ]
          : [];
        return route.fulfill({
          json: {
            data,
            meta: {
              current_page: pageNumber,
              last_page: 2,
              total: 21,
              per_page: 20,
            },
          },
        });
      }
      if (body) {
        if (opts.closeBlocked && url.pathname.endsWith("/close"))
          return route.fulfill({
            status: 422,
            json: {
              message: "Closeout conditions changed.",
              reason_code: "CLOSEOUT_BLOCKED",
              errors: { job_id: ["Assess closeout again."] },
            },
          });
        if (opts.delayed)
          await new Promise((resolve) => setTimeout(resolve, 450));
        if (opts.revoked) {
          duties = [];
          return route.fulfill({
            status: 403,
            json: { message: "Not permitted." },
          });
        }
        if (opts.stale && first) {
          first = false;
          generation++;
          return route.fulfill({
            status: 422,
            json: {
              message: "The Job changed; reload it.",
              reason_code: "STALE_GENERATION",
              errors: { expected_generation: ["Reload current Job context."] },
            },
          });
        }
        const key = String(body.command_identity ?? body.request_key);
        if (applied.has(key))
          return route.fulfill({
            json: { data: { ...applied.get(key), replayed: true } },
          });
        if (url.pathname.endsWith("/closeout-assessments")) {
          const result = {
            job_id: 7,
            assessment_id: 9,
            generation,
            status: opts.blocked ? "BLOCKED" : "READY",
            blockers: opts.blocked ? ["OPEN_HOLDS", "SOURCE_NOT_READY"] : [],
            replayed: false,
          };
          applied.set(key, result);
          return route.fulfill({ json: { data: result } });
        }
        const operation = url.pathname.endsWith("/assign")
          ? "ASSIGN"
          : url.pathname.endsWith("/start")
            ? "START"
            : url.pathname.endsWith("/holds")
              ? "HOLD"
              : url.pathname.endsWith("/resolve-hold")
                ? "RESOLVE_HOLD"
                : url.pathname.endsWith("/complete")
                  ? "COMPLETE"
                  : "CLOSE";
        if (operation === "ASSIGN") assigned = true;
        if (operation === "START") state = "ACTIVE";
        if (operation === "COMPLETE") state = "COMPLETED";
        if (operation === "CLOSE") state = "CLOSED";
        if (operation === "HOLD")
          holds.push({
            id: 25,
            reason: String(body.reason),
            recorded_at: "2026-11-24T09:00:00Z",
          });
        if (operation === "RESOLVE_HOLD")
          holds = holds.filter((h) => h.id !== body.hold_id);
        generation++;
        const result = {
          job_id: 7,
          event_id: ++eventId,
          sequence: generation,
          operation,
          state,
          generation,
          replayed: false,
        };
        applied.set(key, result);
        if (opts.concurrent && first) {
          first = false;
          return route.fulfill({
            status: 422,
            json: {
              message: "Concurrent change; retry the request.",
              reason_code: "CONCURRENT_CHANGE",
              errors: { job_id: ["Retry the request."] },
            },
          });
        }
        if (opts.uncertain && first) {
          first = false;
          return route.fulfill({
            status: 500,
            json: { message: "Result uncertain." },
          });
        }
        return route.fulfill({ json: { data: result } });
      }
      return route.fulfill({
        status: 403,
        json: { message: "General reads are not authorised." },
      });
    }
    return route.fulfill({ body: "", status: 204 });
  });
  const params = new URLSearchParams({
    duties: duties.join(","),
    mode: opts.dark ? "dark" : "light",
    job: "7",
    operation: opts.operation ?? "HOLD",
  });
  await page.goto("http://job-operations.test/job-operations?" + params);
  await page.addStyleTag({ content: css });
  await page.addScriptTag({ content: script });
  await expect
    .poll(() =>
      page.evaluate(() => document.documentElement.classList.contains("dark")),
    )
    .toBe(Boolean(opts.dark));
  return { requests, applied, getHolds: () => holds };
}
async function review(page: Page) {
  await page.getByRole("button", { name: "Review Job operation" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await expect(page.getByText("JOB-007", { exact: true })).toBeVisible();
}
async function confirm(page: Page, name: string) {
  await page.locator("#job-command-confirm").check();
  await page.getByRole("button", { name, exact: true }).click();
}

test("command access and identity helpers preserve exact intent and refuse broad view as a substitute", () => {
  for (const { duty } of Object.values(jobOperations)) {
    expect(canAccessScreen("/job-operations", new Set([duty]))).toBe(true);
    expect(canAccessScreen("/jobs", new Set([duty]))).toBe(false);
  }
  expect(canAccessScreen("/job-operations", new Set(["job.view"]))).toBe(false);
  expect(parseJobReference("https://erp.test/jobs/27?x=1")).toBe(27);
  expect(parseJobReference("JOB-027")).toBeNull();
  let n = 0;
  const keys = new JobCommandAttempt(() => `key-${++n}`);
  const first = keys.for(7, "HOLD", { expected_generation: 1, reason: "Wait" });
  keys.settle(new Error("offline"));
  expect(
    keys.for(7, "HOLD", { expected_generation: 1, reason: "Wait" }),
  ).toEqual(first);
  expect(
    keys.for(7, "HOLD", { expected_generation: 2, reason: "Wait" })
      .command_identity,
  ).not.toBe(first.command_identity);
});

test("command-only employee uses only its operation profile and no general directory or Job reads", async ({
  page,
}) => {
  const { requests } = await mount(page, {
    duties: ["job.hold"],
    operation: "HOLD",
  });
  await review(page);
  await expect(page.getByLabel("Operation", { exact: true })).toHaveValue(
    "HOLD",
  );
  await expect(page.getByText("Original agreed work")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Job actions" })).toHaveCount(
    0,
  );
  await page.getByLabel("Reason", { exact: true }).fill("Awaiting access");
  await confirm(page, "Place on hold");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(
    requests.some((r) => !/\/(operation-context|holds)$/.test(r.path)),
  ).toBe(false);
});

test("uncertain response retries original generation, request key and UUID and writes once", async ({
  page,
}) => {
  const { requests, applied } = await mount(page, {
    duties: ["job.hold"],
    uncertain: true,
  });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Wait for access");
  await confirm(page, "Place on hold");
  await expect(
    page.getByRole("button", { name: "Retry original request" }),
  ).toBeVisible();
  await expect(page.getByLabel("Reason", { exact: true })).toBeDisabled();
  await page.getByRole("button", { name: "Retry original request" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const writes = requests.filter((r) => r.method === "POST");
  expect(writes).toHaveLength(2);
  expect(writes[0]!.body).toEqual(writes[1]!.body);
  expect(applied.size).toBe(1);
});

test("stale generation refreshes context and requires a new confirmation and identity", async ({
  page,
}) => {
  const { requests } = await mount(page, { duties: ["job.hold"], stale: true });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Wait");
  await confirm(page, "Place on hold");
  await expect(page.getByText("Review generation 2")).toBeVisible();
  await expect(page.getByRole("checkbox")).not.toBeChecked();
  await confirm(page, "Place on hold");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const writes = requests.filter((r) => r.method === "POST");
  expect(writes[0]!.body!.command_identity).not.toBe(
    writes[1]!.body!.command_identity,
  );
  expect(writes[1]!.body!.expected_generation).toBe(2);
});

test("resolve one selected hold keeps the other independent hold open", async ({
  page,
}) => {
  const host = await mount(page, {
    duties: ["job.hold.resolve"],
    operation: "RESOLVE_HOLD",
    holds: 2,
  });
  await review(page);
  await page.getByLabel("Open hold", { exact: true }).selectOption("11");
  await page.getByLabel("Reason", { exact: true }).fill("Access restored");
  await confirm(page, "Resolve a hold");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(host.getHolds().map((h) => h.id)).toEqual([12]);
});

test("close-only operator records a READY assessment then explicitly closes with its returned id", async ({
  page,
}) => {
  const { requests } = await mount(page, {
    duties: ["job.close"],
    operation: "CLOSE",
    state: "COMPLETED",
  });
  await review(page);
  await expect(
    page.getByRole("button", { name: "Close Job", exact: true }),
  ).toBeDisabled();
  await page.locator("#job-assessment-confirm").check();
  await page
    .getByRole("button", { name: "Assess closeout readiness", exact: true })
    .click();
  await expect(page.getByText("READY", { exact: true })).toBeVisible();
  await page
    .getByLabel("Closeout statement (optional)")
    .fill("Operationally finished");
  await confirm(page, "Close Job");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(
    requests
      .filter((r) => r.method === "POST")
      .map((r) => r.path.split("/").pop()),
  ).toEqual(["closeout-assessments", "close"]);
  expect(
    requests.find((r) => r.path.endsWith("/close"))!.body!.assessment_id,
  ).toBe(9);
});

test("BLOCKED assessment shows safe next steps and cannot enable close", async ({
  page,
}) => {
  await mount(page, {
    duties: ["job.close"],
    operation: "CLOSE",
    state: "COMPLETED",
    blocked: true,
  });
  await review(page);
  await page.locator("#job-assessment-confirm").check();
  await page
    .getByRole("button", { name: "Assess closeout readiness", exact: true })
    .click();
  await expect(page.getByText("BLOCKED", { exact: true })).toBeVisible();
  await expect(
    page.getByText("Resolve every open hold, then assess again."),
  ).toBeVisible();
  await page.getByRole("checkbox").check();
  await expect(
    page.getByRole("button", { name: "Close Job", exact: true }),
  ).toBeDisabled();
});

test("source denial and revocation clear protected context without falling back to Job view", async ({
  page,
}) => {
  const denied = await mount(page, {
    duties: ["job.hold"],
    sourceDenied: true,
  });
  await page.getByRole("button", { name: "Review Job operation" }).click();
  await expect(page.getByText("JOB-007", { exact: true })).toHaveCount(0);
  expect(
    denied.requests.every((r) => r.path.endsWith("/operation-context")),
  ).toBe(true);
  await mount(page, { duties: ["job.hold"], revoked: true });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Wait");
  await confirm(page, "Place on hold");
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }).first(),
  ).toBeVisible();
  await expect(page.getByText("JOB-007", { exact: true })).toHaveCount(0);
});

test("pending writes cannot be submitted twice", async ({ page }) => {
  const { requests } = await mount(page, {
    duties: ["job.hold"],
    delayed: true,
  });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Wait");
  await page.getByRole("checkbox").check();
  await page
    .getByRole("button", { name: "Place on hold", exact: true })
    .dblclick();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(requests.filter((r) => r.method === "POST")).toHaveLength(1);
});

for (const dark of [false, true])
  test(`keyboard assignee search, pagination and accessible phone layout in ${dark ? "dark" : "light"} mode`, async ({
    page,
  }) => {
    const { requests } = await mount(page, {
      duties: ["job.assign"],
      operation: "ASSIGN",
      width: 390,
      dark,
    });
    await review(page);
    const picker = page.getByRole("combobox", {
      name: "Responsible employee",
      exact: true,
    });
    await picker.focus();
    await page.keyboard.press("Enter");
    await page.getByPlaceholder("Type at least 2 name characters…").fill("Sam");
    await expect(
      page.getByRole("option", { name: "Sam Employee · 3" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "More employees" }).click();
    const last = page.getByRole("option", { name: "Sam Last Page · 103" });
    await expect(last).toBeVisible();
    await page.getByPlaceholder("Type at least 2 name characters…").focus();
    await page.keyboard.press("ArrowDown");
    await page.keyboard.press("Enter");
    await expect(picker).toContainText("Sam Last Page");
    const accessibility = await new AxeBuilder({ page }).analyze();
    expect(
      accessibility.violations.filter((v) =>
        ["serious", "critical"].includes(v.impact ?? ""),
      ),
    ).toEqual([]);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    await page.screenshot({
      path: `/tmp/erp-132-job-actions-${dark ? "dark" : "light"}.png`,
      fullPage: true,
    });
    await page.getByRole("checkbox").check();
    const submit = page.getByRole("button", {
      name: "Assign responsibility",
      exact: true,
    });
    await submit.focus();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("dialog")).toHaveCount(0);
    expect(
      requests.find((r) => r.path.endsWith("/assign"))!.body!.assignee_id,
    ).toBe(103);
    expect(
      requests.some(
        (r) => r.path.includes("members") || r.path.includes("sales"),
      ),
    ).toBe(false);
  });

test("assign then start uses independent duties and the refreshed generation", async ({
  page,
}) => {
  const { requests } = await mount(page, { operation: "ASSIGN" });
  await review(page);
  await page
    .getByRole("combobox", { name: "Responsible employee", exact: true })
    .click();
  const search = page.getByPlaceholder("Type at least 2 name characters…");
  await search.fill("Sam");
  await expect(
    page.getByRole("option", { name: "Sam Employee · 3" }),
  ).toBeVisible();
  await search.press("ArrowDown");
  await search.press("Enter");
  await confirm(page, "Assign responsibility");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await page.getByLabel("Operation", { exact: true }).selectOption("START");
  await review(page);
  await expect(page.getByText("Responsibility is recorded.")).toBeVisible();
  await confirm(page, "Start work");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const writes = requests.filter((r) => r.method === "POST");
  expect(writes.map((r) => r.body!.expected_generation)).toEqual([1, 2]);
});

test("held and closed states refuse a fresh start or hold without any write", async ({
  page,
}) => {
  const held = await mount(page, {
    duties: ["job.start"],
    operation: "START",
    assigned: true,
    holds: 1,
  });
  await review(page);
  await expect(
    page.getByText("Resolve every open hold before starting work."),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Start work", exact: true }),
  ).toBeDisabled();
  expect(held.requests.some((r) => r.method === "POST")).toBe(false);
  await mount(page, { duties: ["job.hold"], state: "CLOSED" });
  await review(page);
  await expect(
    page.getByText(
      "This Job is closed. Its recorded history cannot be changed.",
    ),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Place on hold", exact: true }),
  ).toBeDisabled();
});

test("dirty confirmation preserves input on Escape and requires explicit discard", async ({
  page,
}) => {
  await mount(page, { duties: ["job.hold"] });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Keep this reason");
  await page.keyboard.press("Escape");
  await expect(page.getByText("Discard these unsaved changes?")).toBeVisible();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByRole("button", { name: "Keep editing" }).click();
  await expect(page.getByLabel("Reason", { exact: true })).toHaveValue(
    "Keep this reason",
  );
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await page
    .getByRole("button", { name: "Discard changes", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
});

test("completion cannot attest unseen agreed conditions while its disclosure contract is pending", async ({
  page,
}) => {
  const { requests } = await mount(page, {
    duties: ["job.complete"],
    operation: "COMPLETE",
    state: "ACTIVE",
  });
  await review(page);
  await expect(
    page.getByRole("button", { name: "Complete deliverable", exact: true }),
  ).toBeDisabled();
  await expect(
    page.getByText(
      "The exact agreed work and completion conditions must be available for review before completing this Job. Contact the responsible reviewer.",
    ),
  ).toBeVisible();
  expect(requests.some((r) => r.method === "POST")).toBe(false);
});

test("assignment optional fields retain explicit occurrence and intended timestamps in UTC", async ({
  page,
}) => {
  const { requests } = await mount(page, {
    duties: ["job.assign"],
    operation: "ASSIGN",
    width: 390,
  });
  await review(page);
  await page
    .getByRole("combobox", { name: "Responsible employee", exact: true })
    .click();
  const search = page.getByPlaceholder("Type at least 2 name characters…");
  await search.fill("Sam");
  await expect(
    page.getByRole("option", { name: "Sam Employee · 3" }),
  ).toBeVisible();
  await search.press("ArrowDown");
  await search.press("Enter");
  await page
    .getByRole("button", { name: "Optional details", exact: true })
    .click();
  await page
    .getByLabel("Instructions (optional)")
    .fill("Arrange access before arrival");
  await page
    .getByLabel("Intended from (optional, with timezone offset)")
    .fill("2026-11-24T09:00:00+05:00");
  await page
    .getByLabel("Intended until (optional, with timezone offset)")
    .fill("2026-11-24T10:00:00+05:00");
  await page
    .getByLabel("Claimed occurrence (optional, with timezone offset)")
    .fill("2026-11-24T08:00:00+05:00");
  await page.getByLabel("Occurrence precision (optional)").fill("HOUR");
  await confirm(page, "Assign responsibility");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const body = requests.find((r) => r.path.endsWith("/assign"))!.body!;
  expect(body.intended_from).toBe("2026-11-24T04:00:00.000Z");
  expect(body.intended_until).toBe("2026-11-24T05:00:00.000Z");
  expect(body.occurred_at).toBe("2026-11-24T03:00:00.000Z");
  expect(body.occurrence_precision).toBe("HOUR");
  expect(body.instructions).toBe("Arrange access before arrival");
});

test("revocation during initial context load refreshes effective access and reveals no profile", async ({
  page,
}) => {
  const { requests } = await mount(page, {
    duties: ["job.hold"],
    contextRevoked: true,
  });
  await page.getByRole("button", { name: "Review Job operation" }).click();
  await expect(
    page.getByRole("heading", { name: "You don’t have access" }).first(),
  ).toBeVisible();
  await expect(page.getByText("JOB-007", { exact: true })).toHaveCount(0);
  expect(requests.every((r) => r.path.endsWith("/operation-context"))).toBe(
    true,
  );
});

test("concurrent-change retry preserves the original transport and semantic identities", async ({
  page,
}) => {
  const { requests, applied } = await mount(page, {
    duties: ["job.hold"],
    concurrent: true,
  });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Wait");
  await confirm(page, "Place on hold");
  await page.getByRole("button", { name: "Retry original request" }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const writes = requests.filter((r) => r.method === "POST");
  expect(writes).toHaveLength(2);
  expect(writes[0]!.body).toEqual(writes[1]!.body);
  expect(applied.size).toBe(1);
});

test("changed closeout conditions invalidate READY without an optimistic close", async ({
  page,
}) => {
  const { requests } = await mount(page, {
    duties: ["job.close"],
    operation: "CLOSE",
    state: "COMPLETED",
    closeBlocked: true,
  });
  await review(page);
  await page.locator("#job-assessment-confirm").check();
  await page
    .getByRole("button", { name: "Assess closeout readiness", exact: true })
    .click();
  await expect(page.getByText("READY", { exact: true })).toBeVisible();
  await confirm(page, "Close Job");
  await expect(page.getByText("READY", { exact: true })).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Close Job", exact: true }),
  ).toBeDisabled();
  await expect(page.getByRole("checkbox")).not.toBeChecked();
  await expect(page.getByText(/Closeout conditions changed/)).toBeVisible();
  expect(requests.filter((r) => r.path.endsWith("/close"))).toHaveLength(1);
});

test("320px confirmation stays within the viewport and its submit control is reachable", async ({
  page,
}) => {
  await mount(page, { duties: ["job.hold"], width: 320 });
  await review(page);
  await page.getByLabel("Reason", { exact: true }).fill("Await site access");
  await page.locator("#job-command-confirm").check();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  const submit = page.getByRole("button", {
    name: "Place on hold",
    exact: true,
  });
  await submit.scrollIntoViewIfNeeded();
  const box = await submit.boundingBox();
  expect(box).not.toBeNull();
  expect(
    await submit.evaluate((element) => {
      const box = element.getBoundingClientRect();
      const hit = document.elementFromPoint(
        box.x + box.width / 2,
        box.y + box.height / 2,
      );
      return hit === element || element.contains(hit);
    }),
  ).toBe(true);
  await submit.press("Enter");
  await expect(page.getByRole("dialog")).toHaveCount(0);
});
