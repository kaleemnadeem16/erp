import { expect, test } from '@playwright/test';
import { activateGrouped, api, createStockedItem } from './package-stock-support';

/**
 * Grouped package transfer (docs/phases/package-balance-ui.md §4): source and destination balances before and after, the same draw editor as an issue,
 * whole cans arriving sealed and loose arriving loose, and the destination-needs-count blocker.
 */
test('transfer 10 L to Karachi: whole can arrives sealed, loose arrives loose, both balances update', async ({ page }) => {
  test.setTimeout(120_000);
  const world = await createStockedItem(page, 'e2e-xfer');
  await activateGrouped(page, world);

  await page.goto(`/items/${world.item.id}`);
  const row = page.getByTestId('package-balance-row').first();
  await expect(row.getByTestId('breakdown-total')).toContainText(/= 20 l/i);
  await row.getByTestId('transfer-stock').click();

  const dialog = page.getByTestId('transfer-dialog');
  await expect(dialog.getByTestId('transfer-source')).toContainText('3 × Sealed 5 L can');
  await dialog.getByLabel('To', { exact: true }).selectOption(String(world.karachi.id));
  await expect(dialog.getByTestId('transfer-destination-now')).toContainText('No stock of this item there yet');

  await dialog.getByLabel(/How much do you want to move/).fill('10');
  const proposed = dialog.getByTestId('proposed-draw');
  await expect(proposed).toContainText(/Take 5 l loose/i);
  await expect(proposed).toContainText('Take 1 sealed Sealed 5 L can');
  await expect(dialog.getByTestId('leaves')).toContainText('2 × Sealed 5 L can');
  await expect(dialog.getByTestId('leaves')).toContainText(/= 10 l/i);
  const arrival = dialog.getByTestId('transfer-arrival');
  await expect(arrival).toContainText('1 × Sealed 5 L can');
  await expect(arrival).toContainText(/5 l loose/i);
  await expect(arrival).toContainText(/= 10 l/i);

  await dialog.getByTestId('transfer-submit').click();
  await expect(dialog).toHaveCount(0);

  const rows = page.getByTestId('package-balance-row');
  await expect(rows).toHaveCount(2);
  const main = rows.filter({ hasText: /Main Stock/ });
  const karachi = rows.filter({ hasText: world.karachi.name });
  await expect(main.getByTestId('breakdown-sealed')).toContainText('2 × Sealed 5 L can');
  await expect(main.getByTestId('breakdown-total')).toContainText(/= 10 l/i);
  await expect(karachi.getByTestId('breakdown-sealed')).toContainText('1 × Sealed 5 L can');
  await expect(karachi.getByTestId('breakdown-loose')).toContainText(/5 l loose/i);
  await expect(karachi.getByTestId('breakdown-total')).toContainText(/= 10 l/i);
  const balances = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data;
  expect(balances.map((b: any) => [b.total, b.fresh]).sort()).toEqual([['10.0000', true], ['10.0000', true]]);
});

test('a destination holding stock with no current package count blocks the transfer and offers to count it', async ({ page }) => {
  test.setTimeout(120_000);
  const world = await createStockedItem(page, 'e2e-xfer-block');
  await activateGrouped(page, world);

  // The server refuses this for real (covered by the backend tests); here the screen's reaction is checked with the destination reported as uncounted.
  await page.route('**/api/v1/package-balances*', async (route) => {
    const url = new URL(route.request().url());
    if (url.searchParams.get('location_id') !== String(world.karachi.id)) return route.continue();
    return route.fulfill({
      json: {
        data: [{
          item_id: world.item.id, batch_id: null, package_tracking_mode: 'PACKAGE_BALANCE', total: '4.0000', sealed: [], loose: '0.0000', fresh: false, reasons: ['LATER_STOCK_MOVEMENT'], operation_id: 1,
          location: { id: world.karachi.id, code: 'stock', name: world.karachi.name, warehouse_name: 'Karachi' },
        }],
      },
    });
  });

  await page.goto(`/items/${world.item.id}`);
  await page.getByTestId('package-balance-row').first().getByTestId('transfer-stock').click();
  const dialog = page.getByTestId('transfer-dialog');
  await dialog.getByLabel('To', { exact: true }).selectOption(String(world.karachi.id));
  await dialog.getByLabel(/How much do you want to move/).fill('5');
  await expect(dialog.getByTestId('proposed-draw')).toContainText(/Take 5 l loose/i);

  await expect(dialog.getByTestId('transfer-needs-count')).toContainText(/no current package count/i);
  await expect(dialog.getByTestId('transfer-submit')).toBeDisabled();
  await dialog.getByTestId('count-destination').click();
  const count = page.getByTestId('count-location-dialog');
  await expect(count).toContainText('stock total 4 l');
  await expect(count.getByTestId('count-loose')).toContainText(/Loose: 4 l/i);
});
