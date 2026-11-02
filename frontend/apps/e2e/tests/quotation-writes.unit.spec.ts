import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import {
  QUOTATION_VIEW,
  QUOTATION_PREPARE,
} from "../../../packages/core-ui/src/features/quotations/quotation-access";
import {
  QUOTATION_ISSUE,
  DOCUMENT_VIEW,
  DOCUMENT_GENERATE,
  DOCUMENT_DOWNLOAD,
  DOCUMENT_REPAIR,
  DELIVERY_VIEW,
  DELIVERY_RECORD,
} from "../../../packages/core-ui/src/features/quotations/quotation-milestone-access";
import { IntentKey } from "../../../packages/core-ui/src/features/quotations/request-key";
import type { Outcome, WritesFixture } from "./fixtures/quotation-writes";

// Mounted behaviour of the gated write screens, with a scripted gateway (no backend, no network): what is
// sent, under which Idempotency-Key and expected_generation, after success, uncertain failure and refusal.
const fixtureScript = buildSync({
  entryPoints: [
    new URL("./fixtures/quotation-writes.ts", import.meta.url).pathname,
  ],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;

const EVERY_DUTY = [
  QUOTATION_VIEW,
  QUOTATION_PREPARE,
  QUOTATION_ISSUE,
  DOCUMENT_VIEW,
  DOCUMENT_GENERATE,
  DOCUMENT_DOWNLOAD,
  DOCUMENT_REPAIR,
  DELIVERY_VIEW,
  DELIVERY_RECORD,
];

type Call = { op: string; args: Record<string, unknown> };

async function mount(
  page: Page,
  screen: WritesFixture["screen"],
  script: Record<string, Outcome[]> = {},
  options: { dev?: boolean; permissions?: string[] } = {},
) {
  await page.setContent(
    '<!doctype html><html lang="en"><head><title>Quotation writes fixture</title></head><body><div id="root"></div></body></html>',
  );
  const fixture: WritesFixture = {
    screen,
    dev: options.dev ?? true,
    permissions: options.permissions ?? EVERY_DUTY,
    script,
  };
  await page.evaluate((f) => {
    window.__fixture = f;
  }, fixture);
  await page.addScriptTag({ content: fixtureScript });
}
const calls = (page: Page, op?: string) =>
  page.evaluate(
    (name) => (window.__calls as Call[]).filter((c) => !name || c.op === name),
    op,
  ) as Promise<Call[]>;
const key = (c: Call) => c.args.requestKey as string;

async function confirmIssue(page: Page) {
  await page.getByRole("button", { name: "Issue quotation" }).click();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Issue quotation" })
    .click();
}

test("IntentKey reuses a key only for the same intent after an uncertain failure", () => {
  let n = 0;
  const keys = new IntentKey(() => `k${++n}`);
  const http = (status: number) =>
    Object.assign(new Error(String(status)), { status });
  expect(keys.for({ g: 4 })).toBe("k1");
  keys.settle(new TypeError("Failed to fetch")); // network: uncertain
  expect(keys.for({ g: 4 })).toBe("k1");
  keys.settle(http(503));
  expect(keys.for({ g: 4 })).toBe("k1");
  keys.settle(http(429)); // rate limited: may not have run, a replay is still safe
  expect(keys.for({ g: 4 })).toBe("k1");
  expect(keys.for({ g: 5 })).toBe("k2"); // a different intent never reuses a key
  keys.settle(http(422)); // definitive refusal ends the attempt
  expect(keys.for({ g: 5 })).toBe("k3");
  keys.settle(); // success ends it too
  expect(keys.for({ g: 5 })).toBe("k4");
});

test("a production build mounts nothing and calls no gateway, even holding every duty", async ({
  page,
}) => {
  for (const screen of ["issue", "document"] as const) {
    await mount(page, screen, {}, { dev: false });
    await expect(page.getByText("You don’t have access")).toBeVisible();
    expect(await calls(page)).toEqual([]);
  }
});

test("Issue sends the reviewed generation and replays the same key after an uncertain failure", async ({
  page,
}) => {
  await mount(page, "issue", {
    readiness: [
      {
        ok: {
          revision_id: 12,
          input_generation: 4,
          issue_ready: true,
          blockers: [],
        },
      },
    ],
    issue: [{ network: true }, { status: 503 }],
  });
  await confirmIssue(page);
  await expect(page.getByRole("alert")).toContainText("could not be issued");
  // Same confirmation retried twice more: the outcome was uncertain, so the same key is replayed.
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Issue quotation" })
    .click();
  await expect(page.getByRole("alert")).toContainText("could not be issued");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Issue quotation" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Quotation issued" }),
  ).toBeVisible();
  await expect(page.getByText("QUO-2026-0007")).toBeVisible();
  const issues = await calls(page, "issue");
  expect(issues).toHaveLength(3);
  expect(issues.map((c) => c.args.expectedGeneration)).toEqual([4, 4, 4]);
  expect(new Set(issues.map(key)).size).toBe(1);
  expect(key(issues[0]!)).toMatch(/^[\w-]{8,}$/);
  // Issued: readiness is not asked again; the real issued record is read once (404 here, so the confirmed outcome stays).
  expect((await calls(page)).map((c) => c.op)).toEqual([
    "readiness",
    "issue",
    "issue",
    "issue",
    "issued",
  ]);
});

test("a definitive refusal ends the attempt; a newer generation is a new key", async ({
  page,
}) => {
  await mount(page, "issue", {
    readiness: [
      {
        ok: {
          revision_id: 12,
          input_generation: 4,
          issue_ready: true,
          blockers: [],
        },
      },
      {
        ok: {
          revision_id: 12,
          input_generation: 5,
          issue_ready: true,
          blockers: [],
        },
      },
    ],
    issue: [{ status: 422, reason_code: "QUOTATION_DRAFT_STALE" }],
  });
  await confirmIssue(page);
  await expect(page.getByRole("alert")).toContainText(
    "The draft changed since you reviewed it",
  );
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: "Check again" }).click();
  await expect(page.getByText("5", { exact: true })).toBeVisible();
  await confirmIssue(page);
  await expect(
    page.getByRole("heading", { name: "Quotation issued" }),
  ).toBeVisible();
  const [first, second] = await calls(page, "issue");
  expect([
    first!.args.expectedGeneration,
    second!.args.expectedGeneration,
  ]).toEqual([4, 5]);
  expect(key(second!)).not.toBe(key(first!));
});

test("after an uncertain failure, a changed draft generation is never sent under the old key", async ({
  page,
}) => {
  await mount(page, "issue", {
    readiness: [
      {
        ok: {
          revision_id: 12,
          input_generation: 4,
          issue_ready: true,
          blockers: [],
        },
      },
      {
        ok: {
          revision_id: 12,
          input_generation: 6,
          issue_ready: true,
          blockers: [],
        },
      },
    ],
    issue: [{ network: true }],
  });
  await confirmIssue(page);
  await expect(page.getByRole("alert")).toContainText("could not be issued");
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Cancel" })
    .click();
  await page.getByRole("button", { name: "Check again" }).click();
  await expect(page.getByText("6", { exact: true })).toBeVisible();
  await confirmIssue(page);
  const [first, second] = await calls(page, "issue");
  expect([
    first!.args.expectedGeneration,
    second!.args.expectedGeneration,
  ]).toEqual([4, 6]);
  expect(key(second!)).not.toBe(key(first!));
});

test("a revision that is no longer a draft is not presented as issued", async ({
  page,
}) => {
  await mount(page, "issue", {
    readiness: [{ status: 422, reason_code: "QUOTATION_NOT_EDITABLE" }],
  });
  await expect(
    page.getByText("This quotation is no longer a draft"),
  ).toBeVisible();
  await expect(page.getByText("Issued", { exact: true })).toHaveCount(0);
  // The issued-record read answered 404 (a cancelled draft): nothing claims it was issued.
  expect((await calls(page)).map((c) => c.op)).toEqual(["readiness", "issued"]);
});

test("an issued revision shows its real issued record; totals only when the server sends them", async ({
  page,
}) => {
  const record = {
    revision_id: 12,
    family_id: 8,
    official_number: "QUO-2026-0042",
    issued_at: "2026-10-30T10:15:00Z",
    prepared_by: 5,
    issued_by: 9,
    currency_id: 3,
    pricing_date: "2026-10-27",
    valid_through_local_date: "2026-11-30",
    expires_at: "2026-12-01T00:00:00Z",
    totals: null,
    controls_outcome: "SECRET_CONTROL",
  };
  await mount(page, "issue", {
    readiness: [{ status: 422, reason_code: "QUOTATION_NOT_EDITABLE" }],
    issued: [{ ok: record }],
  });
  await expect(page.getByText("QUO-2026-0042")).toBeVisible();
  await expect(
    page.getByText("Totals are shown to people who prepare or issue"),
  ).toBeVisible();
  await expect(page.getByText("SECRET_CONTROL")).toHaveCount(0);
  expect((await calls(page)).map((c) => c.op)).toEqual(["readiness", "issued"]);
});

const pending = {
  ok: { revision_id: 12, state: "PENDING", error_code: null, artefact: null },
};

test("Generate replays its key after an uncertain failure and uses a new key for a new request", async ({
  page,
}) => {
  await mount(page, "document", {
    status: [pending, pending, pending, pending],
    generate: [{ status: 503 }, { ok: pending.ok }, { ok: pending.ok }],
  });
  const generate = page.getByRole("button", { name: "Generate document" });
  await generate.click();
  await expect(page.getByRole("alert")).toContainText("could not be completed");
  await generate.click(); // retry of the uncertain attempt
  await expect(page.getByRole("alert")).toHaveCount(0);
  await generate.click(); // a new request after a settled one
  await expect.poll(async () => (await calls(page, "generate")).length).toBe(3);
  const [a, b, c] = (await calls(page, "generate")).map(key);
  expect(b).toBe(a);
  expect(c).not.toBe(a);
  // Each settled generate refreshes the status (initial load + two successes).
  await expect.poll(async () => (await calls(page, "status")).length).toBe(3);
});

test("Download and Print hand over the gateway's PDF bytes only; anything else is refused", async ({
  page,
}) => {
  await mount(page, "document", {
    download: [
      { ok: { __pdf: "1" } },
      { ok: { __pdf: "1" } },
      { ok: { __blob: "text/html" } },
    ],
  });
  const saved = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download PDF" }).click();
  expect((await saved).suggestedFilename()).toBe("quotation-12.pdf");
  const printed = page.waitForEvent("download");
  await page.getByRole("button", { name: "Print PDF" }).click();
  await printed;
  await page.getByRole("button", { name: "Download PDF" }).click();
  await expect(page.getByRole("alert")).toContainText("could not be completed");
  expect((await calls(page, "download")).map((c) => c.args)).toEqual([
    { revisionId: 12, intent: "download" },
    { revisionId: 12, intent: "print" },
    { revisionId: 12, intent: "download" },
  ]);
  // No link was built or shown: the only URLs on the page are none.
  await expect(page.locator("a[href]")).toHaveCount(0);
});

test("Record delivery: retry replays, edited details get a new key, success clears the form", async ({
  page,
}) => {
  await mount(page, "document", {
    recordDelivery: [{ network: true }, { network: true }],
  });
  await page.getByLabel("Delivered on").fill("2026-10-31");
  await page.getByLabel("Received by").fill("Mr Customer");
  const submit = page.getByRole("button", { name: "Record delivery" });
  await submit.click();
  await expect(page.getByRole("alert")).toContainText("could not be completed");
  await submit.click(); // unchanged details: the same uncertain request, replayed
  await expect(page.getByRole("alert")).toContainText("could not be completed");
  await page.getByLabel("Received by").fill("Ms Customer"); // edited: a different record
  await submit.click();
  await expect(page.getByLabel("Received by")).toHaveValue("");
  const records = await calls(page, "recordDelivery");
  expect(
    records.map(
      (c) => (c.args.body as { recipient_name: string }).recipient_name,
    ),
  ).toEqual(["Mr Customer", "Mr Customer", "Ms Customer"]);
  expect(key(records[1]!)).toBe(key(records[0]!));
  expect(key(records[2]!)).not.toBe(key(records[0]!));
  // The same details entered again after success are a new record with a new key.
  await page.getByLabel("Delivered on").fill("2026-10-31");
  await page.getByLabel("Received by").fill("Ms Customer");
  await submit.click();
  await expect
    .poll(async () => (await calls(page, "recordDelivery")).length)
    .toBe(4);
  const fourth = (await calls(page, "recordDelivery"))[3]!;
  expect(key(fourth)).not.toBe(key(records[2]!));
  expect((await calls(page, "deliveries")).length).toBeGreaterThanOrEqual(2);
});

test("the list, issue and document routes are denied in production even holding every duty, and open only in the dev build", async () => {
  const { canAccessScreen } =
    await import("../../../packages/core-ui/src/routes/screen-access");
  const { configureQuotationDevMode } =
    await import("../../../packages/core-ui/src/lib/quotation-dev-mode");
  const all = new Set(EVERY_DUTY);
  const paths = [
    "/quotations/all",
    "/quotations/12/issue",
    "/quotations/12/document",
  ];
  configureQuotationDevMode({
    development: false,
    flag: "1",
    hostname: "localhost",
  });
  for (const path of paths) expect(canAccessScreen(path, all)).toBe(false);
  configureQuotationDevMode({
    development: true,
    flag: "1",
    hostname: "localhost",
  });
  try {
    for (const path of paths) expect(canAccessScreen(path, all)).toBe(true);
    expect(canAccessScreen("/quotations/12/issue", new Set())).toBe(false);
    expect(canAccessScreen("/quotations/abc/issue", all)).toBe(false);
  } finally {
    configureQuotationDevMode({
      development: false,
      flag: undefined,
      hostname: "localhost",
    });
  }
});
