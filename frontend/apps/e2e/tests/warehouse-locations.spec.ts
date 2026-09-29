import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { pickSelect } from './support';

/**
 * A structured warehouse's locations (ADR 0041, ADR 0067): the hierarchy with each location's type, whether
 * its stock counts as usable and who decided, and the governed change of that rule. Runs against the
 * structured demo data (`demo-main`); the round trip uses the Production floor, which holds no stock, so no
 * other spec's numbers move.
 */
async function openMainWarehouse(page: Page) {
  await page.goto('/warehouses');
  await page.getByRole('row', { name: /demo-main/ }).click();
  await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible();
}

test('the hierarchy shows each location, its type and whether its stock counts, and by whose decision', async ({ page }) => {
  await openMainWarehouse(page);

  const returns = page.locator('li', { hasText: '(returns)' });
  await expect(returns.getByText('Returns', { exact: true })).toBeVisible();
  await expect(returns.getByText('Counts as usable (marked)')).toBeVisible();

  await expect(page.locator('li', { hasText: '(overflow)' }).getByText('Not counted (marked)')).toBeVisible();
  // Followed from the type, so no "(marked)": a dock counts, a quarantine cage does not.
  await expect(page.locator('li', { hasText: '(recv)' }).getByText('Counts as usable', { exact: true })).toBeVisible();
  await expect(page.locator('li', { hasText: '(quar)' }).getByText('Not counted', { exact: true })).toBeVisible();
  // A grouping location holds nothing.
  await expect(page.locator('li', { hasText: '(zone-a)' }).getByText('Holds no stock')).toBeVisible();

  const results = await new AxeBuilder({ page }).analyze();
  const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
  expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
});

test('changing whether a location counts needs a reason, is applied, and can be put back to follow its type', async ({ page }) => {
  await openMainWarehouse(page);
  const row = page.locator('li', { hasText: '(wip)' });
  await expect(row.getByText('Not counted', { exact: true })).toBeVisible();

  await row.getByRole('button', { name: /Change usable stock rule/ }).click();
  await pickSelect(page, 'Stock in this location', 'Always counts as usable');
  await page.getByRole('button', { name: 'Save rule' }).click();
  await expect(page.getByText(/Say why/)).toBeVisible();

  await page.getByLabel('Why is this changing?').fill('End-to-end check: WIP is fit for use in this test');
  await page.getByRole('button', { name: 'Save rule' }).click();
  await expect(row.getByText('Counts as usable (marked)')).toBeVisible();

  await row.getByRole('button', { name: /Change usable stock rule/ }).click();
  await pickSelect(page, 'Stock in this location', 'Follow the location type (default)');
  await page.getByLabel('Why is this changing?').fill('End-to-end check: back to the default');
  await page.getByRole('button', { name: 'Save rule' }).click();
  await expect(row.getByText('Not counted', { exact: true })).toBeVisible();
});

test('a location can be renamed and the form closes on save', async ({ page }) => {
  await openMainWarehouse(page);
  const row = page.locator('li', { hasText: '(transit)' });
  await row.getByRole('button', { name: /^Edit / }).click();
  await page.getByLabel('Name', { exact: true }).fill('In transit (renamed)');
  await page.getByRole('button', { name: 'Save location' }).click();
  await expect(page.getByRole('form', { name: /Edit/ })).toHaveCount(0);
  await expect(page.locator('li', { hasText: '(transit)' }).locator('.font-medium').first()).toHaveText('In transit (renamed)');

  await page.locator('li', { hasText: '(transit)' }).getByRole('button', { name: /^Edit / }).click();
  await page.getByLabel('Name', { exact: true }).fill('In transit');
  await page.getByRole('button', { name: 'Save location' }).click();
  await expect(page.getByRole('form', { name: /Edit/ })).toHaveCount(0);
  await expect(page.locator('li', { hasText: '(transit)' }).locator('.font-medium').first()).toHaveText('In transit');
});
