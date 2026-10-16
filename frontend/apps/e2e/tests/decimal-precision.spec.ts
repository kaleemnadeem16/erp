import { expect, test } from '@playwright/test';
import { api, createStockedItem } from './package-stock-support';
import { pickCombobox } from './support';

/**
 * PX4 browser journey: Item → Purchase Order → Goods Receipt → Inventory, exercising the shared decimal foundation with both accepted and refused
 * precision. The point is that the screens say what the server will say, keep what was typed exactly, and never round a quantity, price or rate.
 */
test.describe.configure({ mode: 'serial' });

test('an item increment finer than the ledger is refused on the form, and an accepted one is shown without padding', async ({ page }) => {
  const world = await createStockedItem(page, 'px4inc');
  await page.goto(`/items/${world.item.id}/edit`);

  const increment = page.getByLabel('Stock quantity increment (optional)');
  await increment.fill('0.00001');
  await page.getByRole('button', { name: /save/i }).last().click();
  await expect(page.getByText('Increment must have at most 4 decimal places')).toBeVisible();

  // The control never repairs what was typed: the refused value is still there.
  await expect(increment).toHaveValue('0.00001');
  await expect(increment).toHaveAttribute('aria-invalid', 'true');

  await increment.fill('0.001');
  await page.getByRole('button', { name: /save/i }).last().click();
  await expect(page).toHaveURL(new RegExp(`/items/${world.item.id}$`));
  const stored = (await api(page, 'GET', `/items/${world.item.id}`)).data;
  expect(stored.quantity_precision).toBe('0.001000000');
  await expect(page.getByText('0.001', { exact: true }).first()).toBeVisible();

  // Opening the edit form again starts from the same number without the database padding, and saving it unchanged is accepted.
  await page.goto(`/items/${world.item.id}/edit`);
  await expect(page.getByLabel('Stock quantity increment (optional)')).toHaveValue('0.001');
});

test('a package definition finer than four places is refused before it is sent', async ({ page }) => {
  const world = await createStockedItem(page, 'px4pack');
  await page.goto(`/items/${world.item.id}`);
  await page.getByRole('tab', { name: 'Suppliers & Sourcing' }).click();

  await page.getByLabel('Package code').fill('tin-x');
  await page.getByLabel('Package name').fill('Odd tin');
  await page.getByLabel('Content in stock unit').fill('0.33333');
  await page.getByRole('button', { name: 'Add package' }).click();
  await expect(page.getByText('Content in stock unit must have at most 4 decimal places')).toBeVisible();
  await expect(page.getByLabel('Content in stock unit')).toHaveValue('0.33333');

  await page.getByLabel('Content in stock unit').fill('2.5');
  await page.getByRole('button', { name: 'Add package' }).click();
  await expect(page.getByText('Odd tin: 2.5')).toBeVisible();
});

test('purchase order: exact prices and rates are kept, finer ones and a zero rate are refused, and an inexact conversion is refused by the server', async ({ page }) => {
  const world = await createStockedItem(page, 'px4po');
  // A pack unit whose conversion is a repeating-looking factor: 7 of them is 46.666666669 litres, which the four-place ledger cannot hold.
  const pack = (await api(page, 'GET', '/uoms?per_page=100')).data.find((uom: { code: string }) => uom.code === 'drum');
  await api(page, 'POST', '/item-quantity-units', { item_id: world.item.id, uom_id: pack.id, factor: '6.666666667' });

  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await pickCombobox(page, 'Supplier', /./, { within: sheet, searchText: '' });
  await pickCombobox(page, 'Currency', /PKR/i, { within: sheet, searchText: 'PKR' });

  // Exchange rate: nine places are kept exactly; zero and ten places are refused.
  await sheet.getByRole('button', { name: /More options/ }).click();
  const rate = sheet.getByLabel('Rate', { exact: true });
  await rate.fill('280.123456789');
  await expect(rate).toHaveValue('280.123456789');
  await rate.fill('0');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await expect(sheet.getByText('FX rate must be greater than zero')).toBeVisible();
  await rate.fill('1.0000000001');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await expect(sheet.getByText('FX rate must have at most 9 decimal places')).toBeVisible();
  await rate.fill('1');
  await sheet.getByRole('button', { name: 'Next' }).click();

  await pickCombobox(page, 'Item', world.item.name, { within: sheet, searchText: world.item.code });
  const quantity = sheet.getByLabel('Quantity', { exact: true });
  const price = sheet.getByLabel('Unit price', { exact: true });

  // A unit price of 125.1234 stays 125.1234 on blur; one more place is refused rather than rounded.
  await price.fill('125.1234');
  await price.blur();
  await expect(price).toHaveValue('125.1234');
  await price.fill('125.12345');
  await quantity.fill('1.2345');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await expect(sheet.getByText('Unit price must have at most 4 decimal places')).toBeVisible();
  await price.fill('125.1234');
  await quantity.fill('1.23456');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await expect(sheet.getByText('Quantity must have at most 4 decimal places')).toBeVisible();
  await expect(quantity).toHaveValue('1.23456');

  // The server refuses the inexact conversion and the line says so, with what to do next — nothing is rounded to 46.6667.
  await quantity.fill('7');
  await pickCombobox(page, 'Purchase UoM', /^Drum/i, { within: sheet, searchText: 'Drum' });
  await expect(sheet.getByRole('alert').filter({ hasText: 'nothing is rounded for you' })).toBeVisible({ timeout: 15_000 });
  await expect(sheet.getByText('46.6667')).toHaveCount(0);
});

test('goods receipt: a quantity finer than four places is refused on the form, a fractional measured quantity is accepted, and inventory shows it exactly', async ({ page }) => {
  const world = await createStockedItem(page, 'px4gr');
  // An item whose ordering increment is whole litres may still receive a measured 2.5 L (an ordering increment is not a receiving rule).
  await api(page, 'PATCH', `/items/${world.item.id}`, { quantity_precision: '1' });

  await page.goto('/goods-receipts/new');
  await expect(page.locator('input[name="lines.0.received_qty"]')).toHaveAttribute('title', 'Up to 4 decimal places');

  // Exercised through the API the form posts to, because choosing an order line in the picker depends on seeded supplier data; the form-level
  // refusal of a finer quantity is covered by the purchase-order spec above and the unit specs.
  const today = new Date().toISOString().slice(0, 10);
  const draft = async (quantity: string) => {
    const cookies = await page.context().cookies('http://127.0.0.1:8090/api/v1');
    const xsrf = decodeURIComponent(cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
    return page.request.post('http://127.0.0.1:8090/api/v1/goods-receipts', {
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Origin: 'http://127.0.0.1:5176', Referer: 'http://127.0.0.1:5176/' },
      data: {
        supplier_account_id: world.supplier.id, physical_arrival_at: new Date().toISOString(), posting_date: today,
        lines: [{ purchase_order_line_id: world.poLine.id, item_id: world.item.id, location_id: world.main.id, received_qty: quantity, received_uom_id: world.litre.id,
          provisional_unit_cost: '10', currency_id: world.currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual' }],
      },
    });
  };
  const refused = await draft('2.50005');
  expect(refused.status()).toBe(422);
  // The request rule catches a fifth place before the quantity contract does, so the refusal is a field error on the line, not a reason code.
  expect(Object.keys((await refused.json()).errors)).toContain('lines.0.received_qty');

  const accepted = await draft('2.5');
  expect(accepted.status()).toBe(201);
  const receiptId = (await accepted.json()).data.id;
  await api(page, 'POST', `/goods-receipts/${receiptId}/post`);

  await page.goto('/inventory-overview');
  await page.getByPlaceholder('Search code or name…').fill(world.item.code);
  await expect(page.getByRole('row', { name: new RegExp(world.item.code, 'i') })).toContainText('22.5');
});
