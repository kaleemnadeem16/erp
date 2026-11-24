import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import type { HrFixture } from "./fixtures/handover-release";
import { handoverReleaseAccess } from "../../../packages/core-ui/src/features/handover-release/handover-release-access";
import {
  BLOCKER_TEXT,
  REFUSAL_MESSAGES,
  alreadyReleased,
  blockerText,
  evidenceProblem,
  needsFreshReview,
  readBasisOutcome,
  readConditions,
  readHandoverStatus,
  readPlan,
  readReleaseOutcome,
  readReleasePreview,
  refusalMessage,
} from "../../../packages/core-ui/src/features/handover-release/handover-release-contract";
import { canAccessScreen } from "../../../packages/core-ui/src/routes/screen-access";

// The Sales handover and release screen (#130 API) in a real browser: gate, phone width, light and dark, keyboard, accessibility and
// every state: loading, blocked, ready, released, replay, already released, refusals. The server stays authoritative: the fixture's
// gateway only scripts what the three Sales endpoints answer.
const script = buildSync({
  entryPoints: [new URL("./fixtures/handover-release.tsx", import.meta.url).pathname],
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
const ALL = [...READ, "sales.handover.completion-basis.record", "sales.handover.release"];

async function mount(page: Page, fixture: HrFixture, dark = false, width = 390) {
  await page.setViewportSize({ width, height: 844 });
  await page.setContent(
    `<!doctype html><html lang="en" class="${dark ? "dark" : ""}"><head><title>Handover fixture</title><style>${css}</style></head><body><main id="root"></main></body></html>`,
  );
  await page.evaluate((f) => {
    window.__hr = f;
  }, fixture);
  await page.addScriptTag({ content: script });
}
async function expectPhoneSafe(page: Page) {
  const narrow = (await page.evaluate(() => window.innerWidth)) < 768;
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth),
  ).toBeLessThanOrEqual(0);
  const small = await page.evaluate(() =>
    [...document.querySelectorAll("button, a[href]")]
      .filter((el) => (el as HTMLElement).offsetParent !== null)
      .filter((el) => !el.closest("[data-radix-popper-content-wrapper]"))
      .filter((el) => el.getBoundingClientRect().height < 44)
      .map((el) => (el.textContent ?? "").trim() || el.outerHTML.slice(0, 100)),
  );
  expect(narrow ? small : []).toEqual([]);
}
async function expectNoA11yViolations(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
}
const apiError = (status: number, reason?: string) =>
  Object.assign(new Error("refused"), { status, body: reason ? { reason_code: reason } : {} });
const calls = (page: Page) => page.evaluate(() => window.__hrCalls);

test("duties are exact and default-deny, production denies everyone, and the route is gated", () => {
  const all = new Set(ALL);
  expect(Object.values(handoverReleaseAccess(all, false)).every((v) => v === false)).toBe(true);
  expect(handoverReleaseAccess(new Set(READ), true)).toEqual({ read: true, review: false, recordBasis: false, release: false });
  expect(handoverReleaseAccess(all, true)).toEqual({ read: true, review: true, recordBasis: true, release: true });
  // Sales duties alone, without the independent reads, offer nothing.
  expect(handoverReleaseAccess(new Set(["sales.handover.release", "sales.handover.completion-basis.record"]), true).read).toBe(false);
  expect(handoverReleaseAccess(new Set(["sales.customer-decision.view", "sales.handover.release"]), true).release).toBe(false);
  expect(canAccessScreen("/handover-plans/5", all)).toBe(false); // production gate off in this test environment
  expect(canAccessScreen("/handover-plans/abc", all)).toBe(false);
});

test("responses are parsed to the safe shape; wording is plain, covers every blocker and never invents one", () => {
  const p = readReleasePreview({
    ready: false,
    blockers: ["COMPLETION_BASIS_MISSING"],
    next_actions: [{ fact: "X", owner: "Sales", action: "Do it", extra: "x" }],
    cost: { required: false, status: "UNASSESSED", policy_version: "secret" },
    applicability: { status: "UNKNOWN", materials: "UNSTATED", subcontract: "UNSTATED", pin: "secret" },
    basis_id: null,
    basis_version: null,
    fingerprint: "abc",
  });
  expect(JSON.stringify(p)).not.toMatch(/secret|extra/);
  expect(() => readReleasePreview({ ready: "yes" })).toThrow();
  expect(readBasisOutcome({ basis_id: 3, version: 1, replayed: false, case_id: 9 })).toEqual({ basis_id: 3, version: 1, replayed: false });
  expect(readReleaseOutcome({ release_event_id: 1, job_id: 2, job_code: "JOB-00001", replayed: true, cost: "1" })).toEqual({ release_event_id: 1, job_id: 2, job_code: "JOB-00001", replayed: true });
  for (const code of Object.keys(BLOCKER_TEXT)) expect(blockerText(code)).toBe(BLOCKER_TEXT[code]);
  expect(blockerText("SOMETHING_NEW")).toMatch(/not met yet/);
  expect(refusalMessage(apiError(409, "RELEASE_ALREADY_RECORDED"))).toBe(REFUSAL_MESSAGES.RELEASE_ALREADY_RECORDED);
  expect(refusalMessage(apiError(403))).toMatch(/receive jobs/);
  expect(needsFreshReview(apiError(409, "PREVIEW_TOKEN_STALE"))).toBe(true);
  expect(alreadyReleased(apiError(409, "RELEASE_ALREADY_RECORDED"))).toBe(true);
});

test("a customer message can be chosen only when it is written, current and from a verified sender", () => {
  const base = { is_current: true, source_kind: "EMAIL", authority_verification_basis: "DOCUMENT_ON_FILE" } as never;
  expect(evidenceProblem(base)).toBeNull();
  expect(evidenceProblem({ ...(base as object), source_kind: "VERBAL" } as never)).toMatch(/Verbal/);
  expect(evidenceProblem({ ...(base as object), authority_verification_basis: "UNVERIFIED" } as never)).toMatch(/authority/);
  expect(evidenceProblem({ ...(base as object), is_current: false } as never)).toMatch(/corrected/);
});

test("a person without the read duties is denied and issues no request", async ({ page }) => {
  await mount(page, { permissions: ["sales.handover.release"] });
  await expect(page.getByText(/permission/i).first()).toBeVisible();
  expect(await calls(page)).toEqual([]);
});

test("loading, then a blocked service with plain blockers and next actions, a locked release button and no scope checkbox", async ({ page }) => {
  await mount(page, { permissions: ALL, noBasis: true });
  await expect(page.getByRole("heading", { name: "Prepared handover" })).toBeVisible();
  await expect(page.getByText("Main Works, 1 Mill Road, Lahore")).toBeVisible();
  await expect(page.getByTestId("no-basis")).toBeVisible();
  const blockers = page.getByTestId("blockers");
  await expect(blockers).toContainText("completion conditions have not been recorded");
  await expect(page.getByTestId("next-actions")).toContainText("Sales:");
  await expect(page.getByRole("button", { name: "Release to a Job" })).toBeDisabled();
  expect(await page.locator('input[type="checkbox"]').count()).toBe(0);
  await expect(page.getByText(/cannot be stated or ticked here/)).toBeVisible();
  await expectPhoneSafe(page);
  await expectNoA11yViolations(page);
});

test("recording the issued description cites the acceptance evidence with no text, then the review is refreshed", async ({ page }) => {
  await mount(page, { permissions: ALL, noBasis: true });
  await page.getByRole("button", { name: "Record these conditions" }).click();
  await expect(page.getByTestId("basis-recorded")).toContainText("version 1");
  expect((await calls(page)).filter((c) => c.startsWith("basis:"))).toEqual(["basis:55:1:null:key"]);
  await expect(page.getByTestId("blockers")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Release to a Job" })).toBeEnabled();
});

test("choosing a later written message sends its exact text; verbal and consent messages cannot be chosen", async ({ page }) => {
  await mount(page, { permissions: ALL, noBasis: true, unproven: true });
  await page.getByLabel("A later written customer message.").check();
  await expect(page.getByText("Verbal messages cannot be used")).toBeVisible();
  await expect(page.getByLabel(/Phoned to add a site visit/)).toBeDisabled();
  await expect(page.getByLabel(/The buyer agrees to the full issued service/)).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Record these conditions" })).toBeDisabled();
  await page.getByLabel(/MATERIALS: EXCLUDED/).check();
  await page.getByRole("button", { name: "Record these conditions" }).click();
  await expect(page.getByTestId("basis-recorded")).toBeVisible();
  const sent = (await calls(page)).find((c) => c.startsWith("basis:"))!;
  expect(sent).toContain(":2:");
  expect(sent).toContain(JSON.stringify("MATERIALS: EXCLUDED\nSUBCONTRACTORS: EXCLUDED\nLINE: 101\nDELIVERABLE_SHA256: " + "a".repeat(64)));
  // Unproven applicability still blocks and is explained, never waved through.
  await expect(page.getByTestId("blockers")).toContainText("not yet proven");
  await expect(page.getByRole("button", { name: "Release to a Job" })).toBeDisabled();
});

test("with no other written message the empty state says what to do", async ({ page }) => {
  await mount(page, { permissions: ALL, noBasis: true, noMessages: true });
  await page.getByLabel("A later written customer message.").check();
  await expect(page.getByTestId("no-messages")).toContainText("no other written message");
});

test("a ready service is released after one confirmation: Job number, link to the Job, one request", async ({ page }) => {
  await mount(page, { permissions: ALL });
  await expect(page.getByText("Ready to release")).toBeVisible();
  await expect(page.getByText("Version 1")).toBeVisible();
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await expect(page.getByRole("dialog")).toContainText("cannot be undone");
  await page.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByTestId("job-code")).toHaveText("JOB-00007");
  await expect(page.getByText("The service was released and one Job was created.")).toBeVisible();
  expect((await calls(page)).filter((c) => c.startsWith("release:"))).toEqual(["release:9:70:fp-1-0:key"]);
  await expect(page.getByRole("button", { name: "Release to a Job" })).toHaveCount(0);
  await page.getByRole("link", { name: "Open the Job" }).click();
  await expect(page.locator("#job-page")).toHaveText("Job page /jobs/88");
});

test("a replayed release says nothing was created twice", async ({ page }) => {
  await mount(page, { permissions: ALL, replay: true });
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await page.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByTestId("release-result")).toContainText("nothing was created twice");
});

test("an already-released service explains that a second Job is impossible and offers no release", async ({ page }) => {
  await mount(page, { permissions: ALL, releaseRefusal: { status: 409, reason: "RELEASE_ALREADY_RECORDED" } });
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await page.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByTestId("release-error")).toContainText("exactly one Job and cannot create a second");
  await expect(page.getByRole("button", { name: "Release to a Job" })).toHaveCount(0);
});

test("a stale review is refreshed and asks for a new review; a missing receiving permission is explained", async ({ page }) => {
  await mount(page, { permissions: ALL, releaseRefusal: { status: 409, reason: "PREVIEW_TOKEN_STALE" } });
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await page.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByTestId("release-error")).toContainText("Review the release again");
  expect((await calls(page)).filter((c) => c.startsWith("preview:")).length).toBeGreaterThan(1);
  await mount(page, { permissions: ALL, releaseRefusal: { status: 403 } });
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await page.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByTestId("release-error")).toContainText("receive jobs");
});

test("a failed review shows a retryable error", async ({ page }) => {
  await mount(page, { permissions: ALL, previewFlaky: true });
  await expect(page.getByRole("alert")).toContainText(/.+/);
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByText("Ready to release")).toBeVisible();
});

test("without the write duties the screen reads but offers no recording or release", async ({ page }) => {
  await mount(page, { permissions: READ });
  await expect(page.getByRole("heading", { name: "Prepared handover" })).toBeVisible();
  await expect(page.getByText(/needs a separate permission/).first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Release to a Job" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Record these conditions" })).toHaveCount(0);
});

test("phone and desktop, light and dark, are free of overflow and accessibility violations", async ({ page }) => {
  for (const [dark, width] of [[false, 390], [true, 390], [false, 1280], [true, 1280]] as const) {
    await mount(page, { permissions: ALL }, dark, width);
    await expect(page.getByText("Ready to release")).toBeVisible();
    await expectPhoneSafe(page);
    await expectNoA11yViolations(page);
  }
});

const planPayload = {
  id: 12,
  acceptance_id: 55,
  stage_id: 9,
  recorded_at: "2026-11-11T09:00:00+05:00",
  state: "PREPARED",
  release_performed: true,
  operational: { deliverable_identity: "x", description: "d", location_kind: "REMOTE", instructions: "", contact: null, site: null },
};

test("the release status, conditions and navigation reads are parsed to exactly the safe shape", () => {
  const release = { release_event_id: 3, released_at: "2026-11-20T10:00:00+05:00", job_access: "VISIBLE", job: { id: 8, code: "JOB-00001", secret: "x" }, cost: "1" };
  expect(readPlan({ ...planPayload, release }).release).toEqual({ release_event_id: 3, released_at: "2026-11-20T10:00:00+05:00", job_access: "VISIBLE", job: { id: 8, code: "JOB-00001" } });
  expect(readPlan({ ...planPayload, release: null }).release).toBeNull();
  expect(() => readPlan(planPayload)).toThrow(); // the server always says; absence is a contract break, never "not released"
  expect(() => readPlan({ ...planPayload, release: { ...release, job_access: "MAYBE" } })).toThrow();
  const cond = { basis_id: 4, version: 2, terms_source: "EVIDENCE_TEXT", agreed_text: "t", materials_scope: "EXCLUDED", subcontract_scope: "EXCLUDED", evidence_current: true, recorded_at: "2026-11-12T09:00:00+05:00", evidence_id: 9 };
  expect(JSON.stringify(readConditions({ acceptance_id: 55, current: cond }))).not.toContain("evidence_id");
  expect(readConditions({ acceptance_id: 55, current: null }).current).toBeNull();
  expect(readHandoverStatus({ acceptance_id: 55, prepared: false, plan_id: null, stage_id: null, release_performed: false, other: 1 })).toEqual({ acceptance_id: 55, prepared: false, plan_id: null, stage_id: null, release_performed: false });
  expect(blockerText("COMPLETION_EVIDENCE_SUPERSEDED")).toMatch(/corrected/);
});

test("the exact agreed text and version are shown to people who may record or release, and hidden from readers", async ({ page }) => {
  await mount(page, { permissions: ALL });
  await expect(page.getByTestId("agreed-text")).toContainText("MATERIALS: EXCLUDED");
  await expect(page.getByText("Version 1")).toBeVisible();
  await expect(page.getByText("from a later written customer message")).toBeVisible();
  await expect(page.getByTestId("evidence-corrected")).toHaveCount(0);
  await mount(page, { permissions: READ });
  await expect(page.getByTestId("conditions-hidden")).toBeVisible();
  await expect(page.getByTestId("agreed-text")).toHaveCount(0);
  expect((await calls(page)).filter((c) => c.startsWith("conditions:"))).toEqual([]);
});

test("a corrected customer message is called out, and a failed conditions read is retryable", async ({ page }) => {
  await mount(page, { permissions: ALL, evidenceCorrected: true, blockers: ["COMPLETION_EVIDENCE_SUPERSEDED"] });
  await expect(page.getByTestId("evidence-corrected")).toContainText("no longer support a release");
  await expect(page.getByTestId("blockers")).toContainText("later corrected");
  await expect(page.getByRole("button", { name: "Release to a Job" })).toBeDisabled();
  await expectNoA11yViolations(page);
  await mount(page, { permissions: ALL, conditionsFail: true });
  await expect(page.getByRole("alert").first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
});

test("a new basis version elsewhere makes the reviewed release stale: it is refreshed before release is possible again", async ({ page }) => {
  await mount(page, { permissions: ALL, basisMovesOn: true });
  await expect(page.getByText("Version 1")).toBeVisible();
  await expect(page.getByRole("button", { name: "Release to a Job" })).toBeEnabled();
  await page.evaluate(() => window.dispatchEvent(new Event("visibilitychange")));
  await expect(page.getByText("Version 2")).toBeVisible();
  await expect.poll(async () => (await calls(page)).filter((c) => c.startsWith("preview:")).length).toBeGreaterThan(1);
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await page.getByRole("button", { name: "Release", exact: true }).click();
  // the release carries the refreshed review (basis 99, fingerprint of version 2), never the old one
  expect((await calls(page)).filter((c) => c.startsWith("release:"))).toEqual(["release:9:99:fp-2-0:key"]);
});

test("reopening an already-released plan shows the existing Job, offers no second release and never says a Job was created", async ({ page }) => {
  await mount(page, { permissions: ALL, released: "visible" });
  await expect(page.getByTestId("existing-release")).toContainText("Already released — existing Job");
  await expect(page.getByTestId("existing-release")).toContainText("a second one cannot be created");
  await expect(page.getByTestId("job-code")).toHaveText("JOB-00007");
  await expect(page.getByText("one Job was created")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Release to a Job" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Record these conditions" })).toHaveCount(0);
  expect((await calls(page)).filter((c) => c.startsWith("preview:") || c.startsWith("release:") || c.startsWith("basis:"))).toEqual([]);
  await expectPhoneSafe(page);
  await expectNoA11yViolations(page);
  await page.getByRole("link", { name: "Open the Job" }).click();
  await expect(page.locator("#job-page")).toHaveText("Job page /jobs/88");
});

test("a released service whose Job this person may not see, or that cannot be read now, says so without a number or a link", async ({ page }) => {
  await mount(page, { permissions: ALL, released: "denied" });
  await expect(page.getByTestId("existing-release")).toContainText("Already released");
  await expect(page.getByTestId("job-not-permitted")).toContainText("don’t have permission to see Jobs");
  await expect(page.getByTestId("job-code")).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Open the Job" })).toHaveCount(0);
  await expectNoA11yViolations(page);
  await mount(page, { permissions: ALL, released: "unavailable" });
  await expect(page.getByTestId("job-unavailable")).toBeVisible();
  await expect(page.getByRole("link", { name: "Open the Job" })).toHaveCount(0);
});

test("when someone else releases it before this person confirms, the existing release is shown and no request is sent", async ({ page }) => {
  await mount(page, { permissions: ALL, releasedBeforeConfirm: true });
  await page.getByRole("button", { name: "Release to a Job" }).click();
  await page.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByTestId("existing-release")).toContainText("Already released — existing Job");
  await expect(page.getByText("one Job was created")).toHaveCount(0);
  expect((await calls(page)).filter((c) => c.startsWith("release:"))).toEqual([]);
});

test("an unavailable or inaccessible handover is explained, with retry only for a failure", async ({ page }) => {
  await mount(page, { permissions: ALL, planFailure: 404 });
  await expect(page.getByRole("heading", { name: "Handover unavailable" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Try again" })).toHaveCount(0);
  await mount(page, { permissions: ALL, planFailure: 500 });
  await expect(page.getByRole("heading", { name: "Could not load this handover" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
});

test("keyboard: the release is reachable and confirmable without a pointer, and Escape cancels it", async ({ page }) => {
  await mount(page, { permissions: ALL });
  const release = page.getByRole("button", { name: "Release to a Job" });
  await expect(release).toBeEnabled();
  await release.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(release).toBeFocused();
  expect((await calls(page)).filter((c) => c.startsWith("release:"))).toEqual([]);
});

test("320px: no overflow, readable states and no accessibility violations in light and dark", async ({ page }) => {
  for (const fixture of [{ permissions: ALL }, { permissions: ALL, released: "visible" as const }, { permissions: ALL, noBasis: true }]) {
    for (const dark of [false, true]) {
      await mount(page, fixture, dark, 320);
      await expect(page.getByRole("heading", { name: "Prepared handover" })).toBeVisible();
      await expect(page.getByRole("heading", { name: /Release|Record completion conditions/ }).first()).toBeVisible();
      await expectPhoneSafe(page);
      await expectNoA11yViolations(page);
    }
  }
});
