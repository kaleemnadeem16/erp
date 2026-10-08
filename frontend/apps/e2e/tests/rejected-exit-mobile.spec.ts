import { expect, test, type Locator, type Page } from '@playwright/test';
import { activateGrouped, createQaOrder, createStockedItem, rejectedDelivery } from './package-stock-support';

/**
 * Supplier Return and Scrap on a phone (about 390 px): the form, the draft's preview and the post confirmation fit the screen, the page never scrolls
 * sideways, and the primary action stays reachable (not hidden under the bottom navigation or an overlay).
 */
test.use({ viewport: { width: 390, height: 800 } });

async function noSidewaysScroll(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
}

/** The control is the topmost thing at its own centre, so nothing covers it. */
async function reachable(page: Page, control: Locator) {
  await control.scrollIntoViewIfNeeded();
  const box = (await control.boundingBox())!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(390);
  const covered = await control.evaluate((node, [x, y]) => !node.contains(document.elementFromPoint(x as number, y as number)), [box.x + box.width / 2, box.y + box.height / 2]);
  expect(covered).toBe(false);
}

test('the return form, the draft preview and the post confirmation work on a phone', async ({ page }) => {
  test.setTimeout(240_000);
  const world = await createStockedItem(page, 'e2e-xphone');
  await activateGrouped(page, world);
  const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'RETURN_TO_SUPPLIER', { grouped: true });

  await page.goto(`/supplier-returns/new?receipt=${delivery.receiptId}&line=${delivery.lineId}`);
  await expect(page.getByTestId('exit-line-remaining')).toBeVisible();
  await page.getByRole('button', { name: /Shipping and expected credit/ }).click();
  await noSidewaysScroll(page);
  await expect(page.getByTestId('exit-portion-chosen')).toBeVisible();
  await reachable(page, page.getByTestId('exit-save'));

  await page.getByLabel('Reason', { exact: true }).fill('Failed viscosity test');
  await page.getByTestId('exit-save').click();
  await expect(page).toHaveURL(/\/supplier-returns\/\d+$/);
  await expect(page.getByTestId('exit-line')).toBeVisible();
  await noSidewaysScroll(page);

  await expect(page.getByTestId('exit-post')).toBeEnabled(); // the preview has arrived and the layout has settled
  await reachable(page, page.getByTestId('exit-post'));
  await page.getByTestId('exit-post').click();
  const dialog = page.getByTestId('exit-confirm');
  await expect(dialog).toBeVisible();
  const box = (await dialog.boundingBox())!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(390);
  expect(box.y + box.height).toBeLessThanOrEqual(800);
  await noSidewaysScroll(page);
  await reachable(page, page.getByTestId('exit-confirm-action'));
  await page.getByTestId('exit-confirm-action').click();
  await expect(page.getByTestId('exit-frozen-line')).toBeVisible();
  await noSidewaysScroll(page);

  // The list shows the document as a card, not a table that scrolls sideways.
  await page.goto('/supplier-returns');
  await expect(page.getByTestId('exit-list')).toBeVisible();
  await noSidewaysScroll(page);
});
