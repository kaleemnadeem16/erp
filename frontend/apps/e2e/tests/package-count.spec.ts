import { expect, test } from '@playwright/test';
import { activateGrouped, api, createStockedItem, draftCountWithLine, signInAs } from './package-stock-support';

/**
 * Package-aware physical count (docs/phases/package-balance-ui.md §5): counting by sealed packages plus loose quantity, composition-only versus
 * quantity variance, the stale-line refresh, and the found-stock cost. The demo user counts; the demo count reviewer (a different person) posts.
 */
const SUPERVISOR = 'demo-count-reviewer@erp.x1techs.com';

async function countByPackage(page: import('@playwright/test').Page, cans: number, loose: string) {
  await page.getByTestId('count-by-package').click();
  const dialog = page.getByTestId('count-composition-dialog');
  for (let i = 0; i < cans; i++) await dialog.getByRole('button', { name: /^More / }).click();
  if (loose) await dialog.getByLabel(/Loose/).fill(loose);
  await dialog.getByTestId('count-composition-submit').click();
  await expect(dialog).toHaveCount(0);
}

test('the same litres in different cans is a composition fix: no stock movement, new breakdown', async ({ page, browser }) => {
  test.setTimeout(150_000);
  const world = await createStockedItem(page, 'e2e-count-comp');
  await activateGrouped(page, world); // 3 cans + 5 L loose = 20 L
  const { countId } = await draftCountWithLine(page, world);

  await page.goto(`/physical-counts/${countId}`);
  const cell = page.getByTestId('grouped-count-cell');
  await expect(cell).toContainText('Not counted');
  await countByPackage(page, 4, '');
  await expect(cell.getByTestId('breakdown-sealed')).toContainText('4 × Sealed 5 L can');
  await expect(cell.getByTestId('breakdown-total')).toContainText(/= 20 l/i);
  await expect(page.getByTestId('count-verdict')).toContainText('no stock movement');
  await expect(page.getByText("You can't post your own count")).toBeVisible();

  const movesBefore = (await api(page, 'GET', `/stock-moves?filter[item_id]=${world.item.id}&per_page=100`)).data.length;
  const supervisor = await signInAs(browser, SUPERVISOR);
  await supervisor.goto(`/physical-counts/${countId}`);
  await supervisor.getByRole('button', { name: 'Post count' }).click();
  await expect(supervisor.getByText('POSTED', { exact: true })).toBeVisible();

  const balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect([balance.sealed[0].count, balance.loose, balance.total]).toEqual([4, '0.0000', '20.0000']);
  expect((await api(page, 'GET', `/stock-moves?filter[item_id]=${world.item.id}&per_page=100`)).data.length).toBe(movesBefore);
  await supervisor.context().close();
});

test('a line goes stale when stock moves, is refreshed and recounted, and found stock needs a confirmed cost', async ({ page, browser }) => {
  test.setTimeout(180_000);
  const world = await createStockedItem(page, 'e2e-count-var');
  await activateGrouped(page, world);
  const { countId } = await draftCountWithLine(page, world);

  await page.goto(`/physical-counts/${countId}`);
  await countByPackage(page, 3, '5');
  await expect(page.getByTestId('count-verdict')).toContainText('no stock movement');

  // Someone issues 2 L after the line was captured: it is stale and the count cannot post.
  await api(page, 'POST', '/stock-issues', {
    item_id: world.item.id, location_id: world.main.id, request_key: `e2e-between-${world.item.code}`, reason: 'Job',
    draw: [{ kind: 'LOOSE', quantity: '2' }],
  });
  await page.reload();
  await expect(page.getByTestId('stale-line')).toBeVisible();
  await expect(page.getByTestId('post-blocked-stale')).toBeVisible();
  await expect(page.getByTestId('count-by-package')).toHaveCount(0);

  const supervisor = await signInAs(browser, SUPERVISOR);
  await supervisor.goto(`/physical-counts/${countId}`);
  await expect(supervisor.getByTestId('stale-line')).toBeVisible();
  await expect(supervisor.getByRole('button', { name: 'Post count' })).toBeDisabled();

  // Refresh clears the count; the book is now 18 L. Counting 3 cans + 5 L = 20 L finds 2 L more.
  await page.getByTestId('refresh-line').click();
  await expect(page.getByTestId('stale-line')).toHaveCount(0);
  await expect(page.getByTestId('grouped-count-cell')).toContainText('Not counted');
  await countByPackage(page, 3, '5');
  await expect(page.getByTestId('count-verdict')).toContainText('+2');

  await supervisor.reload();
  const post = supervisor.getByRole('button', { name: 'Post count' });
  await expect(post).toBeDisabled();
  await expect(supervisor.getByTestId('count-verdict')).toContainText('needs a cost');
  const suggestion = supervisor.getByTestId('found-cost-suggestion');
  await expect(suggestion).toContainText(/last receipt/i);
  await expect(post).toBeDisabled(); // a suggestion is not a cost until it is chosen
  await suggestion.click();
  await expect(supervisor.getByTestId('found-cost')).toHaveValue('10');
  await post.click();
  await expect(supervisor.getByText('POSTED', { exact: true })).toBeVisible();

  const balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect([balance.sealed[0].count, balance.loose, balance.total]).toEqual([3, '5.0000', '20.0000']);
  await supervisor.context().close();
});

test('counting by package fits a phone: the dialog and the count page do not scroll sideways', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 390, height: 800 });
  const world = await createStockedItem(page, 'e2e-count-phone');
  await activateGrouped(page, world);
  const { countId } = await draftCountWithLine(page, world);

  await page.goto(`/physical-counts/${countId}`);
  await page.getByTestId('count-by-package').click();
  const dialog = page.getByTestId('count-composition-dialog');
  await expect(dialog).toBeVisible();
  const box = await dialog.boundingBox();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(390);
  await dialog.getByRole('button', { name: /^More / }).click();
  await dialog.getByLabel(/Loose/).fill('5');
  await expect(dialog.getByTestId('count-total')).toContainText('10');
  await dialog.getByTestId('count-composition-submit').click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByTestId('grouped-count-cell').getByTestId('breakdown-total')).toBeVisible();
  // The page itself must not be wider than the screen (the table scrolls inside its own container).
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
