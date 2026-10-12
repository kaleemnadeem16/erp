import { expect, test } from '@playwright/test';
import { signInAs } from './package-stock-support';

/**
 * Which items a supplier supplies is Purchasing's sourcing data. A person who may list items but not read item-supplier links must neither be
 * offered the sourcing filters, nor be able to use them through the API, nor have a stale link with those filters break the list.
 */
const STEWARD = 'demo-steward@erp.x1techs.com';
const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8090/api/v1';
const ORIGIN = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5176';

test('a steward without sourcing access neither sees nor can use the supplier filters', async ({ browser }) => {
  const page = await signInAs(browser, STEWARD);
  const sourcingRequests: string[] = [];
  page.on('request', (request) => {
    if (/\/api\/v1\/items\?/.test(request.url()) && /filter(%5B|\[)(has_supplier|supplier_id)/.test(request.url())) sourcingRequests.push(request.url());
  });

  // A bookmarked link carrying the filters must not break the list or reach the server.
  await page.goto('/items?filter.has_supplier=true&filter.supplier_id=1');
  await expect(page.getByRole('heading', { name: 'Items', exact: true })).toBeVisible();
  await expect(page.getByText(/couldn.t load|can.t load/i)).toHaveCount(0);
  expect(sourcingRequests).toEqual([]);
  await expect(page.getByText('Sourcing status')).toHaveCount(0);

  // And the server refuses it regardless of the screen.
  const refused = await page.request.get(`${API}/items?filter[has_supplier]=true`, { headers: { Accept: 'application/json', Origin: ORIGIN, Referer: `${ORIGIN}/` } });
  expect(refused.status()).toBe(403);
  const open = await page.request.get(`${API}/items`, { headers: { Accept: 'application/json', Origin: ORIGIN, Referer: `${ORIGIN}/` } });
  expect(open.status()).toBe(200);
  await page.context().close();
});
