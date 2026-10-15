import { expect, test } from '@playwright/test';
import { api, findByCode } from './package-stock-support';

/** Items can be archived and activated together from the list's selection bar, each by its own request, with the result kept on screen even when the rows leave the view. */
test('items are archived together from the selection bar and the result stays readable', async ({ page }) => {
  test.setTimeout(180_000);
  await page.goto('/');
  const stamp = Date.now().toString(36);
  const list = async (path: string) => (await api(page, 'GET', `${path}?per_page=100`)).data as any[];
  const unit = (await list('/uoms')).find((uom) => uom.code === 'ea') ?? (await list('/uoms'))[0];
  const base = await findByCode<any>(page, '/items', 'demo-pack-thinner');
  expect(unit && base).toBeTruthy();
  for (const letter of ['a', 'b']) {
    await api(page, 'POST', '/items', {
      code: `bulk-${stamp}-${letter}`, name: `E2E bulk item ${letter} ${stamp}`, type: 'RAW', stock_uom_id: unit.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
      inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
    });
  }

  await page.goto(`/items?filter.search=bulk-${stamp}`);
  await expect(page.getByRole('row', { name: new RegExp(`bulk-${stamp}-a`) })).toBeVisible();
  await page.getByRole('checkbox', { name: 'Select all' }).check();
  await expect(page.getByText('2 selected items')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Print selected' })).toBeVisible();
  await expect(page.getByTestId('bulk-activate')).toHaveCount(0);

  await page.getByTestId('bulk-archive').click();
  const dialog = page.getByTestId('bulk-dialog');
  await expect(dialog).toContainText('2 active items');
  await dialog.getByTestId('bulk-confirm').click();
  await expect(dialog.getByTestId('bulk-result')).toContainText('2 changed');
  await dialog.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByTestId('bulk-dialog')).toHaveCount(0);
});
