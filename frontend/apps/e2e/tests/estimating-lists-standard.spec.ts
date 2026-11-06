import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * The six Estimating masters and the Resource rates list on the screen standard: the shared list template, URL-held search (where the endpoint has one) as a chip,
 * selection with Print selected, error-with-retry, access, phone width. Their create/edit forms are sheets (estimating-records.spec.ts).
 */
interface Screen {
  name: string;
  path: string;
  heading: string;
  /** The search box, where the endpoint has a free-text search. */
  search?: string;
  api: string;
  term?: string;
  noun: string;
  /** Permissions of someone who may not open the screen at all. */
  denied: string[];
}

const SCREENS: Screen[] = [
  { name: 'Rate dimensions', path: '/rate-dimensions', heading: 'Rate dimensions', search: 'Search rate dimensions', api: 'rate-dimensions', term: 'surface', noun: 'dimension', denied: ['estimating.recipe.view'] },
  { name: 'Assembly groups', path: '/assembly-groups', heading: 'Assembly groups', search: 'Search assembly groups', api: 'assembly-groups', term: 'demo', noun: 'group', denied: ['estimating.recipe.view'] },
  { name: 'Formulations', path: '/formulations', heading: 'Formulations', search: 'Search formulations', api: 'formulations', term: 'demo', noun: 'formulation', denied: ['purchasing.purchase-order.view'] },
  { name: 'Application rates', path: '/application-rates', heading: 'Application rates', search: 'Search application rates', api: 'application-rates', term: 'paint', noun: 'rate', denied: ['purchasing.purchase-order.view'] },
  { name: 'Assemblies', path: '/assemblies', heading: 'Assemblies', search: 'Search assemblies', api: 'assemblies', term: 'demo', noun: 'assembly', denied: ['purchasing.purchase-order.view'] },
  { name: 'Resource rates', path: '/resource-rates', heading: 'Resource rates', api: 'resource-rates', noun: 'rate', denied: ['purchasing.purchase-order.view'] },
];

const horizontalOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

for (const screen of SCREENS) {
  test.describe(screen.name, () => {
    test('is on the list standard: template, search as a chip, selection with Print selected', async ({ page }) => {
      await page.goto(screen.path);
      await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
      await expect(page.getByRole('heading', { name: screen.heading, exact: true })).toBeVisible();
      const rows = page.getByRole('table').locator('tbody tr');
      await expect(rows.first()).toBeVisible();

      const chip = page.locator('.ui-chip-active').filter({ hasText: `Search: "${screen.term}"` });
      if (screen.search) {
        await page.getByRole('searchbox', { name: screen.search }).fill(screen.term!);
        await expect(chip).toBeVisible();
        await expect(page).toHaveURL(new RegExp(`search=${screen.term}`));
      }

      await page.getByRole('checkbox', { name: /Select row/i }).first().check();
      const bar = page.getByRole('region', { name: 'Bulk actions' });
      await expect(bar).toContainText(new RegExp(`1 ${screen.noun} selected`));
      await expect(bar.getByRole('button', { name: 'Print selected' })).toBeVisible();
      await bar.getByRole('button', { name: 'Clear selection' }).click();
      await expect(bar).toHaveCount(0);

      if (screen.search) {
        await chip.getByRole('button', { name: /Remove filter/ }).click();
        await expect(chip).toHaveCount(0);
        await expect(page.getByRole('searchbox', { name: screen.search })).toHaveValue('');
      }
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
      // Without any duty that opens the screen (screen-access.ts) there is no screen, and nothing is asked of the server.
      await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions: screen.denied } }) });
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
      if (screen.search) expect((await page.getByRole('searchbox', { name: screen.search }).boundingBox())!.height).toBeGreaterThanOrEqual(44);
      const filters = page.getByRole('button', { name: /Open filters/ });
      expect((await filters.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await filters.click();
      await expect(page.getByRole('dialog').getByRole('heading', { name: 'Filters' })).toBeVisible();
    });
  }
});
