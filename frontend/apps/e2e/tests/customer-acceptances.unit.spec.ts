import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import type { CaFixture } from "./fixtures/customer-acceptances";
import {
  acceptDisabledReason,
  customerAcceptanceAccess,
} from "../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-access";
import {
  MISSING_TEXT,
  REFUSAL_MESSAGES,
  SCOPE_STATUS_VIEW,
  WARNING_TEXT,
  consentProblem,
  previewScope,
  readAcceptance,
  readOutcome,
  readPreview,
  refusalMessage,
  scopeStatusView,
  type CustomerAcceptanceGateway,
} from "../../../packages/core-ui/src/features/customer-acceptances/customer-acceptance-contract";
import { readIssuedTerms } from "../../../packages/core-ui/src/features/quotations/quotation-issue-contract";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";

// Customer acceptance (acceptance slice 2: the Accept panel, the review sheet, the acceptance and its readiness, and the quotation
// picker of Customer Messages) in a real browser: gate, phone width, light and dark, keyboard, the guarded writes.
const script = buildSync({
  entryPoints: [
    new URL("./fixtures/customer-acceptances.tsx", import.meta.url).pathname,
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

const READ = ["sales.customer-decision.view", "sales.quotation.view"];
const ACCEPT = [...READ, "sales.customer-decision.accept"];

async function mount(
  page: Page,
  fixture: CaFixture,
  dark = false,
  width = 390,
) {
  await page.setViewportSize({ width, height: 844 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Customer acceptance fixture</title><style>${css}</style></head><body><div id="root"></div></body></html>`,
  );
  await page.evaluate((f) => {
    window.__ca = f;
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
      .filter((el) => !el.closest("[data-radix-popper-content-wrapper]"))
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
const calls = (page: Page) => page.evaluate(() => window.__caCalls);

async function chooseMessage(page: Page) {
  await page.getByLabel("Scope 30").check();
  await page.getByLabel("Customer message", { exact: true }).click();
  await page.getByRole("option", { name: /PO-ALPHA-0417/ }).click();
}
async function toReview(page: Page) {
  await chooseMessage(page);
  await page.getByRole("button", { name: "Review acceptance" }).click();
  await expect(page.getByTestId("acceptance-terms")).toBeVisible();
}

test("every screen is denied in production whatever the person holds, and reading and accepting are separate", () => {
  const all = new Set(ACCEPT);
  expect(
    Object.values(customerAcceptanceAccess(all, false)).every((v) => v === false),
  ).toBe(true);
  expect(customerAcceptanceAccess(new Set(READ), true)).toEqual({
    read: true,
    accept: false,
  });
  expect(customerAcceptanceAccess(all, true)).toEqual({
    read: true,
    accept: true,
  });
  // The accept duty alone, or without the independent quotation read, offers nothing.
  expect(
    customerAcceptanceAccess(new Set(["sales.customer-decision.accept"]), true),
  ).toEqual({ read: false, accept: false });
  expect(
    customerAcceptanceAccess(
      new Set(["sales.customer-decision.view", "sales.customer-decision.accept"]),
      true,
    ),
  ).toEqual({ read: false, accept: false });
  expect(canAccessScreen("/customer-acceptances/5", all)).toBe(false);
  expect(canAccessScreen("/customer-acceptances/abc", all)).toBe(false);
  expect(acceptDisabledReason(new Set(READ))).toMatch(/separate permission/);
  expect(acceptDisabledReason(new Set())).toMatch(/see customer messages/);
  expect(acceptDisabledReason(all)).toBeNull();
});

test("responses are parsed to the safe shape and the wording never says Won or hides a refusal behind server text", () => {
  const preview = readPreview({
    intent: {
      revision_id: 1,
      component_id: 2,
      evidence_id: 3,
      issued_line_id: 4,
      expected_case_generation: 0,
      expected_scope_generation: 0,
      expected_choice_generation: null,
      unchanged_terms: true,
    },
    terms: {
      issued_line_id: 4,
      description: "Unit",
      quantity: "1.0000",
      uom_id: null,
      unit_price: "10.00",
      currency_id: 1,
      net: "10.00",
      tax: null,
      gross: null,
      cost: "5.00",
      margin: "0.5",
    },
    unchanged_terms_statement: "x",
    warnings: [],
    preview_token: "t",
    expires_in_seconds: 600,
  });
  expect(JSON.stringify(preview)).not.toMatch(/cost|margin/);
  expect(readOutcome({ acceptance_id: 5, case_id: 7, replayed: true }).replayed).toBe(true);
  expect(() => readAcceptance({ id: 1 })).toThrow();
  const issued = readIssuedTerms({
    revision_id: 1,
    family_id: 2,
    official_number: "Q-1",
    issued_at: "2026-11-01T00:00:00Z",
    prepared_by: 1,
    issued_by: 1,
    currency_id: 1,
    pricing_date: "2026-11-01",
    valid_through_local_date: "2026-11-30",
    expires_at: "2026-12-01T00:00:00Z",
    availability: "AVAILABLE",
    scope_acceptance: [
      { issued_line_id: 1, logical_scope_id: 2, status: "AVAILABLE", acceptance_id: 99 },
    ],
    totals: null,
  });
  expect(issued.scope_acceptance).toEqual([
    { issued_line_id: 1, logical_scope_id: 2, status: "AVAILABLE" },
  ]);
  for (const code of Object.keys(REFUSAL_MESSAGES))
    expect(
      refusalMessage(
        Object.assign(new Error("x"), {
          status: 409,
          body: { reason_code: code, message: "SECRET detail of another customer" },
        }),
      ),
    ).toBe(REFUSAL_MESSAGES[code]);
  expect(refusalMessage(new Error("offline"))).toBe(
    "That could not be completed. Try again.",
  );
  const everyWord = JSON.stringify([
    REFUSAL_MESSAGES,
    SCOPE_STATUS_VIEW,
    WARNING_TEXT,
    MISSING_TEXT,
  ]);
  expect(everyWord).not.toMatch(/\bwon\b/i);
  expect(scopeStatusView("CLAIMED").label).toBe("Claimed elsewhere");
});

test("consent that cannot support an acceptance is explained in words", () => {
  const obs = {
    id: 1,
    predecessor_id: null,
    is_current: true,
    source_kind: "PURCHASE_ORDER",
    external_reference: null,
    source_version: null,
    customer_representative_snapshot: "A. Buyer",
    authority_verification_basis: "CONFIRMED_BY_SELLER",
    occurrence_precision: "UNKNOWN",
    occurred_at: null,
    occurred_until: null,
    source_timezone: null,
    factual_description: "x",
    observation_state: "OBSERVED",
    recorded_by: 1,
    recorded_by_name: null,
    recorded_at: "2026-11-10T00:00:00Z",
  };
  expect(consentProblem(obs)).toBeNull();
  expect(consentProblem(undefined)).toMatch(/no observation/);
  expect(consentProblem({ ...obs, source_kind: "VERBAL" })).toMatch(/verbal/);
  expect(
    consentProblem({ ...obs, authority_verification_basis: "STATED_BY_CUSTOMER" }),
  ).toMatch(/not been verified/);
  expect(
    consentProblem({ ...obs, customer_representative_snapshot: " " }),
  ).toMatch(/name/);
});

test("the review asks as an ordinary scope first and as an alternative only when the server says so", async () => {
  const seen: (number | null)[] = [];
  const stale = Object.assign(new Error("stale"), {
    status: 409,
    body: { reason_code: "PREVIEW_TOKEN_STALE" },
  });
  const gateway = {
    preview: async ({ intent }: { intent: { expected_choice_generation: number | null } }) => {
      seen.push(intent.expected_choice_generation);
      if (intent.expected_choice_generation === null) throw stale;
      return { ok: true };
    },
  } as unknown as CustomerAcceptanceGateway;
  const target = {
    revisionId: 1,
    componentId: 2,
    evidenceId: 3,
    issuedLineId: 4,
    caseGeneration: 0,
  };
  await previewScope(gateway, target).catch(() => undefined);
  expect(seen).toEqual([null, 0]);
  // Any other refusal is not retried.
  const other = {
    preview: async () => {
      seen.push(-1);
      throw Object.assign(new Error("x"), {
        status: 422,
        body: { reason_code: "QUOTATION_NOT_AVAILABLE" },
      });
    },
  } as unknown as CustomerAcceptanceGateway;
  seen.length = 0;
  await expect(previewScope(other, target)).rejects.toBeDefined();
  expect(seen).toEqual([-1]);
});

test("the panel shows each scope's state, offers only what can be accepted, and walks scope, message, review, record, then the acceptance", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: ACCEPT });
  await expect(page.getByRole("heading", { name: "Accept" })).toBeVisible();
  await expect(page.getByText("Available", { exact: true })).toBeVisible();
  await expect(page.getByText("Claimed elsewhere")).toBeVisible();
  await expect(page.getByLabel("Scope 31")).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Review acceptance" }),
  ).toBeDisabled();
  await chooseMessage(page);
  await expect(page.getByText("Procurement, Alpha Traders")).toBeVisible();
  await page.getByRole("button", { name: "Review acceptance" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByTestId("acceptance-terms")).toContainText(
    "Supply and fit the rooftop unit",
  );
  await expect(dialog.getByTestId("acceptance-terms")).toContainText("1150.00");
  await expect(dialog.getByTestId("unchanged-statement")).toContainText(
    "exact issued terms, unchanged",
  );
  await expect(dialog.getByText(/No delivery of the official PDF/)).toBeVisible();
  await expect(dialog.getByText(/not a sales outcome/)).toBeVisible();
  const confirm = dialog.getByRole("button", { name: "Record acceptance" });
  await expect(confirm).toBeDisabled();
  await dialog.getByLabel("The customer accepts these terms unchanged.").check();
  await confirm.click();
  await expect(
    page.getByRole("heading", { name: "Customer acceptance 55" }),
  ).toBeVisible();
  await expect(page.getByTestId("acceptance-banner")).toContainText(
    "Acceptance recorded.",
  );
  const sent = await calls(page);
  expect(sent[0]).toBe("preview:12:101:g2:s0:c-");
  expect(sent[1]).toMatch(/^record:101:token-1:/);
  await expect(page.getByText("Not ready", { exact: true })).toBeVisible();
  await expect(
    page.getByText("Starting a service job from an acceptance is not available yet."),
  ).toBeVisible();
  await page.getByLabel("Handover path").click();
  await page.getByRole("option", { name: "Goods order" }).click();
  await expect(
    page.getByText("There is no supported way yet to hand a goods acceptance over"),
  ).toBeVisible();
  await expect(page.locator("body")).not.toContainText(/\bwon\b/i);
});

test("a person without the accept duty sees the states and why accepting is off", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: READ });
  await expect(page.getByText("Claimed elsewhere")).toBeVisible();
  await expect(page.getByTestId("accept-disabled")).toContainText(
    "separate permission",
  );
  await expect(page.getByLabel("Scope 30")).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Review acceptance" }),
  ).toBeDisabled();
  await expect(page.getByLabel("Customer message")).toHaveCount(0);
  // No decision read at all: the panel is not offered.
  await mount(page, { screen: "issued", permissions: ["sales.quotation.view"] });
  await expect(page.getByRole("heading", { name: "Accept" })).toHaveCount(0);
});

test("an offer that is not open cannot be accepted and says so", async ({
  page,
}) => {
  await mount(page, {
    screen: "issued",
    permissions: ACCEPT,
    availability: "EXPIRED",
    firstStatus: "UNAVAILABLE",
  });
  await expect(page.getByText("Not available", { exact: true }).first()).toBeVisible();
  await expect(page.getByTestId("accept-disabled")).toContainText(
    "this offer is not open",
  );
  await expect(page.getByLabel("Scope 30")).toBeDisabled();
});

test("a message that cannot carry consent, or is not linked, blocks the review with the reason", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: ACCEPT, verbal: true });
  await chooseMessage(page);
  await expect(page.getByRole("alert").filter({ hasText: "verbal" })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Review acceptance" }),
  ).toBeDisabled();
  await mount(page, { screen: "issued", permissions: ACCEPT, noLink: true });
  await chooseMessage(page);
  await expect(
    page.getByRole("alert").filter({ hasText: "not linked to this quotation" }),
  ).toBeVisible();
});

test("an alternative is reviewed with its choice generation and says the others close", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: ACCEPT, choice: true });
  await toReview(page);
  await expect(page.getByText(/one of several alternatives/)).toBeVisible();
  expect(await calls(page)).toEqual([
    "preview:12:101:g2:s0:c-",
    "preview:12:101:g2:s0:c0",
  ]);
});

test("after a lost connection the retry reuses the request key and records once", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: ACCEPT, flaky: true });
  await toReview(page);
  await page.getByLabel("The customer accepts these terms unchanged.").check();
  await page.getByRole("button", { name: "Record acceptance" }).click();
  await expect(
    page.getByText("That could not be completed. Try again."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(
    page.getByRole("heading", { name: "Customer acceptance 55" }),
  ).toBeVisible();
  const records = (await calls(page)).filter((c) => c.startsWith("record:"));
  expect(records).toHaveLength(2);
  expect(records[0]).toBe(records[1]);
});

test("a scope claimed meanwhile is refused in fixed words without naming anyone", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: ACCEPT, claimed: true });
  await toReview(page);
  await page.getByLabel("The customer accepts these terms unchanged.").check();
  await page.getByRole("button", { name: "Record acceptance" }).click();
  await expect(
    page.getByText("already covered by a recorded acceptance"),
  ).toBeVisible();
  await expect(page.locator("body")).not.toContainText("SECRET");
});

test("a stale review must be redone, and the new review starts unconfirmed", async ({
  page,
}) => {
  await mount(page, { screen: "issued", permissions: ACCEPT, staleReview: true });
  await toReview(page);
  await page.getByLabel("The customer accepts these terms unchanged.").check();
  await page.getByRole("button", { name: "Record acceptance" }).click();
  await expect(page.getByText("This review is out of date")).toBeVisible();
  await page.getByRole("button", { name: "Review again" }).click();
  const confirm = page.getByRole("button", { name: "Record acceptance" });
  await expect(confirm).toBeDisabled();
  await page.getByLabel("The customer accepts these terms unchanged.").check();
  await confirm.click();
  await expect(
    page.getByRole("heading", { name: "Customer acceptance 55" }),
  ).toBeVisible();
  const records = (await calls(page)).filter((c) => c.startsWith("record:"));
  expect(records[0]).not.toBe(records[1]);
});

test("an exact replay says nothing was recorded twice", async ({ page }) => {
  await mount(page, { screen: "issued", permissions: ACCEPT, replay: true });
  await toReview(page);
  await page.getByLabel("The customer accepts these terms unchanged.").check();
  await page.getByRole("button", { name: "Record acceptance" }).click();
  await expect(page.getByTestId("acceptance-banner")).toContainText(
    "nothing was recorded twice",
  );
});

test("the review can be completed with the keyboard alone", async ({ page }) => {
  await mount(page, { screen: "issued", permissions: ACCEPT });
  await page.getByLabel("Scope 30").focus();
  await page.keyboard.press("Space");
  await expect(page.getByLabel("Scope 30")).toBeChecked();
  await page.getByLabel("Customer message", { exact: true }).focus();
  await page.keyboard.press("Enter");
  await page.keyboard.press("Enter");
  await expect(page.getByText("Procurement, Alpha Traders")).toBeVisible();
  const review = page.getByRole("button", { name: "Review acceptance" });
  await review.focus();
  await page.keyboard.press("Enter");
  const box = page.getByLabel("The customer accepts these terms unchanged.");
  await box.focus();
  await page.keyboard.press("Space");
  await expect(box).toBeChecked();
  await page.getByRole("button", { name: "Record acceptance" }).focus();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("heading", { name: "Customer acceptance 55" }),
  ).toBeVisible();
});

test("the acceptance shows its alternatives, evidence link and a readiness that fails honestly once", async ({
  page,
}) => {
  await mount(page, {
    screen: "acceptance",
    permissions: READ,
    groupDetail: true,
    readinessFails: true,
  });
  await expect(page.getByTestId("acceptance-terms")).toContainText("1000.00");
  await expect(page.getByText(/other alternatives in the set/)).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Open the customer message" }),
  ).toBeVisible();
  await expect(page.getByTestId("acceptance-banner")).toHaveCount(0);
  await expect(page.getByRole("alert")).toBeVisible();
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByText("Not ready", { exact: true })).toBeVisible();
  await expect(
    page.getByText(/does not check the site, contacts, warehouse/),
  ).toBeVisible();
});

test("a person without the reads sees no acceptance at all", async ({ page }) => {
  await mount(page, {
    screen: "acceptance",
    permissions: ["sales.customer-decision.accept"],
  });
  await expect(page.getByText("You don’t have access")).toBeVisible();
  expect((await calls(page)).length).toBe(0);
});

for (const dark of [false, true])
  test(`the panel, review and acceptance are readable at 320px and 390px (${dark ? "dark" : "light"})`, async ({
    page,
  }) => {
    for (const width of [320, 390]) {
      await mount(page, { screen: "issued", permissions: ACCEPT }, dark, width);
      await expect(page.getByRole("heading", { name: "Accept" })).toBeVisible();
      await expectPhoneSafe(page);
      await chooseMessage(page);
      await expectPhoneSafe(page);
      await page.getByRole("button", { name: "Review acceptance" }).click();
      await expect(page.getByTestId("acceptance-terms")).toBeVisible();
      await expectPhoneSafe(page);
      await mount(page, { screen: "acceptance", permissions: READ }, dark, width);
      await expect(page.getByText("Not ready", { exact: true })).toBeVisible();
      await expectPhoneSafe(page);
    }
  });

test("the quotation picker links original scopes, removes them, and explains every state", async ({
  page,
}) => {
  await mount(page, {
    screen: "picker",
    permissions: ["sales.quotation.view"],
    pickerFails: true,
  });
  await expect(page.getByRole("alert")).toBeVisible();
  await page.getByRole("button", { name: "Try again" }).click();
  await page.getByLabel("Issued quotation").click();
  await page.getByRole("option", { name: /QUO-2026-0007/ }).click();
  await page.getByRole("button", { name: "Link scope 30 of QUO-2026-0007" }).click();
  await expect(page.getByRole("button", { name: /Linked scope 30/ })).toBeDisabled();
  await page.getByRole("button", { name: "Link scope 31 of QUO-2026-0007" }).click();
  await expect(page.getByRole("list", { name: "Linked scopes" })).toContainText(
    "QUO-2026-0007 scope 31",
  );
  await page.getByRole("button", { name: "Remove QUO-2026-0007 scope 30" }).click();
  expect(await page.evaluate(() => window.__caLinked)).toEqual([
    {
      intent_identity: "QUO-2026-0007 scope 31",
      family_id: 8,
      original_scope_anchor_id: 31,
    },
  ]);
  await expectPhoneSafe(page);
});

test("the picker without the quotation view duty says why and offers no lookup", async ({
  page,
}) => {
  await mount(page, { screen: "picker", permissions: [] });
  await expect(page.getByTestId("link-picker-denied")).toContainText(
    "permission to view quotations",
  );
  await expect(page.getByLabel("Issued quotation")).toHaveCount(0);
  await mount(page, {
    screen: "picker",
    permissions: ["sales.quotation.view"],
    noQuotations: true,
  });
  await expect(page.getByText("no issued quotation to link")).toBeVisible();
  await mount(page, {
    screen: "picker",
    permissions: ["sales.quotation.view"],
    hiddenScopes: true,
  });
  await page.getByLabel("Issued quotation").click();
  await page.getByRole("option", { name: /QUO-2026-0007/ }).click();
  await expect(page.getByText(/scopes of this quotation are not shown/)).toBeVisible();
});
