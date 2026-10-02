import { expect, test } from '@playwright/test';

test('resolution tester searches item and work unit beyond the first lookup page', async ({ page }) => {
  await page.goto('/application-rates');
  await page.getByRole('button', { name: 'Test resolution' }).click();

  await page.getByRole('combobox', { name: 'Item', exact: true }).first().click();
  const itemsSearch = page.waitForResponse((response) =>
    response.url().includes('/v1/items') &&
    new URL(response.url()).searchParams.get('filter[search]') === 'demo-paint-white' &&
    response.status() === 200,
  );
  await page.locator('[cmdk-input]').fill('demo-paint-white');
  await itemsSearch;
  await page.getByRole('option', { name: /demo-paint-white/ }).first().click();
  await expect(page.getByRole('combobox', { name: 'Item', exact: true }).first()).toContainText('demo-paint-white');

  await page.getByRole('combobox', { name: 'Work unit', exact: true }).first().click();
  const uomsSearch = page.waitForResponse((response) =>
    response.url().includes('/v1/uoms') &&
    new URL(response.url()).searchParams.get('filter[search]') === 'Square metre' &&
    response.status() === 200,
  );
  await page.locator('[cmdk-input]').fill('Square metre');
  await uomsSearch;
  await page.getByRole('option', { name: /Square metre/ }).first().click();
  await expect(page.getByRole('combobox', { name: 'Work unit', exact: true }).first()).toContainText('Square metre');

  await page.getByRole('button', { name: 'Resolve', exact: true }).click();
  await expect(page.getByText('Fell back to the default rate')).toBeVisible();
});
