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

test('application rates filter by item, work unit and default status', async ({ page }) => {
  await page.goto('/application-rates');
  await expect(page.getByRole('heading', { name: 'Application rates' })).toBeVisible();

  await page.getByLabel('Item', { exact: true }).first().click();
  await page.locator('[cmdk-input]').fill('demo-paint-white');
  await page.getByRole('option', { name: /demo-paint-white/ }).first().click();
  await expect(page).toHaveURL(/filter.item_id=\d+/);

  await page.getByLabel('Work unit', { exact: true }).first().click();
  await page.locator('[cmdk-input]').fill('Square metre');
  await page.getByRole('option', { name: /Square metre/ }).first().click();
  await expect(page).toHaveURL(/filter.work_uom_id=\d+/);

  await page.getByLabel('Default status', { exact: true }).first().click();
  await page.getByRole('option', { name: 'Default only' }).click();
  await expect(page).toHaveURL(/filter.is_default=true/);
  await expect(page.getByRole('row', { name: /demo-paint-white/ })).toHaveCount(1);

  await page.getByLabel('Default status', { exact: true }).first().click();
  await page.getByRole('option', { name: 'Context-specific only' }).click();
  await expect(page).toHaveURL(/filter.is_default=false/);
  await expect(page.getByRole('row', { name: /demo-paint-white/ })).toHaveCount(4);
});

test('application rate filters and cards work on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/application-rates');
  await expect(page.getByRole('button', { name: 'Card view' })).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Filters' }).click();
  const sheet = page.getByRole('dialog', { name: 'Filters' });
  await expect(sheet.getByLabel('Item')).toBeVisible();
  await expect(sheet.getByLabel('Work unit')).toBeVisible();
  await sheet.getByLabel('Default status').click();
  await page.getByRole('option', { name: 'Default only' }).click();
  await expect(page).toHaveURL(/filter.is_default=true/);
});

test('application rate item lookup searches the server and keeps its selected label', async ({ page }) => {
  await page.goto('/application-rates');
  await page.getByLabel('Item', { exact: true }).first().click();
  const searched = page.waitForResponse((response) =>
    response.url().includes('/api/v1/items') &&
    new URL(response.url()).searchParams.get('filter[search]') === 'demo-paint-white' &&
    response.status() === 200,
  );
  await page.locator('[cmdk-input]').fill('demo-paint-white');
  await searched;
  await page.getByRole('option', { name: /demo-paint-white/ }).first().click();
  await expect(page.getByLabel('Item', { exact: true }).first()).toContainText('demo-paint-white');
  await page.reload();
  await expect(page.getByLabel('Item', { exact: true }).first()).toContainText('demo-paint-white');
});

test('application rate work-unit lookup searches the server and keeps its selected label', async ({ page }) => {
  await page.goto('/application-rates');
  await page.getByLabel('Work unit', { exact: true }).first().click();
  const searched = page.waitForResponse((response) =>
    response.url().includes('/api/v1/uoms') &&
    new URL(response.url()).searchParams.get('filter[search]') === 'Square metre' &&
    response.status() === 200,
  );
  await page.locator('[cmdk-input]').fill('Square metre');
  await searched;
  await page.getByRole('option', { name: /Square metre/ }).first().click();
  await expect(page.getByLabel('Work unit', { exact: true }).first()).toContainText('Square metre');
  await page.reload();
  await expect(page.getByLabel('Work unit', { exact: true }).first()).toContainText('Square metre');
});
