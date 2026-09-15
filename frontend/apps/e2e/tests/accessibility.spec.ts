import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * Automated half of the Phase 2 keyboard/focus/WCAG audit — catches
 * regressions on every push, not just the one-time manual pass. Scans the
 * screens the required operational journey touches (see journey.spec.ts).
 * Filtered to critical/serious impact: axe's moderate/minor findings are
 * often stylistic judgment calls better suited to a human review pass, and
 * a noisy gate here gets disabled rather than fixed.
 */
const JOURNEY_PAGES = ['/inventory-overview', '/purchase-orders', '/purchase-orders/new', '/goods-receipts', '/goods-receipts/new', '/stock-moves'];

for (const path of JOURNEY_PAGES) {
  test(`no serious accessibility violations: ${path}`, async ({ page }) => {
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
}

test('skip link moves keyboard focus past the nav into page content', async ({ page }) => {
  await page.goto('/inventory-overview');
  // page.goto() resolves at the `load` event, which can land before React has
  // mounted — pressing Tab that early lands on nothing (the skip link isn't
  // in the DOM yet) and never gets a second chance. Waiting for the heading
  // ensures the shell (and the skip link before it) has actually rendered.
  await expect(page.getByRole('heading', { name: 'Inventory Overview' })).toBeVisible();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main-content')).toBeFocused();
});

test('a desktop list row opens its detail page via keyboard alone', async ({ page }) => {
  await page.goto('/purchase-orders');
  const table = page.getByRole('table');
  const firstRow = table.locator('tbody tr').first();
  // While the request is in flight the table renders non-interactive skeleton
  // rows (no tabindex) in the same tbody — .toBeVisible() alone can match one
  // of those, and .focus() on a non-focusable row is a silent no-op. Waiting
  // for tabindex="0" (only set on real, click/Enter-activatable rows) ensures
  // real data has replaced the skeleton before we try to focus it.
  await expect(firstRow).toHaveAttribute('tabindex', '0');
  await firstRow.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/purchase-orders\/\d+$/);
});
