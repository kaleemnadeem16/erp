import { expect, test } from '@playwright/test';

/**
 * A location's code is unique only within its warehouse, and both demo warehouses code their
 * location `stock` — so a screen that labelled a location by its code alone showed every transfer
 * between them as "stock → stock" and offered two indistinguishable "stock" options in a picker.
 * Every screen now labels a location `warehouse / location` through one shared formatter.
 */
test('a transfer in the stock ledger names the warehouse at each end', async ({ page }) => {
  await page.goto('/stock-moves');
  await expect(page.getByRole('heading', { name: /Stock Ledger/i }).first()).toBeVisible();

  // Newer movements (other specs, manual work) can push the seeded transfer off the first page, so find it by its item.
  await page.getByPlaceholder('Search item…').fill('demo-xfer-emulsion');

  // StockTransfersDemoSeeder: 25 kg of Matt Emulsion Base from Main Stock to Karachi Stock.
  const transfer = page.locator('tbody tr', { hasText: 'demo-xfer-emulsion' }).filter({ hasText: 'DEMO_TRANSFER #1' }).first();
  await expect(transfer).toContainText('demo-main / stock');
  await expect(transfer).toContainText('demo-khi / stock');
});

test('a location picker offers the two identically coded locations as distinct options', async ({ page }) => {
  await page.goto('/negative-stock-overrides/new');
  await expect(page.getByRole('heading', { name: /New|Negative-Stock/i }).first()).toBeVisible();

  await page.getByLabel('Location', { exact: true }).click();

  await expect(page.getByRole('option', { name: 'demo-main / stock' })).toHaveCount(1);
  await expect(page.getByRole('option', { name: 'demo-khi / stock' })).toHaveCount(1);
});
