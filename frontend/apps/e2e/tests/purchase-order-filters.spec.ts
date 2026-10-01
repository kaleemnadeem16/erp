import { expect, test } from '@playwright/test';

test.describe('Purchase Orders list search and advanced filters', () => {
  test('desktop toolbar renders Filters button, opens panel with all advanced filters, updates count and URL', async ({ page }) => {
    // 1. Navigate to /purchase-orders
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();

    // 2. Verify primary toolbar elements
    const searchInput = page.getByPlaceholder('Search orders, suppliers, items…');
    await expect(searchInput).toBeVisible();

    const desktopToolbar = page.locator('div.hidden.md\\:flex');
    const filtersBtn = desktopToolbar.getByRole('button', { name: /Filters/ });
    await expect(filtersBtn).toBeVisible();

    // 3. Click Filters button to open advanced filter panel
    await filtersBtn.click();
    await expect(page.getByText('Advanced Filters')).toBeVisible();
    await page.screenshot({ path: 'frontend/apps/e2e/test-results/desktop-filters-open.png' });

    // 4. Verify all advanced filter fields are rendered
    await expect(page.getByLabel('Order date from')).toBeVisible();
    await expect(page.getByLabel('Order date to')).toBeVisible();
    await expect(page.getByLabel('Expected delivery from')).toBeVisible();
    await expect(page.getByLabel('Expected delivery to')).toBeVisible();
    await expect(page.getByLabel('Contains item')).toBeVisible();
    await expect(page.getByLabel('Issued date from')).toBeVisible();

    // 5. Commercial fields visible for admin
    await expect(page.getByLabel('Min total value')).toBeVisible();
    await expect(page.getByLabel('Max total value')).toBeVisible();
    await expect(page.getByLabel('Currency')).toBeVisible();

    // 6. Supplier site shows prompt before supplier is selected
    await expect(page.getByText('Select supplier above')).toBeVisible();

    // 7. Verify initial unfiltered list contains both CLOSED and ISSUED rows
    await expect(page.getByRole('cell', { name: 'CLOSED' }).first()).toBeVisible();
    await expect(page.getByRole('cell', { name: 'ISSUED' }).first()).toBeVisible();

    // 8. Apply a filter using total value min (e.g. 500000) to visibly change results
    await page.getByLabel('Min total value').fill('500000');
    // Active filter count updates on button
    await expect(filtersBtn).toContainText('1');

    // 8a. Test close and re-open animation transition
    await page.getByRole('button', { name: 'Close filters' }).click();
    await expect(page.locator('div[aria-hidden="true"]')).toHaveClass(/grid-rows-\[0fr\]/);
    await filtersBtn.click();
    await expect(page.locator('div[aria-hidden="false"]')).toHaveClass(/grid-rows-\[1fr\]/);

    // URL query state updated
    await expect(page).toHaveURL(/total_min=500000/);
    // Closed orders with 1000 PKR must disappear
    await expect(page.getByRole('cell', { name: '0040' })).toHaveCount(0);
    // The demo draft worth 650000 PKR should be visible. Its document number
    // depends on the other seeded purchase orders and can change.
    const filteredDraft = page.getByRole('row').filter({ hasText: 'PKR 650,000.00' });
    await expect(filteredDraft).toBeVisible();

    // 9. Refresh preserves filter in URL, count, and results
    await page.reload();
    await expect(page).toHaveURL(/total_min=500000/);
    await expect(desktopToolbar.getByRole('button', { name: /Filters/ })).toContainText('1');
    await expect(filteredDraft).toBeVisible();
    await expect(page.getByRole('cell', { name: '0040' })).toHaveCount(0);

    // 10. Click row to navigate to detail page
    await filteredDraft.click();
    await expect(page).toHaveURL(/\/purchase-orders\/\d+/);

    // 11. Test browser back navigation restores list with filters preserved
    await page.goBack();
    await expect(page).toHaveURL(/total_min=500000/);
    await expect(filteredDraft).toBeVisible();
    await expect(page.getByRole('cell', { name: '0040' })).toHaveCount(0);
    await expect(desktopToolbar.getByRole('button', { name: /Filters/ })).toContainText('1');

    // 12. Test browser forward navigation goes back to detail
    await page.goForward();
    await expect(page).toHaveURL(/\/purchase-orders\/\d+/);

  });

  test('mobile bottom sheet contains all filters', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();

    const mobileFiltersBtn = page.getByRole('button', { name: /Filters/ });
    await expect(mobileFiltersBtn).toBeVisible();
    await mobileFiltersBtn.click();

    // Bottom sheet header and controls
    const sheet = page.locator('[role="dialog"]');
    await expect(sheet.getByRole('heading', { name: 'Filters' })).toBeVisible();
    await expect(sheet.getByLabel('Contains item')).toBeVisible();
    await expect(sheet.getByLabel('Order date from')).toBeVisible();
    await expect(sheet.getByLabel('Min total value')).toBeVisible();

    // Take screenshot of mobile sheet
    await page.screenshot({ path: 'frontend/apps/e2e/test-results/mobile-filters.png' });
  });
});

test.describe('Non-commercial user (receiving clerk)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('does not see Currency or Total Value filters', async ({ page }) => {
    const CLERK = 'demo-receiver@erp.x1techs.com';
    const PASSWORD = process.env.E2E_USER_PASSWORD ?? '!User@Erp@123';

    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(CLERK);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();

    const desktopToolbar = page.locator('div.hidden.md\\:flex');
    const filtersBtn = desktopToolbar.getByRole('button', { name: /Filters/ });
    await expect(filtersBtn).toBeVisible();
    await filtersBtn.click();
    await expect(page.getByText('Advanced Filters')).toBeVisible();

    // Non-commercial should NOT see total value or currency
    await expect(page.getByLabel('Min total value')).toHaveCount(0);
    await expect(page.getByLabel('Max total value')).toHaveCount(0);
    await expect(page.getByLabel('Currency')).toHaveCount(0);
  });
});
