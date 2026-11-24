import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { api, jobCodes, openAs, recordApi } from "./real-backend/mount";
import { world } from "./real-backend/world";

// Issued quotation -> Customer acceptance -> Prepare handover -> Record genuine completion evidence -> Preview release -> Release -> Open existing Job,
// in a real browser against THIS checkout's actual Laravel application and a private database (docs/architecture/real-backend-browser-tests.md). Nothing is intercepted.
// The accepted services, the customer's written messages and the people are the committed test world (tests/E2E/RealBackend/BuildWorldTest.php), built through the real Actions.

const w = () => world();
const releaseButton = (page: Page) => page.getByRole("button", { name: "Release to a Job" });
const confirmRelease = async (page: Page) => {
  await releaseButton(page).click();
  await page.getByRole("dialog").getByRole("button", { name: "Release", exact: true }).click();
};
async function prepareThroughTheScreen(page: Page, opts: { onSite: boolean; contact: string; site?: string; instructions: string }) {
  await page.getByLabel(opts.onSite ? "On site" : "Remote").check();
  await page.getByLabel("Customer contact").selectOption({ label: new RegExp(opts.contact) as never }).catch(async () => {
    const value = await page.getByLabel("Customer contact").locator("option", { hasText: opts.contact }).getAttribute("value");
    await page.getByLabel("Customer contact").selectOption(value!);
  });
  if (opts.site) {
    const value = await page.getByLabel("Customer site").locator("option", { hasText: opts.site }).getAttribute("value");
    await page.getByLabel("Customer site").selectOption(value!);
  }
  await page.getByLabel("Operational instructions").fill(opts.instructions);
  await page.getByRole("button", { name: /^Review/ }).click();
  await expect(page.getByText("Ready to prepare")).toBeVisible();
  await page.getByRole("button", { name: "Prepare handover…" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Prepare", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Prepared handover" })).toBeVisible();
}
async function recordGenuineConditions(page: Page) {
  await page.getByLabel("A later written customer message.").check();
  await page.getByLabel(/MATERIALS: EXCLUDED/).first().check();
  await page.getByRole("button", { name: "Record these conditions" }).click();
  await expect(page.getByTestId("basis-recorded")).toBeVisible();
  await expect(page.getByText("Ready to release")).toBeVisible();
}

test("remote service, the whole journey: accepted service -> prepare -> record genuine conditions -> review -> release -> the existing Job", async ({ page, request }) => {
  const s = w().scenarios.remote;
  const officer = w().users.officer;
  const before = await jobCodes(request, officer);
  const sent = recordApi(page);

  await openAs(page, officer, `/customer-acceptances/${s.acceptance}`);
  await expect(page.getByTestId("handover-not-prepared")).toBeVisible();
  await page.getByTestId("prepare-handover-link").click();
  await expect(page.getByRole("heading", { name: "Prepare handover", level: 1 })).toBeVisible();

  // Only THIS customer's own contacts are offered: another customer's contact is never listed.
  await expect(page.getByLabel("Customer contact").locator("option", { hasText: "Foreign Contact" })).toHaveCount(0);
  await expect(page.getByLabel("Customer contact").locator("option", { hasText: `Amina Khan ${s.tag}` })).toHaveCount(1);
  await prepareThroughTheScreen(page, { onSite: false, contact: `Amina Khan ${s.tag}`, instructions: "Call the contact before connecting." });

  // The server holds exactly the prepared plan, and preparing released nothing and made no Job.
  const status = await api(request, officer, "GET", `/customer-acceptances/${s.acceptance}/handover`);
  expect(status.body.data).toMatchObject({ prepared: true, release_performed: false });
  expect(await jobCodes(request, officer)).toEqual(before);

  await expect(releaseButton(page)).toBeDisabled();
  await expect(page.getByTestId("no-basis")).toBeVisible();
  await recordGenuineConditions(page);
  await expect(page.getByText("Version 1", { exact: true })).toBeVisible();

  await confirmRelease(page);
  const code = (await page.getByTestId("job-code").textContent())!.trim();
  await expect(page.getByTestId("release-result")).toContainText("The service is released and has one Job.");
  await expect(page.getByTestId("release-result")).not.toContainText(/new Job was created|one Job was created/);
  const after = await jobCodes(request, officer);
  expect(after.filter((c) => c === code)).toHaveLength(1);
  expect(after).toHaveLength(before.length + 1);

  await page.getByRole("link", { name: "Open the Job" }).click();
  await expect(page.getByText(code).first()).toBeVisible();

  // Reopening the released handover: the existing Job, no second release.
  await openAs(page, officer, `/handover-plans/${(status.body.data as { plan_id: number }).plan_id}`);
  await expect(page.getByTestId("existing-release")).toContainText(code);
  await expect(releaseButton(page)).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Record these conditions" })).toHaveCount(0);
  // The only writes this browser made were exactly one preparation, one recording of the conditions and one release.
  expect(sent.requests().filter((r) => r.startsWith("POST"))).toEqual([
    `POST /customer-acceptances/${s.acceptance}/handover-plans`,
    `POST /customer-acceptances/${s.acceptance}/completion-bases`,
    expect.stringMatching(/^POST \/handover-stages\/\d+\/release$/),
  ]);
  expect(await jobCodes(request, officer)).toHaveLength(before.length + 1);
});

test("on-site service: the site is required, only this customer's active sites are offered, and the plan carries the chosen contact and site", async ({ page, request }) => {
  const s = w().scenarios.onsite;
  const officer = w().users.officer;
  await openAs(page, officer, `/customer-acceptances/${s.acceptance}/prepare-handover`);
  await page.getByLabel("On site").check();
  await expect(page.getByLabel("Customer site").locator("option", { hasText: "Foreign Site" })).toHaveCount(0);
  const contactValue = await page.getByLabel("Customer contact").locator("option", { hasText: `Bilal Raza ${s.tag}` }).getAttribute("value");
  await page.getByLabel("Customer contact").selectOption(contactValue!);
  await page.getByRole("button", { name: /^Review/ }).click();
  await expect(page.getByTestId("prep-missing")).toContainText("Choose a current, active site");
  await expect(page.getByRole("button", { name: "Prepare handover…" })).toBeDisabled();
  await prepareThroughTheScreen(page, { onSite: true, contact: `Bilal Raza ${s.tag}`, site: `Karachi plant ${s.tag}`, instructions: "Gate pass needed." });

  const planId = ((await api(request, officer, "GET", `/customer-acceptances/${s.acceptance}/handover`)).body.data as { plan_id: number }).plan_id;
  const plan = (await api(request, officer, "GET", `/handover-plans/${planId}`)).body.data as { operational: { location_kind: string; contact: { name: string }; site: { name: string }; instructions: string } };
  expect(plan.operational.location_kind).toBe("ON_SITE");
  expect(plan.operational.contact.name).toBe(`Bilal Raza ${s.tag}`);
  expect(plan.operational.site.name).toBe(`Karachi plant ${s.tag}`);
  expect(plan.operational.instructions).toBe("Gate pass needed.");
});

test("another customer's contact or site is refused by the server even when asked for directly", async ({ request }) => {
  const s = w().scenarios.remote;
  const officer = w().users.officer;
  const d = w().decoys;
  const q = (extra: string) => `/customer-acceptances/${s.acceptance}/handover-preview?location_kind=ON_SITE&${extra}`;
  expect((await api(request, officer, "GET", q(`contact_id=${d.foreign_contact}`))).status).toBe(404);
  expect((await api(request, officer, "GET", q(`contact_id=${s.contact}&site_id=${d.foreign_site}`))).status).toBe(404);
});

test("a second preparation is refused and the screen opens the existing handover instead of a form", async ({ page, request }) => {
  const s = w().scenarios.bare;
  const officer = w().users.officer;
  await openAs(page, officer, `/customer-acceptances/${s.acceptance}/prepare-handover`);
  await expect(page.getByTestId("already-prepared")).toBeVisible();
  await expect(page.getByLabel("Remote")).toHaveCount(0);
  const preview = await api(request, officer, "GET", `/customer-acceptances/${s.acceptance}/handover-preview?location_kind=REMOTE&contact_id=${s.contact}`);
  expect(preview.status).toBe(200);
  const token = (preview.body.data as { preview_token: string }).preview_token;
  const again = await api(request, officer, "POST", `/customer-acceptances/${s.acceptance}/handover-plans`, { location_kind: "REMOTE", contact_id: s.contact, preview_token: token }, "second-prepare");
  expect(again.status).toBe(409);
  expect(again.body.reason_code).toBe("SCOPE_ALREADY_CLAIMED");
});

test("missing customer evidence: nothing to cite, so no conditions and no release", async ({ page, request }) => {
  const s = w().scenarios.bare;
  const officer = w().users.officer;
  const before = await jobCodes(request, officer);
  await openAs(page, officer, `/handover-plans/${s.plan}`);
  await expect(page.getByRole("heading", { name: "Prepared handover" })).toBeVisible();
  await expect(page.getByTestId("blockers")).toContainText("completion conditions have not been recorded");
  await expect(releaseButton(page)).toBeDisabled();
  await page.getByLabel("A later written customer message.").check();
  await expect(page.getByTestId("no-messages")).toBeVisible();
  const refused = await api(request, officer, "POST", `/handover-stages/${s.stage}/release`, { basis_id: 999999, readiness_fingerprint: "x" }, "no-basis");
  expect([404, 422]).toContain(refused.status);
  expect(await jobCodes(request, officer)).toEqual(before);
});

test("corrected customer evidence: the old message cannot be chosen, and a correction after recording blocks the release for good", async ({ page, request, browser }) => {
  const s = w().scenarios.evidence;
  const { officer, evidence_recorder: recorder } = w().users;
  const before = await jobCodes(request, officer);

  // The API refuses to cite the superseded message as new agreement evidence.
  const stale = await api(request, officer, "POST", `/customer-acceptances/${s.acceptance}/completion-bases`, { agreed_text: "x", evidence_id: s.superseded_evidence }, "cite-old");
  expect(stale.status).toBe(422);

  await openAs(page, officer, `/handover-plans/${s.plan}`);
  await page.getByLabel("A later written customer message.").check();
  await expect(page.getByText("A later message corrected this one.").first()).toBeVisible();
  // Two messages declare the same conditions: the corrected (first) one cannot be chosen, its correction (last) can.
  await expect(page.getByLabel(/MATERIALS: EXCLUDED/).first()).toBeDisabled();
  await page.getByLabel(/MATERIALS: EXCLUDED/).last().check();
  await page.getByRole("button", { name: "Record these conditions" }).click();
  await expect(page.getByTestId("basis-recorded")).toBeVisible();
  await expect(page.getByText("Ready to release")).toBeVisible();

  // Meanwhile the customer corrects the very message the conditions rest on (a real evidence correction through the real endpoint).
  const caseDetail = await api(request, recorder, "GET", `/customer-decision-cases/${s.case}`);
  const generation = (caseDetail.body.data as { case: { generation: number } }).case.generation;
  const corrected = await api(request, recorder, "POST", `/customer-decision-cases/${s.case}/evidence`, {
    expected_generation: generation, predecessor_id: s.current_evidence, source_kind: "EMAIL", authority_verification_basis: "DOCUMENT_ON_FILE", customer_representative_snapshot: "Synthetic buyer",
    occurrence_precision: "UNKNOWN", factual_description: "Correction: the customer now wants materials supplied by us.",
  }, "correct-current");
  expect(corrected.status).toBe(201);

  // The page the officer already has open still says ready; the server does not agree, and nothing is released.
  await confirmRelease(page);
  await expect(page.getByRole("alert").first()).toBeVisible();
  expect(await jobCodes(request, officer)).toEqual(before);

  // A fresh look shows why: the corrected message is called out and release is blocked.
  const other = await browser.newPage();
  await openAs(other, officer, `/handover-plans/${s.plan}`);
  await expect(other.getByText(/later corrected/i).first()).toBeVisible();
  await expect(other.getByTestId("blockers")).toContainText("later corrected");
  await expect(releaseButton(other)).toBeDisabled();
  expect(await jobCodes(request, officer)).toEqual(before);
});

test("stale review: new conditions recorded elsewhere make the old review refused, then a fresh review releases once", async ({ page, request }) => {
  const s = w().scenarios.stale;
  const { officer, evidence_recorder: recorder } = w().users;
  const before = await jobCodes(request, officer);
  await openAs(page, officer, `/handover-plans/${s.plan}`);
  await expect(page.getByText("Ready to release")).toBeVisible();
  await expect(page.getByText("Version 1", { exact: true })).toBeVisible();

  // Elsewhere, a NEW version of the conditions is recorded on a new written message through the real endpoints (a message may back only one version).
  const detail = await api(request, recorder, "GET", `/customer-decision-cases/${s.case}`);
  const observations = (detail.body.data as { observations: { id: number; factual_description: string; is_current: boolean }[]; case: { generation: number } }).observations;
  const text = observations.find((o) => o.factual_description.startsWith("MATERIALS: EXCLUDED"))!.factual_description;
  const added = await api(request, recorder, "POST", `/customer-decision-cases/${s.case}/evidence`, {
    expected_generation: (detail.body.data as { case: { generation: number } }).case.generation, source_kind: "EMAIL", authority_verification_basis: "DOCUMENT_ON_FILE",
    customer_representative_snapshot: "Synthetic buyer", occurrence_precision: "UNKNOWN", factual_description: text,
  }, "stale-new-message");
  expect(added.status).toBe(201);
  const v2 = await api(request, officer, "POST", `/customer-acceptances/${s.acceptance}/completion-bases`, { agreed_text: text, evidence_id: (added.body.data as { evidence_id: number }).evidence_id }, "stale-basis-v2");
  expect(v2.status).toBe(201);

  // The open page still holds the old review: the server refuses it and nothing is released.
  await confirmRelease(page);
  await expect(page.getByRole("alert").first()).toContainText(/changed|review/i);
  expect(await jobCodes(request, officer)).toEqual(before);

  // The screen takes a fresh review (version 2); releasing on THAT works, once.
  await expect(page.getByText("Version 2", { exact: true })).toBeVisible();
  await confirmRelease(page);
  await expect(page.getByTestId("job-code")).toBeVisible();
  expect(await jobCodes(request, officer)).toHaveLength(before.length + 1);
});

test("duplicate submission: a double-clicked release and a second browser create exactly one Job", async ({ page, request, browser }) => {
  const s = w().scenarios.dup;
  const officer = w().users.officer;
  const before = await jobCodes(request, officer);
  const other = await browser.newPage();
  await openAs(page, officer, `/handover-plans/${s.plan}`);
  await openAs(other, officer, `/handover-plans/${s.plan}`);
  await expect(page.getByText("Ready to release")).toBeVisible();
  await expect(other.getByText("Ready to release")).toBeVisible();

  await releaseButton(page).click();
  await page.getByRole("dialog").getByRole("button", { name: "Release", exact: true }).dblclick();
  await expect(page.getByTestId("job-code")).toBeVisible();

  // The second browser still believed it could release.
  await confirmRelease(other);
  await expect(other.getByRole("alert").or(other.getByTestId("release-result")).or(other.getByTestId("existing-release")).first()).toBeVisible();
  const after = await jobCodes(request, officer);
  expect(after).toHaveLength(before.length + 1);
  expect(new Set(after).size).toBe(after.length);
});

test("reopening an already released handover shows the existing Job, offers no second release, and the server refuses one", async ({ page, request }) => {
  const s = w().scenarios.released;
  const officer = w().users.officer;
  const before = await jobCodes(request, officer);
  await openAs(page, officer, `/handover-plans/${s.plan}`);
  await expect(page.getByTestId("existing-release")).toContainText(s.job_code!);
  await expect(releaseButton(page)).toHaveCount(0);
  await page.getByRole("link", { name: "Open the Job" }).click();
  await expect(page.getByText(s.job_code!).first()).toBeVisible();
  const preview = await api(request, officer, "GET", `/handover-stages/${s.stage}/release-preview`);
  const basisId = (preview.body.data as { basis_id: number; fingerprint: string });
  // A second release request with a fresh key is a business replay: it names the SAME Job and creates nothing.
  const again = await api(request, officer, "POST", `/handover-stages/${s.stage}/release`, { basis_id: basisId.basis_id, readiness_fingerprint: basisId.fingerprint }, "second-release-attempt");
  expect([200, 201, 409]).toContain(again.status);
  if (again.status !== 409) expect(again.body.data).toMatchObject({ job_id: s.job, job_code: s.job_code });
  expect(await jobCodes(request, officer)).toEqual(before);
});

test("a person who may not see Jobs sees the release without a Job reference", async ({ page }) => {
  const s = w().scenarios.released;
  await openAs(page, w().users.officer_without_job_view, `/handover-plans/${s.plan}`);
  await expect(page.getByTestId("existing-release")).toBeVisible();
  await expect(page.getByTestId("existing-release")).not.toContainText(s.job_code!);
  await expect(page.getByRole("link", { name: "Open the Job" })).toHaveCount(0);
});

test("permissions: the server decides, whatever the screen shows", async ({ page, request }) => {
  const { viewer, preparer, releaser_without_jobs: noJobs, revoked_officer: revoked, foreign_officer: foreign, officer } = w().users;
  const fresh = w().scenarios.remote;
  const ready = w().scenarios.norecv;
  const before = await jobCodes(request, officer);

  // A viewer can read but is offered nothing to prepare, and the server refuses the lookup.
  await openAs(page, viewer, `/customer-acceptances/${fresh.acceptance}`);
  await expect(page.getByTestId("handover-not-prepared").or(page.getByTestId("handover-prepared"))).toBeVisible();
  await expect(page.getByTestId("prepare-handover-link")).toHaveCount(0);
  expect((await api(request, viewer, "GET", `/customer-acceptances/${fresh.acceptance}/handover-options`)).status).toBe(403);

  // A preparer cannot release: no controls, and the server says no.
  await openAs(page, preparer, `/handover-plans/${ready.plan}`);
  await expect(page.getByText(/needs a separate permission/).first()).toBeVisible();
  await expect(releaseButton(page)).toHaveCount(0);
  expect((await api(request, preparer, "POST", `/handover-stages/${ready.stage}/release`, { basis_id: 1, readiness_fingerprint: "x" }, "no-duty")).status).toBe(403);

  // Someone with the release duty but without the Jobs receiving duty is refused, and nothing is created.
  await openAs(page, noJobs, `/handover-plans/${ready.plan}`);
  await expect(page.getByText("Ready to release")).toBeVisible();
  await confirmRelease(page);
  await expect(page.getByRole("alert").first()).toContainText(/permission/i);

  // A person whose release grant was REVOKED after their token was issued, on a screen that still believes they hold it: the server refuses the review and the release alike,
  // so the screen can offer nothing, whatever it was told.
  await openAs(page, { ...revoked, duties: revoked.stale_screen_duties! }, `/handover-plans/${ready.plan}`);
  await expect(page.getByText(/don.t have permission/i).first()).toBeVisible();
  await expect(page.getByText("Ready to release")).toHaveCount(0);
  await expect(releaseButton(page)).toHaveCount(0);
  expect((await api(request, revoked, "GET", `/handover-stages/${ready.stage}/release-preview`)).status).toBe(403);
  expect((await api(request, revoked, "POST", `/handover-stages/${ready.stage}/release`, { basis_id: 1, readiness_fingerprint: "x" }, "revoked-release")).status).toBe(403);
  expect(await jobCodes(request, officer)).toEqual(before);

  // Another company sees none of it.
  expect((await api(request, foreign, "GET", `/customer-acceptances/${fresh.acceptance}/handover`)).status).toBe(404);
  expect((await api(request, foreign, "GET", `/customer-acceptances/${fresh.acceptance}/handover-options`)).status).toBe(404);
  expect((await api(request, foreign, "GET", `/handover-plans/${ready.plan}`)).status).toBe(404);
  expect((await api(request, foreign, "GET", `/handover-stages/${ready.stage}/release-preview`)).status).toBe(404);
  await openAs(page, foreign, `/handover-plans/${ready.plan}`);
  await expect(page.getByRole("heading", { name: "Handover unavailable" })).toBeVisible();
  expect(await jobCodes(request, officer)).toEqual(before);
});

test("the journey screens stay accessible and phone-safe against real data", async ({ page }) => {
  const s = w().scenarios.norecv;
  for (const [path, ready] of [[`/handover-plans/${s.plan}`, "Ready to release"], [`/customer-acceptances/${s.acceptance}/prepare-handover`, "Prepare handover"]] as const) {
    await openAs(page, w().users.officer, path, 320);
    await expect(page.getByText(ready).first()).toBeVisible();
    await page.evaluate(() => Promise.all(document.getAnimations().filter((a) => a.effect?.getComputedTiming().iterations !== Infinity).map((a) => a.finished.catch(() => undefined))));
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.map((v) => `${v.id}: ${v.help}`)).toEqual([]);
  }
});
