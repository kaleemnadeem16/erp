import { expect, test } from '@playwright/test';

const category = {
  id: 880084,
  code: 'Q34-CATEGORY',
  name: 'Q34 category',
  parent_id: null,
  default_valuation_method: null,
  income_account_id: 84,
  expense_account_id: 84,
  inventory_account_id: 84,
  parent: null,
  children: [],
  created_at: null,
  updated_at: null,
};

async function setup(page: import('@playwright/test').Page, finance: 'none' | 'read' | 'manage') {
  await page.route('**/api/v1/user', async (route) => {
    // A navigation can abort this request while the handler is still reading it ("Response has been disposed"); the
    // next page load makes the request again, so an aborted one is simply dropped rather than failing the test.
    try {
      const response = await route.fetch();
      const body = await response.json();
      body.data.permissions = body.data.permissions.filter((p: string) => !p.startsWith('accounting.account-mapping.'));
      body.data.permissions.push('inventory.category.manage');
      if (finance !== 'none')
        body.data.permissions.push(`accounting.account-mapping.${finance === 'read' ? 'view' : 'manage'}`);
      await route.fulfill({ response, body: JSON.stringify(body) });
    } catch {
      await route.abort().catch(() => undefined);
    }
  });
  // Deliberately return mappings even to a restricted browser: API tests prove omission,
  // these assertions ensure controls and the hidden print tree also honour permissions.
  await page.route('**/api/v1/categories/880084**', (route) => route.fulfill({ json: { data: category } }));
  await page.route('**/api/v1/chart-of-accounts?**', (route) =>
    route.fulfill({
      json: {
        data: [{ id: 84, code: 'GL84', name: 'Restricted finance account' }],
      },
    }),
  );
}

test('ordinary category maintainers do not see mappings in detail, print or edit', async ({ page }) => {
  await setup(page, 'none');
  await page.goto('/categories/880084');
  await expect(page.getByRole('heading', { name: 'Q34 category', exact: true })).toBeVisible();
  await expect(page.getByText('GL account defaults', { exact: true })).toHaveCount(0);
  await expect(page.locator('div.hidden').filter({ hasText: 'CATEGORY: Q34-CATEGORY' })).not.toContainText(
    'Restricted finance account',
  );
  await page.goto('/categories/880084/edit');
  await expect(page.getByRole('heading', { name: 'Edit Q34 category' })).toBeVisible();
  await expect(page.getByText('GL account defaults', { exact: true })).toHaveCount(0);
  let submitted: Record<string, unknown> | undefined;
  await page.route('**/api/v1/categories/880084', async (route) => {
    if (route.request().method() === 'PATCH') {
      submitted = route.request().postDataJSON();
      await route.fulfill({ json: { data: category } });
    } else await route.fulfill({ json: { data: category } });
  });
  await page.getByRole('button', { name: 'Save category' }).click();
  await expect.poll(() => submitted).toBeDefined();
  expect(submitted).not.toHaveProperty('income_account_id');
  expect(submitted).not.toHaveProperty('expense_account_id');
  expect(submitted).not.toHaveProperty('inventory_account_id');
});

test('a mapping reader sees detail and print evidence but cannot edit financial fields', async ({ page }) => {
  await setup(page, 'read');
  await page.goto('/categories/880084');
  await expect(page.getByRole('heading', { name: 'Q34 category', exact: true })).toBeVisible();
  await expect(page.getByText('GL84 — Restricted finance account').first()).toBeVisible();
  await expect(page.locator('div.hidden').filter({ hasText: 'CATEGORY: Q34-CATEGORY' })).toContainText(
    'GL84 — Restricted finance account',
  );
  await page.goto('/categories/880084/edit');
  await expect(page.getByRole('heading', { name: 'Edit Q34 category' })).toBeVisible();
  await expect(page.getByText('GL account defaults', { exact: true })).toHaveCount(0);
});

test('a mapping manager can see the financial form fields', async ({ page }) => {
  await setup(page, 'manage');
  await page.goto('/categories/880084/edit');
  await expect(page.getByRole('heading', { name: 'Edit Q34 category' })).toBeVisible();
  await expect(page.getByText('GL account defaults', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Income account')).toBeVisible();
});
