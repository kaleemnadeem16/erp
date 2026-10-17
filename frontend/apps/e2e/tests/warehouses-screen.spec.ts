import { expect, test } from '@playwright/test';

test.describe('Warehouses Screen Standards and Behavior', () => {
  test('warehouses list renders with standard screen template, search, and active chips', async ({ page }) => {
    await page.goto('/warehouses');

    // 1. Check screen template layout and header
    const screen = page.locator('[data-screen-template="list"]');
    await expect(screen).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Warehouses', exact: true })).toBeVisible();

    // 2. Search input interaction
    const searchInput = page.getByRole('textbox', { name: 'Search warehouses' });
    await expect(searchInput).toBeVisible();
    await searchInput.fill('khi');

    // Debounced search updates URL and shows active chip
    await expect(page).toHaveURL(/filter%5Bsearch%5D=khi|search=khi/);
    const chip = page.locator('.ui-chip-active').filter({ hasText: 'Search: "khi"' });
    await expect(chip).toBeVisible();

    // 3. Clear search chip
    await chip.getByRole('button', { name: /Remove filter/i }).click();
    await expect(chip).toHaveCount(0);
    await expect(searchInput).toHaveValue('');
  });

  test('opening new warehouse opens RecordSheet over list and cancel restores list', async ({ page }) => {
    await page.goto('/warehouses');

    const newBtn = page.getByRole('button', { name: 'New warehouse' });
    await expect(newBtn).toBeVisible();
    await newBtn.click();

    await expect(page).toHaveURL(/\/warehouses\/new/);
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'New warehouse' })).toBeVisible();

    // Form inputs exist within SectionStack
    await expect(sheet.getByLabel('Code', { exact: true })).toBeVisible();
    await expect(sheet.getByLabel('Name', { exact: true })).toBeVisible();
    await expect(sheet.getByLabel('Location code')).toBeVisible();
    await expect(sheet.getByLabel('Location name')).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Create warehouse' })).toBeVisible();

    // Close button closes the sheet and returns to list
    const closeBtn = sheet.getByRole('button', { name: 'Close' });
    await expect(closeBtn).toBeVisible();
    await closeBtn.click();

    await expect(page).toHaveURL(/\/warehouses$/);
    await expect(sheet).toHaveCount(0);
  });

  test('clicking a warehouse row opens detail sheet with actions and archive dialog', async ({ page }) => {
    await page.goto('/warehouses');

    const table = page.locator('.erp-screen table').first();
    await expect(table).toBeVisible();

    const firstRowLink = page.locator('.ui-order-link').first();
    await expect(firstRowLink).toBeVisible();
    await firstRowLink.click();

    await expect(page).toHaveURL(/\/warehouses\/\d+/);
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    // Verify detail fields
    await expect(sheet.getByRole('heading', { name: 'General' })).toBeVisible();
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Default warehouse' })).toBeVisible();
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Locations count' })).toBeVisible();

    // Action buttons: Edit and Archive
    const editBtn = sheet.getByRole('link', { name: 'Edit' });
    await expect(editBtn).toBeVisible();

    const archiveBtn = sheet.getByRole('button', { name: 'Archive' });
    if (await archiveBtn.isVisible()) {
      // Open archive dialog
      await archiveBtn.click();
      const archiveDialog = page.getByRole('dialog').filter({ hasText: 'Archive warehouse' });
      await expect(archiveDialog).toBeVisible();
      await expect(archiveDialog.getByText(/Are you sure you want to archive warehouse/i)).toBeVisible();

      // Cancel archiving
      await archiveDialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(archiveDialog).toHaveCount(0);
    }
  });

  test('mobile phone width layout has no horizontal overflow and renders cleanly', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/warehouses');

    // Check no horizontal scrollbar overflow
    const hasHorizontalOverflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });
    expect(hasHorizontalOverflow).toBe(false);

    // Mobile filter button exists (accessible name is from aria-label)
    const filterBtn = page.getByRole('button', { name: /filter/i });
    await expect(filterBtn).toBeVisible();

    // Mobile FAB for creation
    const fab = page.getByRole('button', { name: 'New warehouse' });
    await expect(fab).toBeVisible();
  });

  test('row selection checkbox displays WarehouseBulkBar with bulk actions and clear', async ({ page }) => {
    await page.goto('/warehouses');

    // 1. Table has row selection checkboxes
    const firstCheckbox = page.getByRole('checkbox', { name: 'Select row' }).first();
    await expect(firstCheckbox).toBeVisible();
    await firstCheckbox.click();

    // 2. WarehouseBulkBar appears with count and actions
    const bulkBar = page.getByTestId('warehouse-bulk-bar');
    await expect(bulkBar).toBeVisible();
    await expect(page.getByTestId('bulk-count')).toHaveText('1 selected');
    await expect(bulkBar.getByRole('button', { name: 'Print selected' })).toBeVisible();

    // 3. Clear selection button clears the bar
    await bulkBar.getByRole('button', { name: 'Clear selection' }).click();
    await expect(bulkBar).toHaveCount(0);
    await expect(firstCheckbox).not.toBeChecked();
  });

  test('filters for status, default role, and locations update URL and show active chips', async ({ page }) => {
    await page.goto('/warehouses');

    // Status filter select
    const statusTrigger = page.getByRole('combobox', { name: 'Status' });
    await expect(statusTrigger).toBeVisible();
    await statusTrigger.click();
    await page.getByRole('option', { name: 'Active', exact: true }).click();

    await expect(page).toHaveURL(/active=true/);
    const statusChip = page.locator('.ui-chip-active').filter({ hasText: 'Status: Active' });
    await expect(statusChip).toBeVisible();

    // Default role filter select
    const roleTrigger = page.getByRole('combobox', { name: 'Default role' });
    await expect(roleTrigger).toBeVisible();
    await roleTrigger.click();
    await page.getByRole('option', { name: 'Default only' }).click();

    await expect(page).toHaveURL(/is_default=true/);
    const roleChip = page.locator('.ui-chip-active').filter({ hasText: 'Role: Default only' });
    await expect(roleChip).toBeVisible();

    // Clear all filters
    await page.getByRole('button', { name: 'Clear all' }).click();
    await expect(statusChip).toHaveCount(0);
    await expect(roleChip).toHaveCount(0);
  });

  test('detail screen displays general section, locations panel, and inventory deep link', async ({ page }) => {
    await page.goto('/warehouses');

    const firstRowLink = page.locator('.ui-order-link').first();
    await expect(firstRowLink).toBeVisible();
    await firstRowLink.click();

    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    // Verify enhanced detail fields
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Default warehouse' })).toBeVisible();
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Locations count' })).toBeVisible();

    // Verify Locations section header
    await expect(sheet.getByRole('heading', { name: 'Locations' })).toBeVisible();

    // Verify Inventory operations section and deep link
    const inventorySection = sheet.getByRole('heading', { name: 'Inventory operations' });
    await expect(inventorySection).toBeVisible();
    const deepLink = sheet.getByRole('link', { name: /View physical counts in this warehouse/i });
    await expect(deepLink).toBeVisible();
  });

  test('warehouse create form validates required fields', async ({ page }) => {
    await page.goto('/warehouses/new');
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    const submitBtn = sheet.getByRole('button', { name: 'Create warehouse' });
    await submitBtn.click();

    await expect(sheet.getByText('Code is required', { exact: true })).toBeVisible();
    await expect(sheet.getByText('Name is required', { exact: true })).toBeVisible();
    await expect(sheet.getByText('Initial location code is required', { exact: true })).toBeVisible();
    await expect(sheet.getByText('Initial location name is required', { exact: true })).toBeVisible();
  });
});

