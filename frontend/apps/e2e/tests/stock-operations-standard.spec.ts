import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * The Stock operations group (Physical Counts, Inventory Cutovers, Negative-Stock Overrides, Stock Recalculation) on the screen standard: the shared list template,
 * search + filters + chips, selection with a bulk bar, error/empty states, records as sheets over the list, and phone width. The demo seeders provide records in
 * every one of these lists (StockDemoSeeder, NegativeStockLifecycleDemoSeeder, RecalculationLifecycleDemoSeeder, the cutover demo).
 */
interface Screen {
  name: string;
  path: string;
  heading: RegExp;
  noun: string;
  search?: { label: string; term: string };
  filter: { label: string; option: RegExp; param: string; chip: RegExp };
  create: { button: string; sheet: RegExp };
}

const SCREENS: Screen[] = [
  {
    name: 'Physical counts',
    path: '/physical-counts',
    heading: /Physical Counts/,
    noun: 'count',
    search: { label: 'Search counts by reference', term: 'zzz-no-such-reference' },
    filter: { label: 'Status', option: /^Posted$/, param: 'filter%5Bstatus%5D=POSTED', chip: /Status: Posted/ },
    create: { button: 'New count', sheet: /New physical count|Physical count/ },
  },
  {
    name: 'Inventory cutovers',
    path: '/inventory-cutovers',
    heading: /Inventory Cutovers/,
    noun: 'cutover',
    filter: { label: 'Status', option: /^Draft$/, param: 'filter%5Bstatus%5D=DRAFT', chip: /Status: Draft/ },
    create: { button: 'New cutover', sheet: /New inventory cutover|Inventory cutover/ },
  },
  {
    name: 'Negative-stock overrides',
    path: '/negative-stock-overrides',
    heading: /Negative-Stock Overrides/,
    noun: 'request',
    search: { label: 'Search overrides by item', term: 'demo-neg-primer' },
    filter: { label: 'Authorisation mode', option: /^Prior approval$/, param: 'filter%5Bauthorisation_mode%5D=PRIOR_APPROVAL', chip: /Mode: Prior approval/ },
    create: { button: 'New request', sheet: /New negative-stock override request|Negative-stock override request/ },
  },
  {
    name: 'Stock recalculation',
    path: '/recalculation-requests',
    heading: /Stock Recalculation/,
    noun: 'request',
    search: { label: 'Search recalculation requests by item', term: 'demo' },
    filter: { label: 'Movement kind', option: /^Receipt$/, param: 'filter%5Bmovement_kind%5D=RECEIPT', chip: /Movement: Receipt/ },
    create: { button: 'New request', sheet: /New stock recalculation request|Stock recalculation request/ },
  },
];

const horizontalOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

for (const screen of SCREENS) {
  test.describe(screen.name, () => {
    test('is on the list standard: template, filter as request and chip, Clear all', async ({ page }) => {
      await page.goto(screen.path);
      await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
      await expect(page.getByRole('heading', { name: screen.heading }).first()).toBeVisible();

      const request = page.waitForRequest((r) => r.url().includes('/api/v1/') && r.url().includes(screen.filter.param));
      await page.getByRole('combobox', { name: screen.filter.label, exact: true }).click();
      await page.getByRole('option', { name: screen.filter.option }).press('Enter');
      await request;
      const chip = page.locator('.ui-chip-active').filter({ hasText: screen.filter.chip });
      await expect(chip).toBeVisible();
      await expect(chip.getByRole('button', { name: /Remove filter/ })).toBeVisible();

      if (screen.search) {
        const search = page.getByRole('searchbox', { name: screen.search.label });
        await search.fill(screen.search.term);
        await expect(page.locator('.ui-chip-active').filter({ hasText: `Search: "${screen.search.term}"` })).toBeVisible();
      }
      await page.getByRole('button', { name: 'Clear all' }).click();
      await expect(page.locator('.ui-chip-active')).toHaveCount(0);
    });

    test('selecting rows shows the bulk bar with a count and Print selected, and clearing removes it', async ({ page }) => {
      await page.goto(screen.path);
      const rows = page.getByRole('table').locator('tbody tr');
      await expect(rows.first()).toHaveAttribute('tabindex', '0');
      await page.getByRole('checkbox', { name: /Select row/i }).first().check();
      const bar = page.getByRole('region', { name: 'Bulk actions' });
      await expect(bar).toContainText(new RegExp(`1 ${screen.noun} selected`));
      await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
      await bar.getByRole('button', { name: 'Clear selection' }).click();
      await expect(bar).toHaveCount(0);
    });

    test('a record opens as a sheet over the list and closing keeps the list context', async ({ page }) => {
      await page.goto(`${screen.path}?per_page=10`);
      const rows = page.getByRole('table').locator('tbody tr');
      await expect(rows.first()).toHaveAttribute('tabindex', '0');
      await rows.first().focus();
      await page.keyboard.press('Enter');
      const sheet = page.getByRole('dialog');
      await expect(sheet).toBeVisible();
      await expect(page).toHaveURL(new RegExp(`${screen.path}/\\d+.*per_page=10`));
      // The list stays mounted behind the sheet.
      await expect(page.locator('[data-screen-template="list"]')).toBeAttached();
      await sheet.getByRole('button', { name: 'Close' }).click();
      await expect(page).toHaveURL(new RegExp(`${screen.path}\\?.*per_page=10`));
      await expect(sheet).toHaveCount(0);
    });

    test('creating opens a form sheet that outside clicks do not dismiss, and Cancel/Escape return to the list', async ({ page }) => {
      await page.goto(screen.path);
      await page.getByRole('button', { name: screen.create.button }).click();
      await expect(page).toHaveURL(new RegExp(`${screen.path}/new`));
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: screen.create.sheet }).first()).toBeVisible();
      await page.mouse.click(5, 300); // the dimmed area behind the sheet
      await expect(sheet).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(page).toHaveURL(new RegExp(`${screen.path}(\\?.*)?$`));
      await expect(sheet).toHaveCount(0);
    });

    test('a failed request shows its cause and a retry, never an empty list', async ({ page }) => {
      let calls = 0;
      const api = new RegExp(`/api/v1/${screen.path.slice(1).replace('negative-stock-overrides', 'negative-stock-override-requests')}(\\?.*)?$`);
      await page.route(api, async (route) => {
        calls += 1;
        if (calls <= 3) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) });
        return route.continue();
      });
      await page.goto(screen.path);
      await expect(page.getByRole('alert').filter({ hasText: /Couldn't load/ })).toBeVisible();
      await page.getByRole('button', { name: 'Try again' }).click();
      await expect(page.getByRole('table').locator('tbody tr').first()).toBeVisible();
    });

    test('no serious accessibility violations', async ({ page }) => {
      await page.goto(screen.path);
      await page.waitForLoadState('networkidle');
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
      expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
    });

    test('a person without the create duty is not offered the create action', async ({ page }) => {
      await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        // Read-only access to the screen, no create/request duty.
        await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions: ['inventory.valuation.view', 'inventory.stock.view', 'inventory.negative-stock.review'] } }) });
      });
      await page.goto(screen.path);
      await expect(page.getByRole('heading', { name: screen.heading }).first()).toBeVisible();
      await expect(page.getByRole('button', { name: screen.create.button })).toHaveCount(0);
    });
  });
}

test.describe('phone width (390 px)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  for (const screen of SCREENS) {
    test(`${screen.name}: no horizontal overflow, 44 px targets, a Filters sheet and sheets inside the screen`, async ({ page }) => {
      await page.goto(screen.path);
      await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
      await expect(page.getByText('Select all on this page')).toBeVisible();
      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);

      const filters = page.getByRole('button', { name: /Open filters/ });
      expect((await filters.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      if (screen.search) expect((await page.getByRole('searchbox', { name: screen.search.label }).boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await filters.click();
      const sheet = page.getByRole('dialog');
      await expect(sheet.getByRole('heading', { name: 'Filters' })).toBeVisible();
      await expect(sheet.getByRole('button', { name: 'Done' })).toBeVisible();
      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
      await sheet.getByRole('button', { name: 'Done' }).click();

      const create = page.getByRole('button', { name: screen.create.button });
      await expect(create).toBeVisible();
      expect((await create.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await create.click();
      const form = page.getByRole('dialog');
      await expect(form.getByRole('heading', { name: screen.create.sheet }).first()).toBeVisible();
      expect((await form.boundingBox())!.width).toBeLessThanOrEqual(390.5);
      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
  }
});
