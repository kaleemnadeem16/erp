import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * The Estimating masters whose lists are on the screen standard so far: Rate dimensions and Assembly groups (search is the only filter their endpoints validate).
 * Formulations, Application rates, Assemblies and Resource rates still carry their earlier list layout; their create/edit forms are pages for all six
 * (docs/phases/screens-complete-log.md, remaining work).
 */
const SCREENS = [
  { name: 'Rate dimensions', path: '/rate-dimensions', heading: 'Rate dimensions', search: 'Search rate dimensions', api: 'rate-dimensions', term: 'surface', noun: 'dimension' },
  { name: 'Assembly groups', path: '/assembly-groups', heading: 'Assembly groups', search: 'Search assembly groups', api: 'assembly-groups', term: 'demo', noun: 'group' },
] as const;

const horizontalOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

for (const screen of SCREENS) {
  test.describe(screen.name, () => {
    test('is on the list standard: template, search as a chip, selection with Print selected', async ({ page }) => {
      await page.goto(screen.path);
      await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
      await expect(page.getByRole('heading', { name: screen.heading, exact: true })).toBeVisible();
      const rows = page.getByRole('table').locator('tbody tr');
      await expect(rows.first()).toBeVisible();

      await page.getByRole('searchbox', { name: screen.search }).fill(screen.term);
      const chip = page.locator('.ui-chip-active').filter({ hasText: `Search: "${screen.term}"` });
      await expect(chip).toBeVisible();
      await expect(page).toHaveURL(new RegExp(`search=${screen.term}`));

      await page.getByRole('checkbox', { name: /Select row/i }).first().check();
      const bar = page.getByRole('region', { name: 'Bulk actions' });
      await expect(bar).toContainText(new RegExp(`1 ${screen.noun} selected`));
      await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
      await bar.getByRole('button', { name: 'Clear selection' }).click();
      await expect(bar).toHaveCount(0);

      await chip.getByRole('button', { name: /Remove filter/ }).click();
      await expect(chip).toHaveCount(0);
      await expect(page.getByRole('searchbox', { name: screen.search })).toHaveValue('');
    });

    test('a failed request shows its cause and a retry, never an empty list', async ({ page }) => {
      let calls = 0;
      await page.route(new RegExp(`/api/v1/${screen.api}(\\?.*)?$`), async (route) => {
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

    test('a person without the duty is shown the access message and nothing is requested', async ({ page }) => {
      // These screens are open only to their maintainers (screen-access.ts), so there is no view-only state to test: without the duty there is no screen.
      await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions: ['estimating.recipe.view'] } }) });
      });
      let requests = 0;
      page.on('request', (request) => {
        if (new RegExp(`/api/v1/${screen.api}(?:\\?|$)`).test(request.url())) requests += 1;
      });
      await page.goto(screen.path);
      await expect(page.getByRole('heading', { name: 'You don’t have access' })).toBeVisible();
      expect(requests).toBe(0);
    });
  });
}

test.describe('phone width (390 px)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  for (const screen of SCREENS) {
    test(`${screen.name}: cards, no horizontal overflow, 44 px search and Filters sheet`, async ({ page }) => {
      await page.goto(screen.path);
      await expect(page.getByText('Select all on this page')).toBeVisible();
      expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
      expect((await page.getByRole('searchbox', { name: screen.search }).boundingBox())!.height).toBeGreaterThanOrEqual(44);
      const filters = page.getByRole('button', { name: /Open filters/ });
      expect((await filters.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await filters.click();
      await expect(page.getByRole('dialog').getByRole('heading', { name: 'Filters' })).toBeVisible();
    });
  }
});
