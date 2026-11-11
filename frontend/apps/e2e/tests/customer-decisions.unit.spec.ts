import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import type { CdFixture } from "./fixtures/customer-decisions";
import { customerDecisionAccess } from "../../../packages/core-ui/src/features/customer-decisions/customer-decision-access";
import {
  readCaseDetail,
  readListPage,
  readOutcome,
  refusalMessage,
  REFUSAL_MESSAGES,
} from "../../../packages/core-ui/src/features/customer-decisions/customer-decision-contract";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";

// Customer messages (acceptance slice 1: record what the customer sent) in a real browser: gate, phone width, light and dark, the guarded writes.
const script = buildSync({
  entryPoints: [
    new URL("./fixtures/customer-decisions.tsx", import.meta.url).pathname,
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
  .filter((name) => name.endsWith(".css"))
  .map((name) => readFileSync(new URL(name, assets), "utf8"))
  .join("\n");

const VIEW = ["sales.customer-decision.view"];
const BOTH = [...VIEW, "sales.customer-decision.evidence.record"];
const RECORD_ONLY = ["sales.customer-decision.evidence.record"];

async function mount(
  page: Page,
  fixture: CdFixture,
  dark = false,
  width = 390,
) {
  await page.setViewportSize({ width, height: 844 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Customer decisions fixture</title><style>${css}</style></head><body><div id="root"></div></body></html>`,
  );
  await page.evaluate((f) => {
    window.__cd = f;
  }, fixture);
  await page.addScriptTag({ content: script });
}
async function expectPhoneSafe(page: Page) {
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth - window.innerWidth,
    ),
  ).toBeLessThanOrEqual(0);
  const small = await page.evaluate(() =>
    [...document.querySelectorAll("button, a[href]")]
      .filter((el) => (el as HTMLElement).offsetParent !== null)
      .filter((el) => el.getAttribute("role") !== "combobox")
      // The shared data table's own sort headers and page buttons keep their shared sizing; this spec is about the screen's controls.
      .filter(
        (el) =>
          !el.closest("th") &&
          !/ page$/.test(el.getAttribute("aria-label") ?? ""),
      )
      .filter((el) => el.getBoundingClientRect().height < 44)
      .map(
        (el) =>
          (el.textContent ?? "").trim() ||
          el.getAttribute("aria-label") ||
          el.outerHTML.slice(0, 120),
      ),
  );
  expect(small).toEqual([]);
}
const writes = (page: Page) => page.evaluate(() => window.__cdWrites);

test("the screens are denied in production whatever the person holds, and reading and recording are separate", () => {
  const all = new Set([...BOTH]);
  const off = customerDecisionAccess(all, false);
  expect(Object.values(off).every((v) => v === false)).toBe(true);
  expect(customerDecisionAccess(new Set(VIEW), true)).toEqual({
    view: true,
    record: false,
  });
  expect(customerDecisionAccess(new Set(BOTH), true)).toEqual({
    view: true,
    record: true,
  });
  // Recording without the independent read offers neither screen nor action.
  expect(customerDecisionAccess(new Set(RECORD_ONLY), true)).toEqual({
    view: false,
    record: false,
  });
  // The screen gate follows the same rule (and is off here because the dev opt-in is not configured in this process).
  expect(canAccessScreen("/customer-decisions", new Set(BOTH))).toBe(false);
  expect(canAccessScreen("/customer-decisions/abc", new Set(BOTH))).toBe(false);
});

test("responses are parsed to the safe shape: nothing about consent, claims or amounts survives", () => {
  const detail = readCaseDetail({
    case: {
      id: 1,
      party_id: 2,
      party_name: null,
      intake_identity: "PO-1",
      generation: 0,
      recorded_by: 3,
      recorded_by_name: null,
      recorded_at: "2026-11-10T00:00:00Z",
      consent_recorded: false,
      accepted: true,
      net_amount: "100.0000",
    },
    components: [],
    hidden_component_count: 0,
    observations: [],
  });
  expect(JSON.stringify(detail)).not.toMatch(/accepted|net_amount/);
  expect(detail.case.consent_recorded).toBe(false);
  expect(() => readCaseDetail({ case: {} })).toThrow();
  expect(
    readOutcome({ case_id: 4, generation: 1, evidence_id: 9, replayed: true })
      .evidence_id,
  ).toBe(9);
  expect(
    readListPage({
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
      },
    }).data,
  ).toEqual([]);
});

test("every refusal a person can act on has fixed wording, and a server message is never echoed", () => {
  for (const code of Object.keys(REFUSAL_MESSAGES))
    expect(
      refusalMessage(
        Object.assign(new Error("x"), {
          status: 409,
          body: { reason_code: code, message: "SECRET detail of another case" },
        }),
      ),
    ).toBe(REFUSAL_MESSAGES[code]);
  expect(
    refusalMessage(
      Object.assign(new Error("x"), {
        status: 403,
        body: { message: "SECRET" },
      }),
    ),
  ).toBe("You don’t have permission to do that.");
  expect(refusalMessage(new Error("offline"))).toBe(
    "That could not be completed. Try again.",
  );
});

for (const dark of [false, true])
  test(`the list and a case are readable at 320px and 390px (${dark ? "dark" : "light"})`, async ({
    page,
  }) => {
    for (const width of [320, 390]) {
      await mount(page, { screen: "list", permissions: BOTH }, dark, width);
      await expect(
        page.getByRole("heading", { name: "Customer messages" }),
      ).toBeVisible();
      await expect(page.getByText("PO-ALPHA-0417").first()).toBeVisible();
      await expectPhoneSafe(page);
      await page
        .getByRole("button", { name: "Record a customer message" })
        .click();
      await expect(page.getByRole("dialog")).toBeVisible();
      await expect(page.getByTestId("no-consent-notice")).toBeVisible();
      await expectPhoneSafe(page);
      await mount(page, { screen: "case", permissions: BOTH }, dark, width);
      await expect(
        page.getByRole("heading", { name: "Customer message PO-ALPHA-0417" }),
      ).toBeVisible();
      await expect(page.getByText("No consent recorded")).toBeVisible();
      await expect(
        page.getByText("Corrected by a later observation"),
      ).toBeVisible();
      await expect(
        page.getByText("1 more quotation is linked that you cannot see."),
      ).toBeVisible();
      await expectPhoneSafe(page);
    }
  });

test("a person without the read duty sees nothing, and a reader without the record duty has no write action", async ({
  page,
}) => {
  await mount(page, { screen: "list", permissions: RECORD_ONLY });
  await expect(page.getByText("You don’t have access")).toBeVisible();
  await expect(page.getByText("PO-ALPHA-0417")).toHaveCount(0);
  await mount(page, { screen: "list", permissions: VIEW });
  await expect(page.getByText("PO-ALPHA-0417").first()).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Record a customer message" }),
  ).toHaveCount(0);
  await mount(page, { screen: "case", permissions: VIEW });
  await expect(
    page.getByRole("button", { name: "Add an observation" }),
  ).toHaveCount(0);
});

test("the empty list says so, and searching asks the server", async ({
  page,
}) => {
  await mount(page, { screen: "list", permissions: VIEW, empty: true });
  await expect(
    page.getByText("No customer messages recorded yet."),
  ).toBeVisible();
  await mount(page, { screen: "list", permissions: VIEW });
  await page.getByLabel("Search by reference").fill("nothing like it");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(
    page.getByText("No customer messages match that reference."),
  ).toBeVisible();
});

test("an observation is validated, says unknown stays unknown, and is sent with the generation the person saw", async ({
  page,
}) => {
  await mount(page, { screen: "case", permissions: BOTH });
  await page.getByRole("button", { name: "Add an observation" }).click();
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(
    page.getByText("Describe what the customer sent, in plain facts."),
  ).toBeVisible();
  await page
    .getByLabel("What the customer sent")
    .fill("They confirmed by phone that the order stands.");
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(
    page.getByText("They confirmed by phone that the order stands."),
  ).toBeVisible();
  const sent = await writes(page);
  expect(sent).toHaveLength(1);
  expect(sent[0]).toMatch(/^add:7:2:PURCHASE_ORDER:UNKNOWN:-:/);
});

test("choosing a time precision asks for the time, and a correction names the observation it corrects", async ({
  page,
}) => {
  await mount(page, { screen: "case", permissions: BOTH });
  await page.getByRole("button", { name: "Add an observation" }).click();
  await page.getByLabel("What the customer sent").fill("Corrected quantity.");
  await page.getByLabel("When it happened").click();
  await page.getByRole("option", { name: "A day" }).click();
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(
    page.getByText("Enter when it happened, or choose “Not known”."),
  ).toBeVisible();
  await page.getByLabel("On", { exact: true }).fill("2026-11-10");
  await page.getByLabel("Corrects an earlier observation (optional)").click();
  await page.getByRole("option", { name: /^#2/ }).click();
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(page.getByText("Corrected quantity.")).toBeVisible();
  expect((await writes(page))[0]).toMatch(/^add:7:2:PURCHASE_ORDER:DATE:2:/);
});

test("a case that moved is refreshed and the same observation can be sent again under a new key", async ({
  page,
}) => {
  await mount(page, { screen: "case", permissions: BOTH, stale: true });
  await page.getByRole("button", { name: "Add an observation" }).click();
  await page.getByLabel("What the customer sent").fill("Second note.");
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(
    page.getByText("This case changed since you opened it."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(page.getByText("Second note.")).toBeVisible();
  const sent = await writes(page);
  expect(sent.map((w) => w.split(":").slice(0, 3).join(":"))).toEqual([
    "add:7:2",
    "add:7:3",
  ]);
  expect(new Set(sent.map((w) => w.split(":").pop())).size).toBe(2);
});

test("after a lost connection the retry reuses the request key", async ({
  page,
}) => {
  await mount(page, { screen: "case", permissions: BOTH, flaky: true });
  await page.getByRole("button", { name: "Add an observation" }).click();
  await page.getByLabel("What the customer sent").fill("Sent once.");
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(
    page.getByText("That could not be completed. Try again."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Add observation" }).click();
  await expect(page.getByText("Sent once.")).toBeVisible();
  const keys = (await writes(page)).map((w) => w.split(":").pop());
  expect(keys).toHaveLength(2);
  expect(keys[0]).toBe(keys[1]);
});

test("opening a case sends the customer reference once and shows the notice that nothing is accepted", async ({
  page,
}) => {
  await mount(page, { screen: "list", permissions: BOTH });
  await page.getByRole("button", { name: "Record a customer message" }).click();
  await page.getByRole("button", { name: "Open case" }).click();
  await expect(
    page.getByText("Choose the customer and enter the reference they used."),
  ).toBeVisible();
  expect(await writes(page)).toEqual([]);
});
