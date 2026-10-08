import { expect, test } from '@playwright/test';
import { activateGrouped, api, createQaOrder, createStockedItem } from './package-stock-support';

/**
 * Supplier QA for grouped items (ADR 0094): an inspection-required delivery arrives held, a QA disposition names the held packages it applies to, released
 * packages become usable and rejected ones are shown apart; a sealed can is only ever moved whole.
 */
test('held delivery is released and rejected by declaring the physical portion of each decision', async ({ page }) => {
  test.setTimeout(180_000);
  const world = await createStockedItem(page, 'e2e-qa');
  await activateGrouped(page, world); // 3 cans + 5 L loose usable = 20 L
  const draft = await (await createQaOrder(page, world))('12');
  const lineId = (await api(page, 'GET', `/goods-receipts/${draft.id}`)).data.lines[0].id;
  await api(page, 'PUT', `/goods-receipt-lines/${lineId}/package-composition`, { reason: 'Counted on arrival', composition: [{ packaging_id: world.can.id, count: 2 }, { quantity: '2' }] });
  await api(page, 'POST', `/goods-receipts/${draft.id}/post`);

  // On the item page: 20 L usable, the delivery held beside it, and nothing held can be issued.
  await page.goto(`/items/${world.item.id}`);
  const row = page.getByTestId('package-balance-row').first();
  await expect(row.getByTestId('breakdown-total')).toContainText(/= 20 l usable/i);
  await expect(row.getByTestId('breakdown-held')).toContainText(/Held for inspection: 2 × Sealed 5 L can/);
  await expect(row.getByTestId('breakdown-held')).toContainText(/= 12 l/i);

  // Pass 7 L: the screen proposes 1 can + 2 L loose, the person confirms.
  await page.goto(`/goods-receipts/${draft.id}`);
  await page.getByLabel('Disposition quantity').fill('7');
  await page.getByLabel('Disposition reason').fill('Inspected and passed');
  const step = page.getByTestId('qa-portion-step');
  await expect(step.getByTestId('qa-held')).toContainText('2 × Sealed 5 L can');
  await expect(step.getByTestId('qa-portion')).toContainText('1 sealed Sealed 5 L can + 2 l loose = 7 l');
  await expect(step.getByTestId('qa-portion')).toContainText('released to usable stock');
  await expect(step.getByTestId('qa-portion-check')).toContainText('held');
  await page.getByRole('button', { name: 'Post QA disposition' }).click();
  await expect(page.getByText('PASS · 7')).toBeVisible();

  await page.goto(`/items/${world.item.id}`);
  await expect(row.getByTestId('breakdown-total')).toContainText(/= 27 l usable/i);
  await expect(row.getByTestId('breakdown-sealed')).toContainText('4 × Sealed 5 L can');
  await expect(row.getByTestId('breakdown-held')).toContainText(/1 × Sealed 5 L can = 5 l/i);

  // Scrap the last held can: 3 L cannot be satisfied by a sealed can, so the proposal is empty and the change is refused on screen until it adds up.
  await page.goto(`/goods-receipts/${draft.id}`);
  await page.getByLabel('Disposition outcome').click();
  await page.getByRole('option', { name: 'SCRAP' }).click();
  await page.getByLabel('Disposition quantity').fill('3');
  await page.getByLabel('Disposition reason').fill('Damaged');
  await expect(page.getByTestId('qa-portion-step')).toContainText('No whole held packages and loose quantity add up to 3');
  await expect(page.getByRole('button', { name: 'Post QA disposition' })).toBeDisabled();
  await page.getByLabel('Disposition quantity').fill('5');
  await expect(page.getByTestId('qa-portion')).toContainText('1 sealed Sealed 5 L can = 5 l — rejected — awaiting scrap');
  await page.getByRole('button', { name: 'Post QA disposition' }).click();
  await expect(page.getByText('SCRAP · 5')).toBeVisible();

  await page.goto(`/items/${world.item.id}`);
  await expect(row.getByTestId('breakdown-held')).toHaveCount(0);
  await expect(row.getByTestId('breakdown-rejected')).toContainText(/Rejected, awaiting return or scrap: 1 × Sealed 5 L can = 5 l/i);
  const balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect([balance.total, balance.usable_total, balance.held.total, balance.rejected.total]).toEqual(['32.0000', '27.0000', '0.0000', '5.0000']);
});
