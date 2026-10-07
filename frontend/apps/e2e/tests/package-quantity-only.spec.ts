import { expect, test } from '@playwright/test';
import { api, createStockedItem, draftCountWithLine } from './package-stock-support';

/**
 * An item that was never switched to grouped package tracking is untouched by all of it: no package controls on its page, a plain quantity on its count
 * line, and a receipt reversal that is just a reason.
 */
test('a quantity-only item shows no package controls, counts by a single quantity and reverses with a reason alone', async ({ page }) => {
  test.setTimeout(150_000);
  const world = await createStockedItem(page, 'e2e-qty-only'); // 20 L, deliberately not activated

  await page.goto(`/items/${world.item.id}`);
  await expect(page.getByText(/quantity only/i).first()).toBeVisible();
  await expect(page.getByTestId('transfer-stock')).toHaveCount(0);
  await expect(page.getByTestId('issue-stock')).toHaveCount(0);

  const { countId } = await draftCountWithLine(page, world);
  await page.goto(`/physical-counts/${countId}`);
  await expect(page.getByTestId('grouped-count-cell')).toHaveCount(0);
  await expect(page.getByTestId('count-by-package')).toHaveCount(0);
  const input = page.locator('tbody input[type="number"]').first();
  await input.fill('19');
  await page.getByRole('button', { name: 'Save count' }).click();
  await expect.poll(async () => (await api(page, 'GET', `/physical-counts/${countId}?include=lines`)).data.lines[0].counted_qty).toBe('19.0000');

  const receipt = await world.receipt('4');
  await api(page, 'POST', `/goods-receipts/${receipt.id}/post`);
  await page.goto(`/goods-receipts/${receipt.id}`);
  await page.getByRole('button', { name: 'Reverse receipt' }).click();
  await expect(page.getByText('Reverse this receipt?')).toBeVisible();
  await expect(page.getByTestId('reversal-panel')).toHaveCount(0);
  await page.getByPlaceholder('Correction reason').fill('Wrong delivery');
  await page.getByRole('button', { name: 'Confirm reversal' }).click();
  await expect(page.getByText('REVERSED', { exact: true }).first()).toBeVisible();
});
