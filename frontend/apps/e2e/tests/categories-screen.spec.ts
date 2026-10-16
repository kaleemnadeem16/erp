import { expect, test } from '@playwright/test';

test.describe('Categories Screen Standards and Behavior', () => {
  test('categories list renders with standard screen template, search, and active chips', async ({ page }) => {
    await page.goto('/categories');

    // 1. Check screen template layout and header
    const screen = page.locator('[data-screen-template="list"]');
    await expect(screen).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Categories', exact: true })).toBeVisible();

    // 2. Search input interaction
    const searchInput = page.getByRole('textbox', { name: 'Search categories' });
    await expect(searchInput).toBeVisible();
    await searchInput.fill('raw');

    // Debounced search updates URL and shows active chip
    await expect(page).toHaveURL(/filter%5Bsearch%5D=raw|search=raw/);
    const chip = page.locator('.ui-chip-active').filter({ hasText: 'Search: "raw"' });
    await expect(chip).toBeVisible();

    // 3. Clear search chip
    await chip.getByRole('button', { name: /Remove filter/i }).click();
    await expect(chip).toHaveCount(0);
    await expect(searchInput).toHaveValue('');
  });

  test('opening new category opens RecordSheet over list and cancel restores list', async ({ page }) => {
    await page.goto('/categories');

    const newBtn = page.getByRole('button', { name: 'New category' });
    await expect(newBtn).toBeVisible();
    await newBtn.click();

    await expect(page).toHaveURL(/\/categories\/new/);
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'New category' })).toBeVisible();

    // Form inputs exist within SectionStack
    await expect(sheet.getByLabel('Code')).toBeVisible();
    await expect(sheet.getByLabel('Name')).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Create category' })).toBeVisible();

    // Cancel closes the sheet and returns to list
    const cancelBtn = sheet.getByRole('button', { name: 'Cancel' });
    await expect(cancelBtn).toBeVisible();
    await cancelBtn.click();

    await expect(page).toHaveURL(/\/categories$/);
    await expect(sheet).toHaveCount(0);
  });

  test('clicking a category row opens detail sheet with actions and delete dialog', async ({ page }) => {
    await page.goto('/categories');

    const table = page.locator('.erp-screen table').first();
    await expect(table).toBeVisible();

    const firstRowLink = page.locator('.ui-order-link').first();
    await expect(firstRowLink).toBeVisible();
    await firstRowLink.click();

    await expect(page).toHaveURL(/\/categories\/\d+/);
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    // Verify detail fields
    await expect(sheet.getByRole('heading', { name: 'General' })).toBeVisible();
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Default valuation' })).toBeVisible();

    // Action buttons: Edit and Delete
    const editBtn = sheet.getByRole('link', { name: 'Edit' });
    await expect(editBtn).toBeVisible();

    const deleteBtn = sheet.getByRole('button', { name: 'Delete' });
    await expect(deleteBtn).toBeVisible();

    // Open delete dialog
    await deleteBtn.click();
    const deleteDialog = page.getByRole('dialog').filter({ hasText: 'Delete category' });
    await expect(deleteDialog).toBeVisible();
    await expect(deleteDialog.getByText(/Are you sure you want to delete category/i)).toBeVisible();

    // Cancel deletion
    await deleteDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(deleteDialog).toHaveCount(0);
  });

  test('mobile phone width layout has no horizontal overflow and renders cleanly', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/categories');

    // Check no horizontal scrollbar overflow
    const hasHorizontalOverflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });
    expect(hasHorizontalOverflow).toBe(false);

    // Mobile filter button exists (accessible name is from aria-label)
    const filterBtn = page.getByRole('button', { name: /filter/i });
    await expect(filterBtn).toBeVisible();

    // Mobile FAB for creation
    const fab = page.getByRole('button', { name: 'New category' });
    await expect(fab).toBeVisible();
  });

  test('row selection checkbox displays CategoryBulkBar with bulk actions and clear', async ({ page }) => {
    await page.goto('/categories');

    // 1. Table has row selection checkboxes
    const firstCheckbox = page.getByRole('checkbox', { name: 'Select row' }).first();
    await expect(firstCheckbox).toBeVisible();
    await firstCheckbox.click();

    // 2. CategoryBulkBar appears with count and actions
    const bulkBar = page.getByTestId('category-bulk-bar');
    await expect(bulkBar).toBeVisible();
    await expect(page.getByTestId('bulk-count')).toHaveText('1 selected');
    await expect(bulkBar.getByRole('button', { name: 'Print selected' })).toBeVisible();
    await expect(page.getByTestId('bulk-delete')).toBeVisible();

    // 3. Clear selection button clears the bar
    await bulkBar.getByRole('button', { name: 'Clear selection' }).click();
    await expect(bulkBar).toHaveCount(0);
    await expect(firstCheckbox).not.toBeChecked();
  });

  test('filters for parent, valuation, and hierarchy update URL and show chips', async ({ page }) => {
    await page.goto('/categories');

    // Valuation filter select
    const valTrigger = page.getByRole('combobox', { name: 'Valuation method' });
    await expect(valTrigger).toBeVisible();
    await valTrigger.click();
    await page.getByRole('option', { name: 'AVCO' }).click();

    await expect(page).toHaveURL(/default_valuation_method=AVCO/);
    const valChip = page.locator('.ui-chip-active').filter({ hasText: 'Valuation: AVCO' });
    await expect(valChip).toBeVisible();

    // Hierarchy filter select
    const hierTrigger = page.getByRole('combobox', { name: 'Hierarchy level' });
    await expect(hierTrigger).toBeVisible();
    await hierTrigger.click();
    await page.getByRole('option', { name: 'Leaf categories only' }).click();

    await expect(page).toHaveURL(/has_children=false/);
    const hierChip = page.locator('.ui-chip-active').filter({ hasText: 'Hierarchy: Leaf categories only' });
    await expect(hierChip).toBeVisible();

    // Clear all filters
    await page.getByRole('button', { name: 'Clear all' }).click();
    await expect(valChip).toHaveCount(0);
    await expect(hierChip).toHaveCount(0);
  });

  test('detail screen displays hierarchy role, subcategories, and items in category link', async ({ page }) => {
    await page.goto('/categories');

    const firstRowLink = page.locator('.ui-order-link').first();
    await expect(firstRowLink).toBeVisible();
    await firstRowLink.click();

    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    // Verify enhanced detail fields
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Hierarchy role' })).toBeVisible();
    await expect(sheet.locator('.detail-field-label').filter({ hasText: 'Subcategories' })).toBeVisible();

    // Verify "Items in this category" section and navigation link
    const itemsLink = sheet.getByRole('link', { name: /View items in this category/i });
    await expect(itemsLink).toBeVisible();
  });

  test('category create form validates required fields', async ({ page }) => {
    await page.goto('/categories/new');
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();

    const submitBtn = sheet.getByRole('button', { name: 'Create category' });
    await submitBtn.click();

    await expect(sheet.getByText('Code is required')).toBeVisible();
    await expect(sheet.getByText('Name is required')).toBeVisible();
  });
});
