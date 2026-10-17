import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { signInAs } from './package-stock-support';

/**
 * PX7: the central Settings index and the read-only "Numbers and rounding" page. They hold no settings of their own — they link to module-owned screens
 * and show only what the signed-in person may open — so these specs check navigation, permission visibility, the fixed limits shown, and presentation.
 */
const SALES_VIEWER = 'demo-sales-viewer@erp.x1techs.com';

test('the index groups every settings page by business area and links to the module-owned screens', async ({ page }) => {
  await page.goto('/settings');
  const index = page.getByTestId('settings-index');
  await expect(index.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
  for (const group of ['Company', 'Purchasing and inventory', 'Estimating', 'Numbers, money and rounding', 'Approvals and access', 'You']) {
    await expect(index.getByRole('heading', { name: group, exact: true })).toBeVisible();
  }
  // Each card names what kind of page it opens, so a person knows before clicking whether anything can be changed.
  await expect(page.getByTestId('settings-link-numbers')).toContainText('Read-only');
  await expect(page.getByTestId('settings-link-appearance')).toContainText('Personal');
  await expect(page.getByTestId('settings-link-inventory')).toContainText('Company policy');

  await page.getByTestId('settings-link-inventory').click();
  await expect(page).toHaveURL(/\/settings\/inventory$/);
  await expect(page.getByRole('heading', { name: /Inventory settings/i }).first()).toBeVisible();
});

test('the top-bar Settings menu reaches the index', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: /settings/i }).first().click();
  await page.getByRole('menuitem', { name: 'All settings' }).click();
  await expect(page).toHaveURL(/\/settings$/);
  await expect(page.getByTestId('settings-index')).toBeVisible();
});

test('Numbers and rounding shows the fixed limits the forms enforce, names the owner of each adjustable rule, and has nothing to edit', async ({ page }) => {
  await page.goto('/settings/numbers');
  const page_ = page.getByTestId('numbers-and-rounding');
  await expect(page_.getByRole('heading', { name: 'Numbers and rounding' })).toBeVisible();

  const rows = await page_.getByTestId('fixed-limits').getByRole('row').evaluateAll((trs) =>
    trs.slice(1).map((tr) => Array.from(tr.querySelectorAll('th,td')).map((cell) => cell.textContent?.trim())),
  );
  const byName = Object.fromEntries(rows.map((row) => [row[0], [row[2], row[3]]]));
  expect(byName['Stock quantities']).toEqual(['4', '15']);
  expect(byName['Money amounts']).toEqual(['4', '15']);
  expect(byName['Recipe and assembly quantities']).toEqual(['6', '13']);
  expect(byName['Conversion factors']).toEqual(['9', '10']);
  expect(byName['Exchange rates']).toEqual(['9', '10']);

  const owners = page_.getByTestId('rule-owners');
  await expect(owners).toContainText('Sales unit-price entry precision and rounding');
  await expect(owners).toContainText('cannot be changed from the app yet');
  await expect(owners).toContainText('a currency already in use never changes its decimal places');
  await expect(owners.getByRole('link', { name: 'Purchasing settings' })).toHaveAttribute('href', '/settings/purchasing');
  // Nothing on this page is a control for a stored value.
  await expect(page_.getByRole('textbox')).toHaveCount(0);
  await expect(page_.getByRole('button')).toHaveCount(0);
  await expect(page_.getByRole('combobox')).toHaveCount(0);
});

test('a person without the duties sees only what they may open, and is not offered administration pages', async ({ browser }) => {
  const viewer = await signInAs(browser, SALES_VIEWER);
  await viewer.goto('/settings');
  const index = viewer.getByTestId('settings-index');
  await expect(index.getByRole('heading', { name: 'You', exact: true })).toBeVisible();
  await expect(viewer.getByTestId('settings-link-account')).toBeVisible();
  await expect(viewer.getByTestId('settings-link-numbers')).toBeVisible();
  for (const hidden of ['members', 'roles', 'organisation', 'approvals', 'material-variance']) {
    await expect(viewer.getByTestId(`settings-link-${hidden}`)).toHaveCount(0);
  }
  // A bookmarked URL for a page they may not open is still refused by the route itself, not just hidden here.
  await viewer.goto('/settings/members');
  await expect(viewer.getByTestId('settings-link-members')).toHaveCount(0);
  await expect(viewer.getByRole('heading', { name: 'Members', exact: true })).toHaveCount(0);
  await viewer.context().close();
});

for (const mode of ['light', 'dark'] as const) {
  test(`both pages fit a phone and have no serious accessibility or contrast problems (${mode})`, async ({ page }) => {
    await page.addInitScript((appearance) => localStorage.setItem('erp.appearance', JSON.stringify(appearance)), { mode, theme: 'erp-blue' });
    for (const [path, id] of [['/settings', 'settings-index'], ['/settings/numbers', 'numbers-and-rounding']] as const) {
      for (const viewport of [{ width: 1360, height: 800 }, { width: 390, height: 800 }]) {
        await page.setViewportSize(viewport);
        await page.goto(path);
        await expect(page.getByTestId(id)).toBeVisible();
        await page.waitForLoadState('networkidle');
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `${path} at ${viewport.width}px scrolls sideways`).toBeLessThanOrEqual(0);
        const results = await new AxeBuilder({ page }).analyze();
        const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
        expect(serious, `${path} ${viewport.width}px: ${JSON.stringify(serious, null, 2)}`).toEqual([]);
      }
    }
  });
}
