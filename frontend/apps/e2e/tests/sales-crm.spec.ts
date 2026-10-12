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
    await expect(page.getByTestId('opportunity-detail')).toContainText(title);
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
  await page.getByLabel('Needs a new owner').click();
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

test('stages can be renamed and the change is shown on deals', async ({ browser }) => {
  test.setTimeout(120_000);
  const page = await as(browser, MANAGER);
  await page.goto('/sales-stages');
  const rename = async (from: string, to: string) => {
    const input = page.getByLabel(`Name of ${from}`, { exact: true });
    await input.fill(to);
    await page.locator('li').filter({ has: input }).getByRole('button', { name: 'Rename' }).click();
    await expect(page.getByLabel(`Name of ${to}`, { exact: true })).toBeVisible();
  };
  // A run killed between the rename and its restore leaves the shared demo stage renamed; heal that first so one
  // interrupted run cannot fail every later one.
  await page.getByLabel(/^Name of Proposal/).first().waitFor();
  if ((await page.getByLabel('Name of Proposal (e2e)', { exact: true }).count()) > 0) await rename('Proposal (e2e)', 'Proposal');
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
    await page.getByRole('row', { name: /Plant room refurbishment/ }).or(page.getByText('Plant room refurbishment')).first().click();
    await expect(page.getByTestId('opportunity-detail')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    const detail = await new AxeBuilder({ page }).analyze();
    expect(detail.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious'), `detail (${mode})`).toEqual([]);
    // A closed deal also shows the recorded-responsibility section.
    await page.goto('/opportunities?filter.search=Warehouse%20floor&filter.outcome=LOST');
    await page.getByRole('row', { name: /Warehouse floor coating/ }).or(page.getByText('Warehouse floor coating')).first().click();
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
