import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { pickCombobox } from './support';

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

  // Only Month is in the Tab order among the segments — Tab never lands on
  // this control's own Day/Year. The calendar button is the one other stop
  // (mouse-only would leave keyboard users unable to open it), and the next
  // Tab leaves the control for the next field's Month segment.
  await month.focus();
  await page.keyboard.press('Tab');
  await expect(day).not.toBeFocused();
  await expect(orderDateGroup.getByRole('button', { name: 'Choose date' })).toBeFocused();
  await page.keyboard.press('Tab');
  // The next field's label carries a help (i) button, which is a keyboard stop of its own, before its Month segment.
  await expect(page.getByRole('button', { name: 'About Expected delivery' })).toBeFocused();
  await page.keyboard.press('Tab');
  const nextMonth = page.getByRole('group', { name: 'Expected delivery' }).getByLabel('Month');
  await expect(nextMonth).toBeFocused();
});

test('DateField: typed digits are stored exactly as typed, and the calendar picks a date', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const group = page.getByRole('group', { name: 'Expected delivery' });
  const segments = () => group.locator('input').evaluateAll((inputs) => inputs.map((input) => (input as HTMLInputElement).value).join('/'));

  // Regression: filling a segment jumps to the next one, and the segment just
  // left used to write its stale value back — typing 11/15/2026 came out 01/01/2026.
  await group.getByLabel('Month').focus();
  await page.keyboard.type('11152026');
  expect(await segments()).toBe('11/15/2026');

  await group.getByLabel('Month').focus();
  await page.keyboard.type('09');
  expect(await segments()).toBe('09/15/2026');

  // A single leading digit that can't take a second one advances on its own, and
  // a lone digit is padded once the segment loses focus.
  await group.getByLabel('Month').focus();
  await page.keyboard.type('7');
  await page.keyboard.type('3');
  await page.keyboard.press('Tab');
  expect(await segments()).toBe('07/03/2026');

  // The calendar popup sets the same value.
  await group.getByRole('button', { name: 'Choose date' }).click();
  await page.getByRole('button', { name: '20', exact: true }).click();
  expect(await segments()).toBe('07/20/2026');
});

test('PO create: dates entered in the sheet are saved and shown on the order', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('group', { name: 'Expected delivery' }).getByLabel('Month').focus();
  await page.keyboard.type('12202030');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  // Scoped to the Review step, not just anywhere in the sheet — the order
  // summary side column also shows the expected-delivery date.
  const reviewSection = sheet.locator('section').filter({ hasText: 'Review' });
  await expect(reviewSection.getByText('2030-12-20')).toBeVisible();
  await sheet.getByRole('button', { name: 'Save draft' }).click();
  await expect(page).toHaveURL(/\/purchase-orders\/\d+$/);
  await expect(page.getByRole('dialog').getByText('Expected delivery').first()).toBeVisible();
  await expect(page.getByRole('dialog').getByText('2030-12-20').first()).toBeVisible();
});

test('PO detail sheet: opens over the list, and closes back to it', async ({ page }) => {
  await page.goto('/purchase-orders');
  const firstRow = page.getByRole('table').locator('tbody tr').first();
  await expect(firstRow).toHaveAttribute('tabindex', '0');
  await firstRow.click();
  await expect(page).toHaveURL(/\/purchase-orders\/\d+$/);

  // A dialog over the still-rendered list, not a separate page.
  const sheet = page.getByRole('dialog', { name: /purchase order/i });
  await expect(sheet).toBeVisible();
  await expect(page.getByText('Raise supplier commitments')).toBeVisible();
  await expect(sheet.getByRole('heading', { name: 'Commercial' })).toBeVisible();

  // Focus lands on the sheet itself, not on the close button (which would sit there ringed).
  await expect(sheet).toBeFocused();

  // Clicking the dimmed list behind a read-only view closes it; Escape does too.
  await page.mouse.click(20, 400);
  await expect(page).toHaveURL(/\/purchase-orders$/);
  await expect(sheet).toBeHidden();
  await firstRow.click();
  await expect(sheet).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page).toHaveURL(/\/purchase-orders$/);
});

test('no serious accessibility violations: a purchase order in the detail sheet', async ({ page }) => {
  await page.goto('/purchase-orders');
  const firstRow = page.getByRole('table').locator('tbody tr').first();
  await expect(firstRow).toHaveAttribute('tabindex', '0');
  await firstRow.click();
  await expect(page.getByRole('dialog', { name: /purchase order/i })).toBeVisible();
  const results = await new AxeBuilder({ page }).analyze();
  const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
  expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
});

test('PO create sheet: list stays visible behind it and an outside click does not close it', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await expect(sheet).toBeVisible();

  // The list is mounted (dimmed) behind the sheet, not swapped for a blank shell.
  await expect(page.getByText('Raise supplier commitments')).toBeVisible();

  // A stray click on the dimmed area must not dismiss the sheet.
  await page.mouse.click(20, 400);
  await expect(sheet).toBeVisible();
  await expect(page).toHaveURL(/\/purchase-orders\/new$/);
});

test('PO create sheet: closing keeps unfinished work and reopening picks it back up', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });

  // Nothing entered -> nothing kept: the list's button stays "New purchase order".
  await sheet.getByRole('button', { name: 'Close' }).click();
  await expect(page).toHaveURL(/\/purchase-orders$/);
  await expect(page.getByRole('button', { name: 'New purchase order' })).toBeVisible();

  // Fill in the first step, move on to Lines, then close mid-way — no prompt,
  // e.g. to go and check something on another screen.
  await page.getByRole('button', { name: 'New purchase order' }).click();
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await expect(sheet.getByLabel('Currency', { exact: true })).not.toContainText('Select currency');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await sheet.getByRole('button', { name: 'Close' }).click();
  await expect(sheet).toBeHidden();

  // The list now offers to continue; the sheet returns on the same step with the same data.
  await page.getByRole('button', { name: 'Continue new purchase order' }).click();
  await expect(sheet.getByText('Continuing your unfinished purchase order')).toBeVisible();
  await expect(sheet.getByRole('button', { name: 'Add line' })).toBeVisible();
  await sheet.getByRole('button', { name: 'Back' }).click();
  await expect(sheet.getByLabel('Supplier', { exact: true })).toContainText('Punjab Pigments');

  // "Start over" asks first, in place, before it discards anything.
  await sheet.getByRole('button', { name: 'Start over' }).click();
  await expect(sheet.getByText("Discard everything you've entered?")).toBeVisible();
  await sheet.getByRole('button', { name: 'Yes, start over' }).click();
  await expect(sheet.getByLabel('Supplier', { exact: true })).toContainText('Select supplier');
  await expect(sheet.getByText('Continuing your unfinished purchase order')).toBeHidden();
  await sheet.getByRole('button', { name: 'Close' }).click();
  await expect(page.getByRole('button', { name: 'New purchase order' })).toBeVisible();
});

test('Combobox: typing filters options to a search match', async ({ page }) => {
  await page.goto('/items');
  await page.getByRole('button', { name: 'Filters' }).click();
  const categoryCombobox = page.getByLabel('Category', { exact: true });
  await categoryCombobox.click();
  const search = page.getByPlaceholder('Search categories…');
  await search.fill('zzz-no-such-category-zzz');
  await expect(page.getByText('No categories found.')).toBeVisible();
  await search.fill('');
  await expect(page.getByRole('option').first()).toBeVisible();
});
