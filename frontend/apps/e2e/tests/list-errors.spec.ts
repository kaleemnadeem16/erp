import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type Route } from '@playwright/test';

/**
 * A list whose request fails has to say so, say why, and let the person retry. It used to retry every
 * failure three times with growing delays, so even a definitive 403 or 422 sat behind ~10 seconds of
 * loading skeletons (and four identical requests) with a "0 records" footer, then showed one message
 * for every cause. The Warehouses list is used as the specimen; the behaviour lives in the shared
 * table, the shared retry rule and the shared wording, so it holds for every list screen.
 */
const WAREHOUSES = /\/api\/v1\/warehouses\?/;

async function failWarehouses(page: Page, respond: (route: Route, attempt: number) => Promise<void>) {
  let attempts = 0;
  await page.route(WAREHOUSES, async (route) => {
    attempts += 1;
    await respond(route, attempts);
  });

  return { attempts: () => attempts };
}

const forbidden = (route: Route) =>
  route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ message: 'This action is unauthorized.' }) });

test('a missing permission is named immediately and is not retried', async ({ page }) => {
  const calls = await failWarehouses(page, forbidden);

  await page.goto('/warehouses');

  const alert = page.getByRole('alert').filter({ hasText: "Couldn't load warehouses." });
  await expect(alert).toContainText("You don't have permission to view this.", { timeout: 3_000 });
  await expect(alert.getByRole('button', { name: 'Try again' })).toBeVisible();
  // A 403 cannot change on a second try, so there is exactly one request — and no misleading "0 records".
  expect(calls.attempts()).toBe(1);
  await expect(page.getByText('0 records')).toHaveCount(0);
});

test('an invalid request says what the server objected to', async ({ page }) => {
  await failWarehouses(page, (route) =>
    route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'The filter.active field must be true or false.' }) }),
  );

  await page.goto('/warehouses');

  const alert = page.getByRole('alert').filter({ hasText: "Couldn't load warehouses." });
  await expect(alert).toContainText('The filter.active field must be true or false.', { timeout: 3_000 });
  await expect(alert).toContainText('Try changing or clearing the filters.');
});

test('a server fault that clears up is retried and the list simply appears', async ({ page }) => {
  const calls = await failWarehouses(page, (route, attempt) =>
    attempt === 1
      ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) })
      : route.continue(),
  );

  await page.goto('/warehouses');

  await expect(page.locator('tbody tr', { hasText: 'demo-main' })).not.toHaveCount(0, { timeout: 10_000 });
  await expect(page.getByRole('alert').filter({ hasText: "Couldn't load warehouses." })).toHaveCount(0);
  expect(calls.attempts()).toBeGreaterThanOrEqual(2);
});

test('being unable to reach the server is retried twice, then reported as that', async ({ page }) => {
  const calls = await failWarehouses(page, (route) => route.abort('failed'));

  await page.goto('/warehouses');

  await expect(page.getByRole('alert').filter({ hasText: "Can't reach the server." })).toBeVisible({ timeout: 10_000 });
  expect(calls.attempts()).toBe(3);
});

test('"Try again" reloads the list without reloading the page', async ({ page }) => {
  let failing = true;
  await page.route(WAREHOUSES, (route) => (failing ? forbidden(route) : route.continue()));

  await page.goto('/warehouses');
  const alert = page.getByRole('alert').filter({ hasText: "Couldn't load warehouses." });
  await expect(alert).toBeVisible({ timeout: 3_000 });

  failing = false;
  await alert.getByRole('button', { name: 'Try again' }).click();

  await expect(page.locator('tbody tr', { hasText: 'demo-main' })).not.toHaveCount(0);
  await expect(alert).toHaveCount(0);
});

test('the error state has no serious accessibility violations', async ({ page }) => {
  await failWarehouses(page, forbidden);
  await page.goto('/warehouses');
  await expect(page.getByRole('alert').filter({ hasText: "Couldn't load warehouses." })).toBeVisible({ timeout: 3_000 });

  const results = await new AxeBuilder({ page }).analyze();
  const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
  expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
});
