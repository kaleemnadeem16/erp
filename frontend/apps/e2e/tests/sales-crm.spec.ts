import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { api, findByCode, signInAs } from './package-stock-support';

/**
 * Phase 4 CRM foundation (ADRs 0098, 0105–0108): an optional enquiry that becomes deals, one accountable owner, stages with history, closing and reopening, and what
 * each kind of person can and cannot do. Personas come from the SalesCrmDemoSeeder (`demo:seed`).
 */
const REP = 'demo-sales-rep@erp.x1techs.com';
const MANAGER = 'demo-sales-manager@erp.x1techs.com';
const VIEWER = 'demo-sales-viewer@erp.x1techs.com';
const BRANCH = 'demo-sales-branch@erp.x1techs.com';
const REVIEWER = 'demo-sales-reviewer@erp.x1techs.com';

/** One sign-in per person for the whole file (sign-in is throttled), then a fresh page in that person's session for each test. */
const sessions = new Map<string, BrowserContext>();
async function as(browser: Browser, email: string): Promise<Page> {
  let context = sessions.get(email);
  if (!context) {
    context = (await signInAs(browser, email)).context();
    sessions.set(email, context);
  }

  return context.newPage();
}

test.afterAll(async () => {
  for (const context of sessions.values()) await context.close();
  sessions.clear();
});

async function pickParty(page: Page, search: string, option: RegExp) {
  await page.getByRole('combobox', { name: 'Trading partner' }).click();
  await page.locator('[cmdk-input]').fill(search);
  await page.getByRole('option', { name: option }).first().click();
}

test('a representative records an enquiry, qualifies it, links a partner and turns it into two deals', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, REP);
  const stamp = Date.now().toString(36);

  await page.goto('/leads/new');
  await page.getByLabel('Who is asking?').fill(`E2E Enquirer ${stamp}`);
  await page.getByLabel('Organisation').fill('E2E Interiors');
  await page.getByLabel('Phone').fill('123');
  await page.getByLabel('Where did it come from?').fill('Trade fair');
  await page.getByTestId('lead-save').click();
  await expect(page).toHaveURL(/\/leads\/\d+$/);
  await expect(page.getByTestId('lead-detail')).toContainText('New');

  // Without a partner the enquiry cannot become a deal: it says why instead of failing.
  await page.getByTestId('lead-convert').click();
  await expect(page.getByTestId('convert-needs-party')).toBeVisible();
  await page.getByRole('button', { name: 'Go back' }).click();

  const leadUrl = page.url();
  await page.getByTestId('lead-qualify').click();
  await expect(page.getByTestId('lead-detail')).toContainText('Qualified');
  await pickParty(page, 'Alpha', /Alpha Decorators/);
  await page.getByTestId('lead-link-party').click();
  await expect(page.getByTestId('lead-party')).toContainText('Alpha Decorators');

  for (const title of [`E2E furniture ${stamp}`, `E2E installation ${stamp}`]) {
    await page.getByTestId('lead-convert').click();
    await page.getByLabel('Title').fill(title);
    await page.getByTestId('lead-convert-confirm').click();
    await expect(page).toHaveURL(/\/opportunities\/\d+$/);
    await expect(page.getByRole('dialog')).toContainText(title);
    await expect(page.getByTestId('sales-history')).toContainText('Opportunity created from an enquiry');
    await page.goto(leadUrl);
  }
  await expect(page.getByTestId('lead-opportunities').getByRole('link')).toHaveCount(2);

  // Work the first deal: stage, note and the manual next action all land in the record.
  await page.getByTestId('lead-opportunities').getByRole('link').first().click();
  await page.getByTestId('stage-select').click();
  await page.getByRole('option', { name: 'Negotiation' }).click();
  await expect(page.getByTestId('sales-history')).toContainText('Stage: Qualification → Negotiation');
  await page.getByLabel('Add a note').fill('Customer wants delivery in two phases');
  await page.getByTestId('note-add').click();
  await expect(page.getByTestId('sales-notes')).toContainText('two phases');
  await expect(page.getByTestId('sales-note').first()).toContainText('Demo Sales Rep');

  // A representative cannot reassign, close, or configure.
  await expect(page.getByTestId('opportunity-reassign')).toHaveCount(0);
  await expect(page.getByTestId('opportunity-close')).toHaveCount(0);

  // The intake turns out to be a duplicate: it is disqualified on its own merits while both genuine deals stay open (ADR 0111).
  await page.goto(leadUrl);
  await page.getByTestId('lead-disqualify').click();
  await expect(page.getByTestId('lead-reason-dialog')).toContainText('stay open');
  await page.getByLabel('Why?').fill('Duplicate intake');
  await page.getByTestId('lead-reason-confirm').click();
  await expect(page.getByTestId('lead-detail')).toContainText('Disqualified');
  await expect(page.getByTestId('lead-opportunities').getByRole('link')).toHaveCount(2);

  // A mistyped detail is corrected in any state: the received phone stays recorded, the corrected one becomes current, and the status is untouched (ADR 0113).
  await expect(page.getByTestId('lead-original-kind')).toContainText('Original intake');
  await page.getByTestId('lead-correct').click();
  await page.getByLabel('Phone').fill('132');
  await expect(page.getByTestId('lead-correct-confirm')).toBeDisabled();
  await page.getByLabel('Why is this being corrected?').fill('Mistyped');
  await page.getByTestId('lead-correct-confirm').click();
  await expect(page.getByTestId('lead-detail')).toContainText('132');
  await expect(page.getByTestId('lead-detail')).toContainText('Disqualified');
  await expect(page.getByTestId('lead-original-values')).toContainText('123');
  await expect(page.getByTestId('lead-corrections')).toContainText('Mistyped');
  await page.close();
});

test('the register-partner dialog warns about an existing identity instead of creating a duplicate', async ({ browser }) => {
  test.setTimeout(120_000);
  const page = await as(browser, REP);
  await page.goto('/opportunities/new');
  await page.getByTestId('register-party').click();
  await page.getByLabel('Name').fill('Alpha Decorators');
  await expect(page.getByTestId('party-candidates')).toContainText('Alpha Decorators');
  await expect(page.getByTestId('party-candidates')).toContainText('matched on name');
  await expect(page.getByTestId('register-party-submit')).toContainText('Register as new anyway');
  await page.getByTestId('party-candidate').getByRole('button', { name: 'Use this one' }).first().click();
  await expect(page.getByRole('combobox', { name: 'Trading partner' })).toContainText('Alpha Decorators');
  await page.close();
});

test('a manager closes and reopens a deal, and replaces the owner of a deal whose owner has left', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  const stamp = Date.now().toString(36);

  await page.goto('/opportunities/new');
  await page.getByLabel('Title').fill(`E2E direct deal ${stamp}`);
  await pickParty(page, 'Metro', /Metro Engineering/);
  await page.getByRole('button', { name: /Stage, value, dates and notes/ }).click();
  await page.getByLabel('Expected value').fill('1500');
  await page.getByTestId('opportunity-save').click();
  await expect(page.getByTestId('value-problem')).toContainText('amount and its currency');
  await page.getByRole('combobox', { name: 'Currency' }).click();
  await page.getByRole('option', { name: 'PKR' }).click();
  await page.getByTestId('opportunity-save').click();
  await expect(page).toHaveURL(/\/opportunities\/\d+$/);
  await expect(page.getByTestId('expected-value')).toContainText('1500');

  await page.getByTestId('opportunity-close').click();
  await expect(page.getByTestId('close-confirm')).toBeDisabled();
  await page.getByLabel('Why?').fill('Customer chose another supplier');
  await page.getByTestId('close-confirm').click();
  await expect(page.getByTestId('closure')).toContainText('Customer chose another supplier');
  await expect(page.getByTestId('opportunity-edit')).toHaveCount(0); // a closed deal is not edited
  await page.getByTestId('opportunity-reopen').click();
  await page.getByLabel('Why is it being revived?').fill('They came back');
  await page.getByTestId('reopen-confirm').click();
  await expect(page.getByTestId('opportunity-edit')).toBeVisible();
  await expect(page.getByTestId('sales-history')).toContainText('Reopened');
  await expect(page.getByTestId('sales-history')).toContainText('Closed as Lost');

  // The deal whose owner left is flagged in the list and on the deal, and nothing replaced the owner behind anyone's back.
  await page.goto('/opportunities');
  await page.getByRole('button', { name: 'Needs a new owner' }).click();
  const row = page.getByRole('row', { name: /Showroom repaint \(owner has left\)/ });
  await expect(row).toContainText('needs a new owner');
  await row.click();
  await expect(page.getByTestId('owner-banner')).toContainText('can no longer be responsible');

  // Changing the owner of a deal records old and new, and gives the new owner no extra access (a fresh deal, so the demo's flagged one stays flagged).
  await page.goto('/opportunities');
  await page.getByPlaceholder(/Search title/).fill(`E2E direct deal ${stamp}`);
  await page.getByRole('row', { name: new RegExp(`E2E direct deal ${stamp}`) }).click();
  await page.getByTestId('opportunity-reassign').click();
  await page.getByRole('combobox', { name: 'New owner' }).click();
  await page.locator('[cmdk-input]').fill('Demo Sales Rep');
  await page.getByRole('option', { name: 'Demo Sales Rep' }).click();
  await page.getByLabel('Reason (optional)').fill('Moving to the territory owner');
  await page.getByTestId('reassign-confirm').click();
  await expect(page.getByTestId('opportunity-detail')).toContainText('Demo Sales Rep');
  await expect(page.getByTestId('sales-history')).toContainText('Owner changed');
  await expect(page.getByTestId('sales-history')).toContainText('Moving to the territory owner');
  await page.close();
});

test('a viewer reads the pipeline and cannot change it; a branch seller limited to their units is refused', async ({ browser }) => {
  test.setTimeout(180_000);
  const viewer = await as(browser, VIEWER);
  // Found by a deterministic server-side search: earlier runs leave deals behind, so the seeded one is not on page one.
  await viewer.goto('/opportunities?filter.search=Plant%20room');
  await expect(viewer.getByTestId('opportunities-list')).toBeVisible();
  await expect(viewer.getByRole('row', { name: /Plant room refurbishment/ })).toBeVisible();
  await expect(viewer.getByRole('button', { name: 'New opportunity' })).toHaveCount(0);
  await viewer.getByRole('row', { name: /Plant room refurbishment/ }).click();
  await expect(viewer.getByTestId('opportunity-detail')).toBeVisible();
  for (const control of ['opportunity-edit', 'opportunity-reassign', 'opportunity-close', 'note-add']) await expect(viewer.getByTestId(control)).toHaveCount(0);
  await viewer.goto('/opportunities/new');
  await expect(viewer.getByRole('heading', { name: 'You don’t have access' })).toBeVisible();
  await expect(viewer.getByTestId('opportunity-form')).toHaveCount(0);
  await viewer.close();

  const branch = await as(browser, BRANCH);
  await branch.goto('/opportunities');
  await expect(branch.getByText("Couldn't load opportunities.")).toBeVisible();
  await expect(branch.getByRole('row', { name: /Plant room refurbishment/ })).toHaveCount(0);
  await branch.goto('/leads');
  await expect(branch.getByText("Couldn't load enquiries.")).toBeVisible();
  await branch.close();
});

test('stages are renamed from their own panel and the change is shown on deals', async ({ browser }) => {
  test.setTimeout(180_000);
  const page = await as(browser, MANAGER);
  const rename = async (from: string, to: string) => {
    await page.goto(`/sales-stages?filter.search=${encodeURIComponent(from)}`);
    await page.getByRole('row', { name: new RegExp(`^.*${from.replace(/[()]/g, '\\$&')}`) }).first().click();
    await page.getByTestId('stage-edit').click();
    await expect(page.getByRole('dialog')).toContainText('Rename stage');
    await page.getByLabel('Name', { exact: true }).fill(to);
    await page.getByTestId('stage-save').click();
    await expect(page.getByTestId('stage-detail')).toContainText(to);
  };
  // A run killed between the rename and its restore leaves the shared demo stage renamed; heal that first so one interrupted run cannot fail every later one.
  await page.goto('/sales-stages');
  await expect(page.getByTestId('sales-stages')).toBeVisible();
  if ((await page.getByRole('row', { name: /Proposal \(e2e\)/ }).count()) > 0) await rename('Proposal (e2e)', 'Proposal');
  let renamed = false;
  try {
    await rename('Proposal', 'Proposal (e2e)');
    renamed = true;
  } finally {
    // Leave the shared demo stage as it was, even when an assertion above fails.
    if (renamed) await rename('Proposal (e2e)', 'Proposal');
  }
  await page.close();
});

test('stages have filters, an add panel, ordering, archive and restore, bulk change and printing', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  const stamp = Date.now().toString(36);

  // New stage in the side panel; its code comes from the name.
  await page.goto('/sales-stages/new');
  await expect(page.getByRole('dialog')).toContainText('New stage');
  await page.getByLabel('Name', { exact: true }).fill(`E2E stage ${stamp}`);
  await page.getByRole('button', { name: /Code and position/ }).click();
  await expect(page.getByLabel('Code')).toHaveValue(`E2E_STAGE_${stamp.toUpperCase()}`);
  await page.getByTestId('stage-save').click();
  const detail = page.getByTestId('stage-detail');
  await expect(detail).toContainText(`E2E stage ${stamp}`);
  await expect(detail).toContainText('Place in the order');
  await expect(page.getByTestId('stage-print')).toBeVisible();

  // Move it up and down from its panel; archive and restore it.
  await detail.getByRole('button', { name: /Move .* up/ }).click();
  await expect(detail).toContainText(/of \d+/);
  await page.getByTestId(`stage-toggle-E2E_STAGE_${stamp.toUpperCase()}`).click();
  await expect(detail).toContainText('Archived');
  await page.getByTestId(`stage-toggle-E2E_STAGE_${stamp.toUpperCase()}`).click();
  await expect(detail).toContainText('Active');

  // Filters as chips; Print is on the list.
  await page.goto(`/sales-stages?filter.search=${stamp}`);
  await expect(page.getByRole('row', { name: new RegExp(`E2E stage ${stamp}`) })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Print', exact: true }).first()).toBeVisible();
  await page.getByRole('combobox', { name: 'Status' }).click();
  await page.getByRole('option', { name: 'Archived' }).click();
  await expect(page.getByTestId('active-filters')).toContainText('Status: Archived');
  await expect(page.getByRole('row', { name: new RegExp(`E2E stage ${stamp}`) })).toHaveCount(0);
  await page.getByRole('button', { name: 'Clear all' }).click();

  // Tick it and archive it from the bulk bar (the stage is left archived: stages are never deleted).
  await page.goto(`/sales-stages?filter.search=${stamp}`);
  await page.getByRole('checkbox', { name: 'Select all' }).check();
  const bar = page.getByTestId('stage-bulk-bar');
  await expect(bar.getByTestId('bulk-count')).toHaveText('1 selected');
  await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
  await bar.getByTestId('bulk-archive').click();
  const dialog = page.getByTestId('bulk-dialog');
  await expect(dialog).toContainText('1 active stage');
  await dialog.getByTestId('bulk-confirm').click();
  await expect(dialog.getByTestId('bulk-result')).toContainText('1 changed');
  await dialog.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByRole('row', { name: new RegExp(`E2E stage ${stamp}`) })).toContainText('Archived');
  await page.close();
});

test('the pipeline list filters by partner and owner, shows removable chips and keeps its context around a record', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  await page.goto('/opportunities?filter.outcome=ALL');
  await expect(page.getByTestId('opportunities-list')).toBeVisible();

  // The partner filter lives in the advanced panel, asks the server, and shows as a named chip.
  await page.getByRole('button', { name: 'Filters' }).click();
  const request = page.waitForRequest((candidate) => candidate.url().includes('/api/v1/opportunities?') && candidate.url().includes('filter%5Bparty_id%5D'));
  await page.getByRole('combobox', { name: 'Trading partner' }).click();
  await page.locator('[cmdk-input]').fill('Metro');
  await page.getByRole('option', { name: /Metro Engineering/ }).first().click();
  await request;
  await expect(page).toHaveURL(/party_id=\d+/);
  const chips = page.getByTestId('active-filters');
  await expect(chips).toContainText('For: Metro Engineering');

  // Opening a record from the filtered list keeps the filters in the URL; closing the sheet returns to the same list.
  const row = page.getByRole('row').nth(1);
  await row.click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page).toHaveURL(/\/opportunities\/\d+\?.*party_id=\d+/);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(page).toHaveURL(/\/opportunities\?.*party_id=\d+/);
  await expect(chips).toContainText('For: Metro Engineering');

  // A chip removes just its filter; Clear all removes the rest.
  await chips.getByRole('button', { name: /Remove filter: For: Metro Engineering/ }).click();
  await expect(page).not.toHaveURL(/party_id=/);
  await expect(chips).toContainText('Outcome: All outcomes');
  await chips.getByRole('button', { name: 'Clear all' }).click();
  await expect(chips).toHaveCount(0);

  // Every supported sort stays reachable even though Created is a hidden-by-default column.
  await expect(page.getByRole('columnheader', { name: /Created/ })).toHaveCount(0);
  await page.close();
});

async function createDeal(page: Page, title: string) {
  await page.goto('/opportunities/new');
  await page.getByLabel('Title').fill(title);
  await pickParty(page, 'Metro', /Metro Engineering/);
  await page.getByTestId('opportunity-save').click();
  await expect(page).toHaveURL(/\/opportunities\/\d+/);
}

test('deals are ticked on the list and changed together, each by its own request, with a printed list and a printed record', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  const stamp = Date.now().toString(36);
  await createDeal(page, `E2E bulk A ${stamp}`);
  await createDeal(page, `E2E bulk B ${stamp}`);

  // The record can be printed, and shows every fact it holds — including the ones that are not set.
  await expect(page.getByTestId('opportunity-print')).toBeVisible();
  const detail = page.getByTestId('opportunity-detail');
  for (const label of ['Expected value', 'Expected close', 'Next action', 'Created', 'Last updated']) await expect(detail).toContainText(label);
  await expect(detail).toContainText('Not set');

  await page.goto(`/opportunities?filter.search=${stamp}`);
  await expect(page.getByRole('row', { name: new RegExp(`E2E bulk A ${stamp}`) })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Print', exact: true }).first()).toBeVisible();
  await expect(page.getByTestId('opportunity-bulk-bar')).toHaveCount(0);

  // Ticking rows shows the bulk bar with a count; changing the filters drops the selection.
  await page.getByRole('checkbox', { name: 'Select all' }).check();
  const bar = page.getByTestId('opportunity-bulk-bar');
  await expect(bar.getByTestId('bulk-count')).toHaveText('2 selected');
  await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
  await page.getByRole('combobox', { name: 'Outcome' }).click();
  await page.getByRole('option', { name: 'All outcomes' }).click();
  await expect(bar).toHaveCount(0);

  // Move both to another stage.
  await page.getByRole('checkbox', { name: 'Select all' }).check();
  await bar.getByTestId('bulk-stage').click();
  const dialog = page.getByTestId('bulk-dialog');
  await expect(dialog).toContainText('2 open opportunities');
  await dialog.getByRole('combobox', { name: 'Stage' }).click();
  await page.getByRole('option').last().click();
  await dialog.getByTestId('bulk-confirm').click();
  await expect(dialog.getByTestId('bulk-result')).toContainText('2 changed');
  await dialog.getByRole('button', { name: 'Done' }).click();

  // Close both with one reason; closed deals are then not offered a change.
  await bar.getByTestId('bulk-close').click();
  await expect(dialog.getByTestId('bulk-confirm')).toBeDisabled();
  await dialog.getByLabel('Why?').fill('E2E bulk clean-up');
  await dialog.getByTestId('bulk-confirm').click();
  await expect(dialog.getByTestId('bulk-result')).toContainText('2 changed');
  await dialog.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByRole('row', { name: new RegExp(`E2E bulk A ${stamp}`) })).toContainText('Lost');
  await expect(bar.getByTestId('bulk-close')).toHaveCount(0);
  await page.close();
});

test('an open deal is edited in the same side panel as a new one, and its follow-up can be updated quickly', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  const stamp = Date.now().toString(36);
  await createDeal(page, `E2E edit ${stamp}`);

  await page.getByTestId('opportunity-edit').click();
  await expect(page).toHaveURL(/\/opportunities\/\d+\/edit/);
  await expect(page.getByRole('dialog')).toContainText('Edit opportunity');
  // The party and owner are not asked again; they change through their own actions.
  await expect(page.getByRole('combobox', { name: 'Trading partner' })).toHaveCount(0);
  await page.getByLabel('Title').fill(`E2E edited ${stamp}`);
  await page.getByTestId('opportunity-save').click();
  await expect(page).toHaveURL(/\/opportunities\/\d+$/);
  await expect(page.getByRole('dialog')).toContainText(`E2E edited ${stamp}`);

  // Quick update: only the follow-up, in a small dialog over the record.
  await page.getByTestId('opportunity-quick-edit').click();
  const quick = page.getByTestId('edit-dialog');
  await expect(quick.getByLabel('Title')).toHaveCount(0);
  await quick.getByLabel('Next action', { exact: true }).fill('Call back about the quote');
  await quick.getByTestId('edit-save').click();
  await expect(page.getByTestId('opportunity-detail')).toContainText('Call back about the quote');
  await page.close();
});

test('enquiries have filters, chips, selection with bulk actions and printing like the pipeline', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  const stamp = Date.now().toString(36);
  for (const letter of ['A', 'B']) {
    await page.goto('/leads/new');
    await expect(page.getByRole('dialog')).toContainText('Record an enquiry');
    await page.getByLabel('Who is asking?').fill(`E2E enquirer ${letter} ${stamp}`);
    await page.getByTestId('lead-save').click();
    await expect(page.getByTestId('lead-detail')).toContainText(`E2E enquirer ${letter} ${stamp}`);
    // Every fact the enquiry holds is shown, including the ones not provided; the record can be printed.
    for (const label of ['Email', 'Phone', 'Source', 'Recorded', 'What they want']) await expect(page.getByTestId('lead-detail')).toContainText(label);
    await expect(page.getByTestId('lead-print')).toBeVisible();
  }

  await page.goto(`/leads?filter.search=${stamp}`);
  await expect(page.getByRole('row', { name: new RegExp(`E2E enquirer A ${stamp}`) })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Print', exact: true }).first()).toBeVisible();

  // Filters show as chips that can be removed.
  await page.getByRole('combobox', { name: 'Status' }).click();
  await page.getByRole('option', { name: 'New' }).click();
  await expect(page.getByTestId('active-filters')).toContainText('Status: New');
  await page.getByRole('button', { name: /Remove filter: Status/ }).click();
  await expect(page.getByTestId('active-filters')).not.toContainText('Status: New');

  // Tick both, qualify them together.
  await page.getByRole('checkbox', { name: 'Select all' }).check();
  const bar = page.getByTestId('lead-bulk-bar');
  await expect(bar.getByTestId('bulk-count')).toHaveText('2 selected');
  await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
  await bar.getByTestId('bulk-qualify').click();
  const dialog = page.getByTestId('bulk-dialog');
  await expect(dialog).toContainText('2 new enquiries');
  await dialog.getByTestId('bulk-confirm').click();
  await expect(dialog.getByTestId('bulk-result')).toContainText('2 changed');
  await dialog.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByRole('row', { name: new RegExp(`E2E enquirer A ${stamp}`) })).toContainText('Qualified');
  // Qualified ones are no longer offered "Qualify"; they can be disqualified.
  await expect(bar.getByTestId('bulk-qualify')).toHaveCount(0);
  await expect(bar.getByTestId('bulk-disqualify')).toBeVisible();
  await page.close();
});

test('opening and closing a side panel leaves the list where it was; a real page change starts at the top', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await as(browser, MANAGER);
  await page.setViewportSize({ width: 1360, height: 700 });
  await page.goto('/opportunities?filter.outcome=ALL&per_page=25');
  await expect(page.getByRole('row').nth(20)).toBeVisible();
  const main = page.locator('main');
  const scrollTop = () => main.evaluate((element) => element.scrollTop);

  await main.evaluate((element) => element.scrollTo(0, element.scrollHeight));
  await expect.poll(scrollTop).toBeGreaterThan(200);
  const before = await scrollTop();

  // Open a row from the bottom of the list: the page behind stays put.
  await page.getByRole('row').last().click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.waitForTimeout(400);
  expect(Math.abs((await scrollTop()) - before)).toBeLessThan(5);

  // Close it: still where it was.
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.waitForTimeout(400);
  expect(Math.abs((await scrollTop()) - before)).toBeLessThan(5);

  // Going to another screen still starts at the top.
  await page.getByRole('link', { name: 'Enquiries' }).first().click();
  await expect(page.getByTestId('leads-list')).toBeVisible();
  await expect.poll(scrollTop).toBe(0);
  await page.close();
});

test('the pipeline filters are in a bottom sheet on a phone', async ({ browser }) => {
  const page = await as(browser, MANAGER);
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto('/opportunities');
  await page.getByRole('button', { name: 'Filters' }).click();
  const sheet = page.getByRole('dialog', { name: 'Filters' });
  await expect(sheet.getByRole('combobox', { name: 'Trading partner' })).toBeVisible();
  await expect(sheet.getByRole('combobox', { name: 'Owner' })).toBeVisible();
  await sheet.getByRole('button', { name: 'Mine' }).click();
  await sheet.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByTestId('active-filters')).toContainText('Mine only');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  await page.close();
});

test('the screens fit a phone and have no serious accessibility problems in light and dark', async ({ browser }) => {
  test.setTimeout(240_000);
  for (const mode of ['light', 'dark'] as const) {
    const page = await as(browser, MANAGER);
    await page.addInitScript((appearance) => localStorage.setItem('erp.appearance', JSON.stringify(appearance)), { mode, theme: 'erp-blue' });
    await page.setViewportSize({ width: 390, height: 800 });
    for (const [path, testid] of [['/opportunities', 'opportunities-list'], ['/opportunities/new', 'opportunity-form'], ['/leads', 'leads-list'], ['/leads/new', 'lead-form'], ['/sales-stages', 'sales-stages']] as const) {
      await page.goto(path);
      await expect(page.getByTestId(testid)).toBeVisible();
      await page.waitForLoadState('networkidle');
      expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), `${path} scrolls sideways on a phone (${mode})`).toBeLessThanOrEqual(0);
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
      expect(serious, `${path} (${mode}): ${JSON.stringify(serious, null, 2)}`).toEqual([]);
    }
    await page.goto('/opportunities?filter.search=Plant%20room');
    await page.getByRole('row', { name: /Plant room refurbishment/ }).or(page.getByText('Plant room refurbishment')).filter({ visible: true }).first().click();
    await expect(page.getByTestId('opportunity-detail')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    const detail = await new AxeBuilder({ page }).analyze();
    expect(detail.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious'), `detail (${mode})`).toEqual([]);
    // A closed deal also shows the recorded-responsibility section.
    await page.goto('/opportunities?filter.search=Warehouse%20floor&filter.outcome=LOST');
    await page.getByRole('row', { name: /Warehouse floor coating/ }).or(page.getByText('Warehouse floor coating')).filter({ visible: true }).first().click();
    await expect(page.getByTestId('responsibility-record')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    const closed = await new AxeBuilder({ page }).analyze();
    expect(closed.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious'), `closed detail (${mode})`).toEqual([]);
    await page.close();
  }
});

test('a reviewer corrects who the record says was responsible on a closed deal, and nobody else is offered it', async ({ browser }) => {
  test.setTimeout(180_000);
  const stamp = Date.now().toString(36);
  const manager = await as(browser, MANAGER);
  const party = await findByCode<{ id: number; code: string }>(manager, '/parties', 'cus-alpha');
  expect(party, 'the demo partner must be seeded').toBeTruthy();
  const created = await api<{ data: { id: number } }>(manager, 'POST', '/opportunities', { title: `E2E attribution ${stamp}`, party_id: party!.id });
  await api(manager, 'POST', `/opportunities/${created.data.id}/close`, { outcome: 'LOST', reason: 'E2E closed deal' });
  const url = `/opportunities/${created.data.id}`;

  // Closing and managing a deal confer no right to correct who was responsible.
  await manager.goto(url);
  await expect(manager.getByTestId('responsibility-record')).toBeVisible();
  await expect(manager.getByTestId('attribution-correct')).toHaveCount(0);
  await manager.close();

  const page = await as(browser, REVIEWER);
  await page.goto(url);
  await expect(page.getByTestId('responsibility-fact')).toHaveCount(1);
  for (const control of ['opportunity-edit', 'opportunity-reassign', 'opportunity-reopen', 'note-add']) await expect(page.getByTestId(control)).toHaveCount(0);

  await page.getByTestId('attribution-correct').click();
  await page.getByLabel('Which entry is wrong?').click();
  await page.getByRole('option', { name: /Created for/ }).click();
  await page.getByRole('combobox', { name: 'Member' }).click();
  await page.locator('[cmdk-input]').fill('Demo Sales Rep');
  await page.getByRole('option', { name: /Demo Sales Rep/ }).first().click();
  await expect(page.getByTestId('attribution-confirm')).toBeDisabled();
  await page.getByLabel('Why is this being corrected?').fill('It was entered under the wrong person');
  await page.getByTestId('attribution-confirm').click();

  await expect(page.getByTestId('responsibility-corrected')).toContainText('Corrected to Demo Sales Rep');
  await expect(page.getByTestId('attribution-chain')).toContainText('wrong person');
  // The deal itself is unchanged: still closed, owner still the member recorded today.
  await expect(page.getByTestId('opportunity-detail')).toContainText('Lost');
  await page.close();
});
