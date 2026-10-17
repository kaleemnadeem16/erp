import { expect, test } from '@playwright/test';

test.describe('Inventory Overview Screen Standards and Behavior', () => {
  test('renders standard screen template, interactive KPI cards, and data table', async ({ page }) => {
    await page.goto('/inventory-overview');

    // Standard screen template & headings
    await expect(page.locator('.erp-screen')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Inventory Overview' })).toBeVisible();

    // Verify presence of interactive KPI summary cards
    await expect(page.getByText('Total items')).toBeVisible();
    await expect(page.getByText('Items with shortage')).toBeVisible();
    await expect(page.getByText('Items QA-held')).toBeVisible();
    await expect(page.getByText('Items on order')).toBeVisible();

    // Verify search input
    const searchInput = page.getByRole('textbox', { name: 'Search items' });
    await expect(searchInput).toBeVisible();

    // Verify table has rendered
    const table = page.getByRole('table').first();
    await expect(table).toBeVisible();
  });

  test('clicking KPI card filters table and toggles filter state', async ({ page }) => {
    await page.goto('/inventory-overview');

    // Click "Items with shortage" card
    const shortageCard = page.locator('div[role="button"]').filter({ hasText: 'Items with shortage' });
    await expect(shortageCard).toBeVisible();
    await shortageCard.click();

    // Verifies URL updated
    await expect(page).toHaveURL(/stock_status=shortage/);

    // Active chip is displayed
    const statusChip = page.locator('.ui-chip-active').filter({ hasText: 'Status: Shortage items' });
    await expect(statusChip).toBeVisible();

    // Click "Total items" card to reset status filter
    const totalCard = page.locator('div[role="button"]').filter({ hasText: 'Total items' });
    await totalCard.click();

    await expect(page).not.toHaveURL(/stock_status=shortage/);
    await expect(statusChip).toHaveCount(0);
  });

  test('filter controls update URL and display active chips', async ({ page }) => {
    await page.goto('/inventory-overview');

    // Filter by Item Type
    const typeTrigger = page.getByRole('combobox', { name: 'Item type' });
    await expect(typeTrigger).toBeVisible();
    await typeTrigger.click();
    await page.getByRole('option', { name: 'Raw Material' }).click();

    await expect(page).toHaveURL(/type=RAW_MATERIAL/);
    const typeChip = page.locator('.ui-chip-active').filter({ hasText: 'Type: Raw Material' });
    await expect(typeChip).toBeVisible();

    // Filter by Valuation Method
    const valTrigger = page.getByRole('combobox', { name: 'Valuation method' });
    await expect(valTrigger).toBeVisible();
    await valTrigger.click();
    await page.getByRole('option', { name: 'AVCO' }).click();

    await expect(page).toHaveURL(/valuation_method=AVCO/);
    const valChip = page.locator('.ui-chip-active').filter({ hasText: 'Valuation: AVCO' });
    await expect(valChip).toBeVisible();

    // Clear all filters button resets chips
    const clearAllBtn = page.getByRole('button', { name: 'Clear all' });
    await expect(clearAllBtn).toBeVisible();
    await clearAllBtn.click();

    await expect(typeChip).toHaveCount(0);
    await expect(valChip).toHaveCount(0);
  });

  test('row selection displays InventoryOverviewBulkBar with actions', async ({ page }) => {
    await page.goto('/inventory-overview');

    // Select first row checkbox
    const firstCheckbox = page.getByRole('checkbox', { name: 'Select row' }).first();
    await expect(firstCheckbox).toBeVisible();
    await firstCheckbox.check();

    // Bulk action bar is visible
    const bulkBar = page.getByRole('region', { name: 'Bulk actions' });
    await expect(bulkBar).toBeVisible();
    await expect(bulkBar.getByText(/1 item selected/i)).toBeVisible();
    await expect(bulkBar.getByRole('button', { name: 'Print selected' })).toBeVisible();

    // Clear selection
    await bulkBar.getByRole('button', { name: 'Clear selection' }).click();
    await expect(bulkBar).toHaveCount(0);
    await expect(firstCheckbox).not.toBeChecked();
  });

  test('clicking item row opens detail RecordSheet with metrics and deep links', async ({ page }) => {
    await page.goto('/inventory-overview');

    // Click on the first item record link
    const firstRowLink = page.locator('.ui-order-link').first();
    await expect(firstRowLink).toBeVisible();
    await firstRowLink.click();

    // Verify RecordSheet dialog opened
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    // Verify key metric sections in sheet
    await expect(sheet.getByText('On hand', { exact: true })).toBeVisible();
    await expect(sheet.getByText('Available', { exact: true })).toBeVisible();
    await expect(sheet.getByText('Item Specifications')).toBeVisible();

    // Verify related operational links
    const ledgerLink = sheet.getByRole('link', { name: /View Stock Ledger/i });
    await expect(ledgerLink).toBeVisible();

    const itemMasterLink = sheet.getByRole('link', { name: /View Item Master Record/i });
    await expect(itemMasterLink).toBeVisible();

    const countsLink = sheet.getByRole('link', { name: /View Physical Counts/i });
    await expect(countsLink).toBeVisible();
  });

  test('mobile phone viewport has no horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/inventory-overview');

    await expect(page.getByRole('heading', { name: 'Inventory Overview' })).toBeVisible();

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);
  });
});
