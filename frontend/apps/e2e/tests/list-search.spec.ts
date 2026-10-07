import { expect, test, type Page } from '@playwright/test';

// A search box answers at once but asks the server only once typing pauses.
async function searchRequests(page: Page, pattern: RegExp, act: () => Promise<void>) {
  const seen: string[] = [];
  const listener = (request: import('@playwright/test').Request) => {
    const url = decodeURIComponent(request.url());
    if (pattern.test(url) && url.includes('filter[search]')) seen.push(url);
  };
  page.on('request', listener);
  await act();
  await page.waitForTimeout(900);
  page.off('request', listener);
  return seen;
}

for (const { name, path, placeholder, api } of [
  { name: 'Purchase Orders', path: '/purchase-orders', placeholder: 'Search orders, suppliers, items…', api: /\/purchase-orders\?/ },
  { name: 'Goods Receipts', path: '/goods-receipts', placeholder: 'Search receipt number', api: /\/goods-receipts\?/ },
]) {
  test(`${name}: typing a search makes one request, not one per key`, async ({ page }) => {
    await page.goto(path);
    const box = page.getByPlaceholder(placeholder);
    await expect(box).toBeVisible();

    const requests = await searchRequests(page, api, async () => {
      await box.pressSequentially('00420', { delay: 60 });
      // The box shows everything typed straight away, before any request is made
      await expect(box).toHaveValue('00420');
    });
    expect(requests.length).toBe(1);
    expect(requests[0]).toContain('filter[search]=00420');
  });
}

test('Purchase Orders: Enter searches at once and emptying the box restores the list at once', async ({ page }) => {
  await page.goto('/purchase-orders?filter.status=ISSUED');
  const box = page.getByPlaceholder('Search orders, suppliers, items…');
  const table = page.locator('.po-screen table').first();
  await expect(table.locator('tbody tr').first()).toBeVisible();

  // Search an issued number, not a draft's display-only "Draft #id" label.
  await expect(table.locator('.po-order-link').first()).toBeVisible();
  const labels = await table.locator('.po-order-link').allTextContents();
  const wanted = labels.map(label => label.trim()).find(label => !label.startsWith('Draft #'))!;
  expect(wanted).toBeTruthy();

  // Enter does not wait out the pause
  await box.fill(wanted);
  await box.press('Enter');
  await expect(page).toHaveURL(url => url.searchParams.get('filter.search') === wanted, { timeout: 250 });
  await expect(table.locator('tbody')).toContainText(wanted);

  // Emptying the box restores the issued list at once (no pause) — and the footer never reads "0 records" on the way
  await box.fill('');
  await expect(page).not.toHaveURL(/filter\.search/, { timeout: 250 });
  await expect(page.getByRole('status').filter({ hasText: /of \d+ purchase orders/ })).toBeVisible();
});
