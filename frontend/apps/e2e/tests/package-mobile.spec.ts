import { expect, test, type Page } from '@playwright/test';
import { activateGrouped, api, createStockedItem, type PackageStockWorld } from './package-stock-support';

/**
 * Issue, transfer and receipt-reversal dialogs on a phone (about 390 px): every dialog fits the screen, the page never scrolls sideways, and the primary action stays reachable.
 */
test.use({ viewport: { width: 390, height: 800 } });

async function fits(page: Page, dialog: ReturnType<Page['getByTestId']>) {
  const box = await dialog.boundingBox();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(390);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  // nothing inside the dialog pokes out sideways
  const overflow = await dialog.evaluate((node) => node.scrollWidth - node.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
}

/** The control is not just inside the viewport but is the topmost thing there (not hidden under the bottom navigation or an overlay). */
async function reachable(page: Page, control: ReturnType<Page['getByTestId']>, within: string) {
  await control.scrollIntoViewIfNeeded();
  const box = (await control.boundingBox())!;
  const hit = await page.evaluate(([x, y, testid]) => document.elementFromPoint(x as number, y as number)?.closest(`[data-testid="${testid}"]`) !== null, [box.x + box.width / 2, box.y + box.height / 2, within]);
  expect(hit).toBe(true);
}

async function stocked(page: Page): Promise<PackageStockWorld> {
  const world = await createStockedItem(page, 'e2e-phone');
  await activateGrouped(page, world);

  return world;
}

test('issue and transfer dialogs fit a phone and their actions are reachable', async ({ page }) => {
  test.setTimeout(150_000);
  const world = await stocked(page);
  await page.goto(`/items/${world.item.id}`);
  const row = page.getByTestId('package-balance-row').first();

  await row.getByTestId('issue-stock').click();
  const issue = page.getByTestId('issue-dialog');
  await issue.getByLabel(/How much/).fill('7');
  await expect(issue.getByTestId('proposed-draw')).toBeVisible();
  await issue.getByRole('button', { name: /Adjust what is taken/ }).click();
  await fits(page, issue);
  await reachable(page, issue.getByTestId('issue-submit'), 'issue-dialog');
  await page.keyboard.press('Escape');

  await row.getByTestId('transfer-stock').click();
  const transfer = page.getByTestId('transfer-dialog');
  await transfer.getByLabel('To', { exact: true }).selectOption(String(world.karachi.id));
  await transfer.getByLabel(/How much do you want to move/).fill('7');
  await expect(transfer.getByTestId('transfer-arrival')).toBeVisible();
  await fits(page, transfer);
  await reachable(page, transfer.getByTestId('transfer-submit'), 'transfer-dialog');
});

test('the receipt reversal panel fits a phone', async ({ page }) => {
  test.setTimeout(150_000);
  const world = await stocked(page);
  const receipt = await world.receipt('12');
  const lineId = (await api(page, 'GET', `/goods-receipts/${receipt.id}`)).data.lines[0].id;
  await api(page, 'PUT', `/goods-receipt-lines/${lineId}/package-composition`, { reason: 'Counted on arrival', composition: [{ packaging_id: world.can.id, count: 2 }, { quantity: '2' }] });
  await api(page, 'POST', `/goods-receipts/${receipt.id}/post`);

  await page.goto(`/goods-receipts/${receipt.id}`);
  await page.getByRole('button', { name: 'Reverse receipt' }).click();
  const panel = page.getByTestId('reversal-panel');
  await expect(panel).toBeVisible();
  await panel.getByTestId('reversal-adjust').click();
  await fits(page, panel);
  await expect(page.getByRole('button', { name: 'Confirm reversal' })).toBeVisible();
});
