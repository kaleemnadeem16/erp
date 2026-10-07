import { expect, type Page } from '@playwright/test';

/** API-side setup shared by the package-tracking specs: a fresh item with stock, optionally already on grouped tracking. */
const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8090/api/v1';
const ORIGIN = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5176';

export async function api<T = any>(page: Page, method: 'GET' | 'POST' | 'PUT', path: string, data?: unknown): Promise<T> {
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

export interface PackageStockWorld {
  item: { id: number; code: string; name: string };
  can: { id: number };
  order: { id: number };
  poLine: { id: number };
  litre: { id: number };
  currency: { id: number };
  supplier: { id: number };
  main: { id: number };
  karachi: { id: number; name: string };
  receipt: (quantity: string, locationId?: number) => Promise<{ id: number }>;
}

async function list(page: Page, path: string) {
  return (await api(page, 'GET', `${path}${path.includes('?') ? '&' : '?'}per_page=100`)).data as any[];
}

/** A fresh FIFO item in litres with a 5 L can, 20 L of stock at the demo main store (and nothing at Karachi), not yet on grouped tracking. */
export async function createStockedItem(page: Page, prefix: string): Promise<PackageStockWorld> {
  await page.goto('/');
  const stamp = Date.now().toString(36);
  const litre = (await list(page, '/uoms')).find((uom) => uom.code === 'l');
  const currency = (await list(page, '/currencies')).find((c) => c.code === 'PKR');
  const supplier = (await list(page, '/supplier-accounts'))[0];
  const warehouses = await list(page, '/warehouses');
  const locations = await list(page, '/locations');
  const warehouse = warehouses.find((w) => w.code === 'demo-main');
  const karachiWarehouse = warehouses.find((w) => w.code === 'demo-khi');
  const main = locations.find((l) => l.warehouse_id === warehouse.id && l.code === 'stock');
  const karachi = locations.find((l) => l.warehouse_id === karachiWarehouse.id && l.code === 'stock');
  const base = (await list(page, '/items')).find((item) => item.code === 'demo-pack-thinner');
  expect([litre, currency, supplier, warehouse, karachiWarehouse, main, karachi, base].every(Boolean)).toBeTruthy();

  const item = (
    await api(page, 'POST', '/items', {
      code: `${prefix}-${stamp}`, name: `E2E ${prefix} emulsion ${stamp}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
      inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
    })
  ).data;
  const can = (await api(page, 'POST', '/item-packagings', { item_id: item.id, code: 'can-5l', name: 'Sealed 5 L can', stock_quantity: '5' })).data;
  const today = new Date().toISOString().slice(0, 10);
  const order = (
    await api(page, 'POST', '/purchase-orders', {
      supplier_account_id: supplier.id, currency_id: currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual', order_date: today,
      lines: [{ line_number: 1, item_id: item.id, description: item.name, ordered_qty: '100', purchase_uom_id: litre.id, unit_price: '10', price_uom_id: litre.id, price_quantity: '1' }],
    })
  ).data;
  await api(page, 'POST', `/purchase-orders/${order.id}/issue`);
  const poLine = (await api(page, 'GET', `/purchase-orders/${order.id}?include=lines`)).data.lines[0];
  const receipt = async (quantity: string, locationId: number = main.id) =>
    (
      await api(page, 'POST', '/goods-receipts', {
        supplier_account_id: supplier.id, physical_arrival_at: new Date().toISOString(), posting_date: today,
        lines: [{ purchase_order_line_id: poLine.id, item_id: item.id, location_id: locationId, received_qty: quantity, received_uom_id: litre.id, provisional_unit_cost: '10',
          currency_id: currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual' }],
      })
    ).data;
  const opening = await receipt('20');
  await api(page, 'POST', `/goods-receipts/${opening.id}/post`);

  return { item, can, order, poLine, litre, currency, supplier, main, karachi: { id: karachi.id, name: karachi.name }, receipt };
}

/** Puts the item on grouped tracking through the API from a count of 3 sealed cans + 5 L loose at the main store. */
export async function activateGrouped(page: Page, world: PackageStockWorld): Promise<void> {
  const count = (
    await api(page, 'POST', '/package-checkpoints', {
      item_id: world.item.id, location_id: world.main.id, request_key: `e2e-count-${world.item.code}`, reason: 'E2E opening count', expected_quantity: '20',
      composition: [{ packaging_id: world.can.id, count: 3 }, { quantity: '5' }],
    })
  ).data;
  await api(page, 'POST', `/items/${world.item.id}/package-tracking/activate`, { request_key: `e2e-activate-${world.item.code}`, reason: 'E2E', checkpoints: [count.operation_id] });
}
