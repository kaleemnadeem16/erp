import { expect, test } from '@playwright/test';

test('formulation filters reach the API through URL-backed list state', async ({ page }) => {
  await page.goto('/formulations');
  await expect(page.getByRole('heading', { name: 'Formulations' })).toBeVisible();

  await page.getByLabel('Search formulations').fill('demo-f-paint-white-alpha');
  await expect(page).toHaveURL(/filter.search=demo-f-paint-white-alpha/);
  await expect(page.getByRole('row', { name: /demo-f-paint-white-alpha/ })).toBeVisible();

  await page.getByLabel('Output item', { exact: true }).first().click();
  await page.locator('[cmdk-input]').fill('demo-paint-white');
  await page
    .getByRole('option', { name: /demo-paint-white/ })
    .first()
    .click();
  await expect(page).toHaveURL(/filter.output_item_id=\d+/);

  await page.getByLabel('Customer', { exact: true }).first().click();
  await page.locator('[cmdk-input]').fill('cus-alpha');
  await page
    .getByRole('option', { name: /cus-alpha/ })
    .first()
    .click();
  await expect(page).toHaveURL(/filter.customer_id=\d+/);

  await page.getByLabel('Status', { exact: true }).first().click();
  await page.getByRole('option', { name: 'ACTIVE' }).click();
  await expect(page).toHaveURL(/filter.status=ACTIVE/);
});

test('assembly search, group and status filters are usable', async ({ page }) => {
  await page.goto('/assemblies');
  await expect(page.getByRole('heading', { name: 'Assemblies' })).toBeVisible();

  await page.getByLabel('Search assemblies').fill('demo-asm-wall-paint');
  await expect(page).toHaveURL(/filter.search=demo-asm-wall-paint/);
  await expect(page.getByRole('row', { name: /demo-asm-wall-paint/ })).toBeVisible();

  await page.getByLabel('Assembly group', { exact: true }).first().click();
  await page.locator('[cmdk-input]').fill('demo-labour');
  await page
    .getByRole('option', { name: /demo-labour/ })
    .first()
    .click();
  await expect(page).toHaveURL(/filter.assembly_group_id=\d+/);

  await page.getByLabel('Status', { exact: true }).first().click();
  await page.getByRole('option', { name: 'ACTIVE' }).click();
  await expect(page).toHaveURL(/filter.status=ACTIVE/);
});

test('estimating lists offer mobile cards and a filter sheet', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });

  for (const path of ['/formulations', '/assemblies']) {
    await page.goto(path);
    await expect(page.getByRole('button', { name: 'Card view' })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Filters' }).click();
    await expect(page.getByRole('dialog', { name: 'Filters' })).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'Filters' }).getByLabel('Status')).toBeVisible();
    await page.getByRole('button', { name: 'Done' }).click();
  }
});

test('rate dimensions and assembly groups search through URL-backed filters', async ({ page }) => {
  for (const { path, label, term } of [
    { path: '/rate-dimensions', label: 'Search rate dimensions', term: 'demo-surface-type' },
    { path: '/assembly-groups', label: 'Search assembly groups', term: 'demo-labour' },
  ]) {
    await page.goto(path);
    await page.getByLabel(label).fill(term);
    await expect(page).toHaveURL(new RegExp(`filter.search=${term}`));
    await expect(page.getByRole('row', { name: new RegExp(term) })).toBeVisible();
  }
});

test('rate dimensions and assembly groups default to cards on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const path of ['/rate-dimensions', '/assembly-groups']) {
    await page.goto(path);
    await expect(page.getByRole('button', { name: 'Card view' })).toHaveAttribute('aria-pressed', 'true');
  }
});
