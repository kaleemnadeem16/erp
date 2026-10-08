import { expect, test, type Page } from '@playwright/test';
import { activateGrouped, api, createStockedItem, withMoreThanOneHundredItemsAhead, type PackageStockWorld } from './package-stock-support';

/**
 * Package-aware goods-receipt reversal (docs/phases/package-balance-ui.md §6): the arrived composition is the default, what is on hand now is shown,
 * the draw can change when stock changed form, and blockers (a delivery already used, stock not on hand) are explained and stop the confirmation.
 */
async function postedReceipt(page: Page, world: PackageStockWorld): Promise<{ id: number; lineId: number }> {
  const receipt = await world.receipt('12'); // a further 12 L at the main store: 2 cans + 2 L loose arrive
  const lineId = (await api(page, 'GET', `/goods-receipts/${receipt.id}`)).data.lines[0].id;
  await api(page, 'PUT', `/goods-receipt-lines/${lineId}/package-composition`, { reason: 'Counted on arrival', composition: [{ packaging_id: world.can.id, count: 2 }, { quantity: '2' }] });
  await api(page, 'POST', `/goods-receipts/${receipt.id}/post`);

  return { id: receipt.id, lineId };
}

async function openReversal(page: Page, receiptId: number) {
  await page.goto(`/goods-receipts/${receiptId}`);
  await page.getByRole('button', { name: 'Reverse receipt' }).click();
  await expect(page.getByTestId('reversal-panel')).toBeVisible();
}

test('reversing an untouched grouped delivery proposes what arrived and takes exactly that off the shelf', async ({ page }) => {
  test.setTimeout(150_000);
  const world = await createStockedItem(page, 'e2e-rev-ok');
  await activateGrouped(page, world); // 3 cans + 5 L = 20 L
  const { id } = await postedReceipt(page, world); // now 5 cans + 7 L = 32 L

  await openReversal(page, id);
  const line = page.getByTestId('reversal-line');
  await expect(line.getByTestId('reversal-on-hand')).toContainText('5 × Sealed 5 L can');
  await expect(line.getByTestId('reversal-draw')).toContainText('2 sealed Sealed 5 L can + 2 l loose = 12 l');
  await expect(line.getByTestId('reversal-check')).toContainText('on hand');
  await expect(line.getByTestId('reversal-changed')).toHaveCount(0);
  await page.getByPlaceholder('Correction reason').fill('Wrong delivery');
  await page.getByRole('button', { name: 'Confirm reversal' }).click();
  await expect(page.getByText('REVERSED', { exact: true }).first()).toBeVisible();

  const balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect([balance.sealed[0].count, balance.loose, balance.total]).toEqual([3, '5.0000', '20.0000']);
});

test('when the cans were used the screen flags the changed form, offers the nearest draw and lets the person confirm it', async ({ page }) => {
  test.setTimeout(150_000);
  const world = await createStockedItem(page, 'e2e-rev-changed');
  await activateGrouped(page, world);
  const { id } = await postedReceipt(page, world);
  // 4 of the 5 cans are used; the older stock pays for them, so the delivery's own layer is untouched.
  await api(page, 'POST', '/stock-issues', { item_id: world.item.id, location_id: world.main.id, request_key: `e2e-use-${world.item.code}`, reason: 'Job', draw: [{ kind: 'SEALED', packaging_id: world.can.id, count: 4 }] });

  await openReversal(page, id);
  const line = page.getByTestId('reversal-line');
  await expect(line.getByTestId('reversal-changed')).toContainText('Stock has changed form');
  await expect(line.getByTestId('reversal-draw')).toContainText('1 sealed Sealed 5 L can + 7 l loose = 12 l');
  await expect(line.getByTestId('reversal-check')).toContainText('on hand');

  // The person can still change it: more loose than is on hand is not coverable, which blocks the confirmation.
  await line.getByTestId('reversal-adjust').click();
  await line.getByLabel(/Loose to take/).fill('8');
  await expect(line.getByTestId('reversal-check')).toContainText('not on hand');
  await page.getByPlaceholder('Correction reason').fill('Wrong delivery');
  await expect(page.getByRole('button', { name: 'Confirm reversal' })).toBeDisabled();
  await line.getByLabel(/Loose to take/).fill('7');
  await expect(line.getByTestId('reversal-check')).toContainText('on hand');
  await page.getByRole('button', { name: 'Confirm reversal' }).click();
  await expect(page.getByText('REVERSED', { exact: true }).first()).toBeVisible();
});

test('a delivery that was already used from its own stock cannot be reversed and says why', async ({ page }) => {
  test.setTimeout(150_000);
  const world = await createStockedItem(page, 'e2e-rev-used');
  await activateGrouped(page, world);
  const { id } = await postedReceipt(page, world);
  // 25 L is more than the 20 L that was there before: the delivery's own stock is used too.
  await api(page, 'POST', '/stock-issues', { item_id: world.item.id, location_id: world.main.id, request_key: `e2e-use-${world.item.code}`, reason: 'Big job', draw: [{ kind: 'SEALED', packaging_id: world.can.id, count: 5 }] });

  await openReversal(page, id);
  await expect(page.getByTestId('reversal-blocker-LAYER_CONSUMED')).toContainText('already used');
  await page.getByPlaceholder('Correction reason').fill('Wrong delivery');
  await expect(page.getByRole('button', { name: 'Confirm reversal' })).toBeDisabled();
});

test('the reversal panel names the item and its unit even when more than 100 items sort ahead of it', async ({ page }) => {
  test.setTimeout(300_000);
  const removeFillers = await withMoreThanOneHundredItemsAhead(page);
  try {
    const world = await createStockedItem(page, 'e2e-rev-many');
    await activateGrouped(page, world);
    const { id } = await postedReceipt(page, world);

    await openReversal(page, id);
    const line = page.getByTestId('reversal-line');
    // The item is not on the first page of 100 (sorted by name), yet its label and unit come from its own lookup.
    await expect(line).toContainText(world.item.code);
    await expect(line.getByTestId('reversal-draw')).toContainText('2 sealed Sealed 5 L can + 2 l loose = 12 l');
  } finally {
    await removeFillers();
  }
});
