import { expect, test } from '@playwright/test';

test('quantity-only Inventory print tables omit valuation columns', async ({ page }) => {
  // Only the effective-permission response is narrowed. Backend resource tests
  // cover the real API projection; this catches a print layout bypassing the
  // screen's filtered column definitions even if the session can fetch costs.
  await page.route('**/api/v1/user', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.permissions = body.data.permissions.filter((permission: string) => permission !== 'inventory.valuation.view');
    await route.fulfill({ response, body: JSON.stringify(body) });
  });

  await page.goto('/stock-moves');
  await expect(page.getByRole('heading', { name: 'Stock Ledger' })).toBeVisible();
  await expect(page.locator('.hidden table th', { hasText: 'Unit cost' })).toHaveCount(0);

  await page.goto('/inventory-overview');
  await expect(page.getByRole('heading', { name: 'Inventory Overview' })).toBeVisible();
  await expect(page.locator('.hidden table th', { hasText: 'On-hand value' })).toHaveCount(0);
  await expect(page.locator('.hidden table th', { hasText: 'Available value' })).toHaveCount(0);
});

test('a valuation viewer has the cost columns in the print tables', async ({ page }) => {
  await page.goto('/stock-moves');
  await expect(page.getByRole('heading', { name: 'Stock Ledger' })).toBeVisible();
  await expect(page.locator('.hidden table th', { hasText: 'Unit cost' })).toHaveCount(1);

  await page.goto('/inventory-overview');
  await expect(page.getByRole('heading', { name: 'Inventory Overview' })).toBeVisible();
  await expect(page.locator('.hidden table th', { hasText: 'On-hand value' })).toHaveCount(1);
  await expect(page.locator('.hidden table th', { hasText: 'Available value' })).toHaveCount(1);
});
