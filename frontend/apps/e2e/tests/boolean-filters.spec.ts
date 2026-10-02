import { expect, test, type Page } from '@playwright/test';

/**
 * A generated client serialises a boolean query parameter as "true"/"false", which Laravel's
 * `boolean` rule rejected with a 422 — and a list screen renders a failed request as an empty (or
 * stale) list, so choosing "Active" in a status filter silently showed nothing useful. The API now
 * accepts the string forms (ADR 0060). These specs choose the filter in the UI, where the defect was
 * felt, and check the request itself: what a screen shows after a failed request varies by screen,
 * so the DOM alone is not a reliable witness.
 */
async function chooseActiveStatus(page: Page): Promise<number> {
  const response = page.waitForResponse((r) => decodeURIComponent(r.url()).includes('filter[active]=true'));
  await page.getByRole('combobox').filter({ hasText: 'All statuses' }).click();
  await page.getByRole('option', { name: 'Active', exact: true }).click();

  return (await response).status();
}

test('the Warehouses status filter is accepted and returns the matching warehouses', async ({ page }) => {
  await page.goto('/warehouses');
  await expect(page.getByRole('heading', { name: 'Warehouses' }).first()).toBeVisible();

  expect(await chooseActiveStatus(page)).toBe(200);

  // Both demo warehouses are active. (The page also renders a print copy of the table, so a row
  // can appear more than once — hence "not zero" rather than an exact count.)
  await expect(page.locator('tbody tr', { hasText: 'demo-main' })).not.toHaveCount(0);
  await expect(page.locator('tbody tr', { hasText: 'demo-khi' })).not.toHaveCount(0);
  await expect(page.getByText('0 records')).toHaveCount(0);
});

test('the Item Suppliers status filter is accepted', async ({ page }) => {
  await page.goto('/item-suppliers');
  await expect(page.getByRole('heading', { name: 'Item suppliers' }).first()).toBeVisible();

  expect(await chooseActiveStatus(page)).toBe(200);
});
