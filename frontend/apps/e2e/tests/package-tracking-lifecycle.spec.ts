import { expect, test, type Page } from '@playwright/test';

/**
 * Acceptance test for grouped package tracking (docs/phases/package-balance-ui.md): one browser-driven lifecycle on a fresh item.
 *
 *   quantity only (20 L)  →  start grouped tracking from a count (3 sealed 5 L cans + 5 L loose)
 *   →  receive 12 L, recording what arrived (2 cans + 2 L loose)  →  issue 10 L with one can opened
 *   →  the package balance reads 4 cans + 2 L loose = 22 L at every step.
 *
 * Only the *starting* data (the item, its package, the opening stock and a draft delivery) is created through the API, because every one of those has its own
 * screens covered elsewhere; everything this feature adds is driven through the real UI.
 */
const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8090/api/v1';
const ORIGIN = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5176';

async function api<T = any>(page: Page, method: 'GET' | 'POST', path: string, data?: unknown): Promise<T> {
  const cookies = await page.context().cookies(API);
  const xsrf = decodeURIComponent(cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
  const response = await page.request.fetch(`${API}${path}`, {
    method,
    data: data as never,
    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Origin: ORIGIN, Referer: `${ORIGIN}/` },
  });
  expect(response.ok(), `${method} ${path} → ${response.status()} ${await response.text()}`).toBeTruthy();

  return (await response.json()) as T;
}

test('quantity only → grouped tracking → receive observed packages → issue with an opened can → updated balance', async ({ page }) => {
  test.setTimeout(180_000);
  await page.goto('/');
  const stamp = Date.now().toString(36);

  // --- Starting data (API) ---
  const list = async (path: string) => (await api(page, 'GET', `${path}${path.includes('?') ? '&' : '?'}per_page=100`)).data as any[];
  const litre = (await list('/uoms')).find((uom) => uom.code === 'l');
  const currency = (await list('/currencies')).find((c) => c.code === 'PKR');
  const supplier = (await list('/supplier-accounts'))[0];
  const warehouse = (await list('/warehouses')).find((w) => w.code === 'demo-main');
  const location = (await list('/locations')).find((l) => l.warehouse_id === warehouse.id && l.code === 'stock');
  const base = (await list('/items?filter[search]=demo-pack-thinner')).find((item) => item.code === 'demo-pack-thinner');
  expect([litre, currency, supplier, warehouse, location, base].every(Boolean)).toBeTruthy();

  const code = `e2e-xyz-${stamp}`;
  const item = (
    await api(page, 'POST', '/items', {
      code, name: `E2E XYZ emulsion ${stamp}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
      inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
    })
  ).data;
  await api(page, 'POST', '/item-packagings', { item_id: item.id, code: 'can-5l', name: 'Sealed 5 L can', stock_quantity: '5' });
  const today = new Date().toISOString().slice(0, 10);
  const order = (
    await api(page, 'POST', '/purchase-orders', {
      supplier_account_id: supplier.id, currency_id: currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual', order_date: today,
      lines: [{ line_number: 1, item_id: item.id, description: item.name, ordered_qty: '60', purchase_uom_id: litre.id, unit_price: '10', price_uom_id: litre.id, price_quantity: '1' }],
    })
  ).data;
  await api(page, 'POST', `/purchase-orders/${order.id}/issue`);
  const poLine = (await api(page, 'GET', `/purchase-orders/${order.id}?include=lines`)).data.lines[0];
  const receipt = async (quantity: string) =>
    (
      await api(page, 'POST', '/goods-receipts', {
        supplier_account_id: supplier.id, physical_arrival_at: new Date().toISOString(), posting_date: today,
        lines: [{ purchase_order_line_id: poLine.id, item_id: item.id, location_id: location.id, received_qty: quantity, received_uom_id: litre.id, provisional_unit_cost: '10',
          currency_id: currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual' }],
      })
    ).data;
  const opening = await receipt('20');
  await api(page, 'POST', `/goods-receipts/${opening.id}/post`);

  // --- 1. Quantity only: the card is one quiet line and the way in ---
  await page.goto(`/items/${item.id}`);
  const card = page.getByTestId('package-tracking-card');
  await expect(card).toContainText('Tracked by quantity only');
  await expect(card.getByTestId('package-breakdown')).toHaveCount(0);

  // --- 2. Activation wizard with an opening count ---
  await card.getByTestId('start-grouped-tracking').click();
  const wizard = page.getByTestId('activation-wizard');
  await expect(wizard.getByTestId('wizard-readiness')).toContainText('Nothing stands in the way');
  await wizard.getByTestId('wizard-next').click();
  await expect(wizard.getByTestId('wizard-levels')).toContainText('Sealed 5 L can');
  await wizard.getByTestId('wizard-next').click();
  const scope = wizard.getByTestId('wizard-scope').first();
  await expect(scope).toContainText(/stock\ total\ 20\ L/i);
  for (let i = 0; i < 3; i += 1) await scope.getByRole('button', { name: 'More sealed Sealed 5 L can' }).click();
  await expect(scope.getByTestId('wizard-loose')).toContainText(/Loose:\ 5\ L/i);
  await wizard.getByTestId('wizard-next').click();
  await wizard.getByLabel('Reason').fill('E2E: track emulsion by sealed can');
  await wizard.getByRole('checkbox').check();
  await wizard.getByTestId('wizard-activate').click();
  await expect(wizard).toHaveCount(0);

  await expect(card.getByTestId('tracking-status')).toContainText('Grouped package tracking');
  const row = card.getByTestId('package-balance-row').first();
  await expect(row.getByTestId('breakdown-sealed')).toContainText('3 × Sealed 5 L can');
  await expect(row.getByTestId('breakdown-loose')).toContainText(/5\ L\ loose/i);
  await expect(row.getByTestId('breakdown-total')).toContainText(/=\ 20\ L/i);

  // --- 3. Receive: a posting is held until what arrived is recorded ---
  const delivery = await receipt('12');
  await page.goto(`/goods-receipts/${delivery.id}`);
  await expect(page.getByRole('button', { name: 'Post receipt' })).toBeDisabled();
  const panel = page.getByTestId('receipt-composition-panel');
  await expect(panel).toContainText(/Delivered:\ 12\ L/i);
  await panel.getByRole('button', { name: 'More sealed Sealed 5 L can' }).click();
  await panel.getByRole('button', { name: 'More sealed Sealed 5 L can' }).click();
  await expect(panel.getByTestId('receipt-loose')).toContainText(/Loose:\ 2\ L/i);
  await panel.getByTestId('save-composition').click();
  await expect(panel).toContainText('Recorded.');
  await expect(page.getByRole('button', { name: 'Post receipt' })).toBeEnabled();
  await page.getByRole('button', { name: 'Post receipt' }).click();
  await page.getByRole('button', { name: 'Confirm posting' }).click();
  await expect(page.getByRole('button', { name: 'Post receipt' })).toHaveCount(0);

  await page.goto(`/items/${item.id}`);
  const afterReceipt = page.getByTestId('package-balance-row').first();
  await expect(afterReceipt.getByTestId('breakdown-sealed')).toContainText('5 × Sealed 5 L can');
  await expect(afterReceipt.getByTestId('breakdown-loose')).toContainText(/7\ L\ loose/i);
  await expect(afterReceipt.getByTestId('breakdown-total')).toContainText(/=\ 32\ L/i);

  // --- 4. Issue 10 L: loose first, then one can opened for the last 3 L ---
  await afterReceipt.getByTestId('issue-stock').click();
  const issue = page.getByTestId('issue-dialog');
  await issue.getByLabel(/How much do you need/).fill('10');
  const proposed = issue.getByTestId('proposed-draw');
  await expect(proposed).toContainText(/Take\ 7\ L\ loose/i);
  await expect(proposed).toContainText(/Open\ 1\ Sealed\ 5\ L\ can\ and\ take\ 3\ L\ from\ it/i);
  await expect(issue.getByTestId('leaves')).toContainText('4 × Sealed 5 L can');
  await expect(issue.getByTestId('leaves')).toContainText(/2\ L\ loose/i);
  await expect(issue.getByTestId('leaves')).toContainText(/=\ 22\ L/i);
  await issue.getByLabel('Job or reference (optional)').fill('E2E-JOB');
  await issue.getByTestId('issue-submit').click();
  await expect(issue).toHaveCount(0);

  // --- 5. The updated package balance, in the UI and on the server ---
  const final = page.getByTestId('package-balance-row').first();
  await expect(final.getByTestId('breakdown-sealed')).toContainText('4 × Sealed 5 L can');
  await expect(final.getByTestId('breakdown-loose')).toContainText(/2\ L\ loose/i);
  await expect(final.getByTestId('breakdown-total')).toContainText(/=\ 22\ L/i);
  const balance = (await api(page, 'GET', `/package-balances?item_id=${item.id}`)).data[0];
  expect([balance.total, balance.loose, balance.sealed[0].count, balance.fresh]).toEqual(['22.0000', '2.0000', 4, true]);
});
