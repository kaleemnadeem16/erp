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

test('DateField: arrow keys move between segments, Tab exits the whole control', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const orderDateGroup = page.getByRole('group', { name: 'Order date' });
  const month = orderDateGroup.getByLabel('Month');
  const day = orderDateGroup.getByLabel('Day');
  const year = orderDateGroup.getByLabel('Year');

  // Typing a full 2-digit month auto-advances to Day without a Tab/arrow key.
  await month.focus();
  await month.fill('');
  await page.keyboard.type('11');
  await expect(day).toBeFocused();

  // ArrowLeft/ArrowRight move between segments — never a real Tab stop.
  await page.keyboard.press('ArrowLeft');
  await expect(month).toBeFocused();
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  await expect(year).toBeFocused();

  // Only Month is in the Tab order — Tab from any segment leaves the whole
  // control for the next real field (Expected delivery's own Month segment),
  // it never lands on this control's own Day/Year.
  await month.focus();
  await page.keyboard.press('Tab');
  await expect(day).not.toBeFocused();
  const nextMonth = page.getByRole('group', { name: 'Expected delivery' }).getByLabel('Month');
  await expect(nextMonth).toBeFocused();
});

test('Combobox: typing filters options to a search match', async ({ page }) => {
  await page.goto('/items');
  const categoryCombobox = page.getByLabel('Category', { exact: true });
  await categoryCombobox.click();
  const search = page.getByPlaceholder('Search categories…');
  await search.fill('zzz-no-such-category-zzz');
  await expect(page.getByText('No categories found.')).toBeVisible();
  await search.fill('');
  await expect(page.getByRole('option').first()).toBeVisible();
});
