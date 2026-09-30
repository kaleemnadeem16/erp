import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { pickCombobox, pickSelect } from './support';

/**
 * The company-configured side of ADR 0054: the reason list and the variance rules. Rules are scoped to a
 * dedicated demo item (`demo-neg-hardener`, used by no preview spec) so activating one here cannot change
 * what any other spec sees. Each run leaves one more superseded version on that item — the history the
 * screen exists to keep — and never touches the company-wide rules.
 */
const ITEM = 'demo-neg-hardener';
const REASON_CODE = 'e2e-reason';

async function open(page: Page) {
  await page.goto('/settings/material-variance');
  await expect(page.getByRole('heading', { name: 'Material variance' })).toBeVisible();
}

/** "Start a new version" pre-fills the bands in force, so a repeatable spec clears them and builds its own. */
async function clearBands(page: Page) {
  while ((await page.getByRole('button', { name: 'Remove band 1' }).count()) > 0) {
    await page.getByRole('button', { name: 'Remove band 1' }).click();
  }
}

async function chooseItemScope(page: Page) {
  await pickSelect(page, 'Rules apply to', 'One item');
  await pickCombobox(page, 'Item', ITEM);
  await expect(page.getByRole('heading', { name: new RegExp(`Rules — ${ITEM}`) })).toBeVisible();
}

test('a reason can be added, retired and reinstated, never deleted', async ({ page }) => {
  await open(page);
  const row = page.locator('li', { hasText: `(${REASON_CODE})` });

  if ((await row.count()) === 0) {
    await page.getByRole('button', { name: 'Add a reason' }).click();
    await page.getByLabel('Name', { exact: true }).fill('End-to-end reason');
    await page.getByLabel('Code', { exact: true }).fill(REASON_CODE);
    await page.getByRole('button', { name: 'Add reason' }).click();
  }
  await expect(row).toBeVisible();
  await expect(row.getByRole('button', { name: /Delete/ })).toHaveCount(0);

  await row.getByRole('button', { name: /^Retire/ }).click();
  await expect(row.getByText('Retired')).toBeVisible();
  await row.getByRole('button', { name: /^Reinstate/ }).click();
  await expect(row.getByText('Retired')).toHaveCount(0);
});

test('the band editor refuses what the rules forbid, offers approval only because approvals are on, and never offers a waste option', async ({ page }) => {
  await open(page);
  await chooseItemScope(page);
  await page.getByRole('button', { name: /Set up rules|Start a new version/ }).click();

  await page.getByLabel('Why is this version being set?').fill('Validation check');
  await clearBands(page);
  await page.getByRole('button', { name: 'Add a band' }).click();
  await pickSelect(page, 'Band 1 measure', 'Quantity');
  await page.getByLabel('Band 1 threshold').fill('2');
  await page.getByRole('button', { name: 'Save as draft' }).click();
  await expect(page.getByText('Choose a unit for a quantity')).toBeVisible();

  await pickSelect(page, 'Band 1 measure', '% of the standard quantity');
  await pickSelect(page, 'Band 1 direction', 'Under plan');
  await page.getByLabel('Band 1 threshold').fill('150');
  await page.getByRole('button', { name: 'Save as draft' }).click();
  await expect(page.getByText('An under-plan percentage cannot exceed 100')).toBeVisible();

  // Only what a company may choose today is offered.
  await page.getByLabel('Band 1 effect').click();
  // The demo company has approvals switched on for material variance (ADR 0070), so that effect is on offer; it is not otherwise.
  await expect(page.getByRole('option')).toHaveText(['Record only', 'Reason required', 'Blocked', 'Approval required']);
  await page.keyboard.press('Escape');
  await page.getByLabel('Band 1 measure').click();
  await expect(page.getByRole('option')).toHaveCount(4);
  await expect(page.getByRole('option', { name: /waste/i })).toHaveCount(0);
  await page.keyboard.press('Escape');

  const serious = (await new AxeBuilder({ page }).analyze()).violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
  expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
});

test('a draft is saved, edited and activated, and the overview follows without a reload', async ({ page }) => {
  await open(page);
  await chooseItemScope(page);
  await page.getByRole('button', { name: /Set up rules|Start a new version/ }).click();

  await page.getByLabel('Why is this version being set?').fill('End-to-end rules');
  await clearBands(page);
  await page.getByRole('button', { name: 'Add a band' }).click();
  await page.getByLabel('Band 1 threshold').fill('10');
  await page.getByRole('button', { name: 'Save as draft' }).click();

  const draft = page.locator('div.rounded-md.border.p-3', { hasText: 'Draft — not in force' }).first();
  await expect(draft).toContainText('Over plan by 10% of the standard or more → Reason required');

  await draft.getByRole('button', { name: 'Edit' }).click();
  await page.getByLabel('Band 1 threshold').fill('15');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(draft).toContainText('Over plan by 15% of the standard or more → Reason required');

  // Activating must refresh the page's "Where rules are set" overview, which is a separate query from the
  // panel's own. Asserted as a request, because the DOM alone cannot tell a refresh from a version left by an
  // earlier run already being listed.
  const overviewRefreshed = page.waitForRequest((request) => /filter\[status\]=ACTIVE/.test(decodeURIComponent(request.url())), { timeout: 5_000 });
  await draft.getByRole('button', { name: 'Activate' }).click();
  await overviewRefreshed;
  // The card itself (not an enclosing container, which would also hold older versions' text).
  const activeCard = page.locator('div.rounded-md.border.p-3', { hasText: 'Over plan by 15%' }).filter({ has: page.getByText('Active', { exact: true }) });
  await expect(activeCard).toBeVisible();
  const version = (await activeCard.innerText()).match(/Version (\d+)/)?.[1];
  expect(version).toBeTruthy();
  // The "Where rules are set" overview is a separate query; activating must refresh it to the *new* version
  // (an earlier run's version would already be listed, so the scope alone proves nothing).
  await expect(page.getByRole('listitem').filter({ hasText: `Item ${ITEM}` })).toContainText(`version ${version} `);
});
