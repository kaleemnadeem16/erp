import { execFileSync } from 'node:child_process';
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
  const base = (await list(page, '/items?filter[search]=demo-pack-thinner')).find((item) => item.code === 'demo-pack-thinner');
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

/** A second person in their own browser session (the demo count reviewer posts counts that the demo user created; nobody posts their own). */
export async function signInAs(browser: import('@playwright/test').Browser, email: string): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: ORIGIN });
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_USER_PASSWORD ?? '!User@Erp@123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).not.toHaveURL(/\/login/);

  return page;
}

/** A draft physical count of the main warehouse holding one line for the item, made through the API as the signed-in person. */
export async function draftCountWithLine(page: Page, world: PackageStockWorld): Promise<{ countId: number; lineId: number }> {
  const warehouseId = (await list(page, '/locations')).find((l) => l.id === world.main.id).warehouse_id;
  const count = (await api(page, 'POST', '/physical-counts', { warehouse_id: warehouseId })).data;
  const line = (await api(page, 'POST', `/physical-counts/${count.id}/lines`, { item_id: world.item.id, location_id: world.main.id })).data;

  return { countId: count.id, lineId: line.id };
}

/** An issued purchase order whose line requires QA inspection, for the same item; returns a function that drafts a receipt against it. */
export async function createQaOrder(
  page: Page,
  world: PackageStockWorld,
  /** Another supplier or stock location, for fixtures that need a delivery from elsewhere (mixed-supplier / mixed-warehouse refusals). */
  options: { supplierId?: number; locationId?: number } = {},
): Promise<(quantity: string) => Promise<{ id: number }>> {
  const supplierId = options.supplierId ?? world.supplier.id;
  const locationId = options.locationId ?? world.main.id;
  const today = new Date().toISOString().slice(0, 10);
  const order = (
    await api(page, 'POST', '/purchase-orders', {
      supplier_account_id: supplierId, currency_id: world.currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual', order_date: today,
      lines: [{ line_number: 1, item_id: world.item.id, description: world.item.name, ordered_qty: '100', purchase_uom_id: world.litre.id, unit_price: '12', price_uom_id: world.litre.id, price_quantity: '1', qa_required: true }],
    })
  ).data;
  await api(page, 'POST', `/purchase-orders/${order.id}/issue`);
  const poLine = (await api(page, 'GET', `/purchase-orders/${order.id}?include=lines`)).data.lines[0];

  return async (quantity: string) =>
    (
      await api(page, 'POST', '/goods-receipts', {
        supplier_account_id: supplierId, physical_arrival_at: new Date().toISOString(), posting_date: today,
        lines: [{ purchase_order_line_id: poLine.id, item_id: world.item.id, location_id: locationId, received_qty: quantity, received_uom_id: world.litre.id, provisional_unit_cost: '12',
          currency_id: world.currency.id, fx_rate: '1.000000000', fx_rate_date: today, fx_rate_type: 'SPOT', fx_rate_source: 'manual' }],
      })
    ).data;
}

export interface RejectedDelivery {
  receiptId: number;
  lineId: number;
  number: string;
}

/**
 * A posted QA delivery of the item with rejected stock on hand, built through the API the way the QA screen would: 12 L received, the rest passed, and
 * `rejectedQuantity` decided as `outcome`. For a grouped item (`cans` given) the delivery is observed as that many sealed cans plus loose litres and the
 * rejected portion is `rejectedCans` whole cans — a sealed can is rejected whole.
 */
export async function rejectedDelivery(
  page: Page,
  world: PackageStockWorld,
  draftDelivery: (quantity: string) => Promise<{ id: number }>,
  outcome: 'RETURN_TO_SUPPLIER' | 'SCRAP',
  options: { grouped?: boolean; rejectedQuantity?: string } = {},
): Promise<RejectedDelivery> {
  const grouped = options.grouped ?? false;
  const rejectedQuantity = options.rejectedQuantity ?? (grouped ? '5' : '4');
  const total = grouped ? '12' : '10';
  const draft = await draftDelivery(total);
  const lineId = (await api(page, 'GET', `/goods-receipts/${draft.id}`)).data.lines[0].id;
  if (grouped) {
    await api(page, 'PUT', `/goods-receipt-lines/${lineId}/package-composition`, { reason: 'Counted on arrival', composition: [{ packaging_id: world.can.id, count: 2 }, { quantity: '2' }] });
  }
  const posted = (await api(page, 'POST', `/goods-receipts/${draft.id}/post`)).data;
  const decide = (disposition: string, qty: string, package_portion?: unknown) =>
    api(page, 'POST', `/goods-receipt-lines/${lineId}/quality-dispositions`, { disposition, qty, reason: 'E2E inspection', source_disposition_id: null, ...(package_portion ? { package_portion } : {}) });
  if (grouped) {
    await decide('PASS', '7', [{ kind: 'SEALED', packaging_id: world.can.id, count: 1 }, { kind: 'LOOSE', quantity: '2' }]);
    await decide(outcome, rejectedQuantity, [{ kind: 'SEALED', packaging_id: world.can.id, count: 1 }]);
  } else {
    await decide('PASS', String(Number(total) - Number(rejectedQuantity)));
    await decide(outcome, rejectedQuantity);
  }

  return { receiptId: draft.id, lineId, number: posted.number };
}

export const EXIT_PERSONAS = {
  /** May prepare Supplier Returns and Scrap but not post them. */
  preparer: 'e2e-exit-preparer@erp.x1techs.com',
  /** May prepare and post, but is limited to their own organisation units (and so sees no warehouse's stock). */
  limited: 'e2e-exit-limited@erp.x1techs.com',
} as const;

/**
 * Creates (or refreshes) the two people the rejected-stock exit specs sign in as, by running PHP in the dev `app` container — the same route the other
 * specs avoid, but record scope can only be assigned through the access tables, not the API a spec can reach. Returns false when the container is not
 * reachable (for example a CI job that runs the backend natively), so the spec can skip rather than fail.
 */
export function ensureExitPersonas(): boolean {
  const code = `
    $registrar = app(\\Spatie\\Permission\\PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(1);
    $password = config('demo.user.password');
    $make = function (string $email, string $name, array $permissions) use ($password) {
      foreach ($permissions as $permission) { \\Spatie\\Permission\\Models\\Permission::findOrCreate($permission, 'web'); }
      $user = \\App\\Models\\User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'company_id' => 1]);
      $user->forceFill(['email_verified_at' => now()])->save();
      $user->syncPermissions($permissions);
      return $user;
    };
    $read = ['purchasing.goods-receipt.view', 'inventory.package-state.view', 'inventory.stock.view'];
    $make('${EXIT_PERSONAS.preparer}', 'E2E Exit Preparer', array_merge($read, ['purchasing.supplier-return.create', 'inventory.scrap.create']));
    $granted = array_merge($read, ['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.scrap.create', 'inventory.scrap.post']);
    $limited = $make('${EXIT_PERSONAS.limited}', 'E2E Exit Limited', []);
    // Record scope limits what a *role* grants, so this person holds the capabilities only through the limited role (a direct grant would bypass the limit).
    $role = \\Spatie\\Permission\\Models\\Role::query()->firstOrCreate(['team_id' => 1, 'name' => 'E2E limited exits', 'guard_name' => 'web']);
    foreach ($granted as $permission) { \\Spatie\\Permission\\Models\\Permission::findOrCreate($permission, 'web'); }
    $role->syncPermissions($granted);
    $limited->assignRole($role);
    \\App\\Modules\\Administration\\Models\\AccessScopeLimit::query()->updateOrCreate(['user_id' => $limited->id, 'role_id' => $role->id], ['scope' => 'MY_UNITS']);
    echo 'ok';
  `;
  try {
    const output = execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'tinker', '--execute', code], { cwd: new URL('../../../../', import.meta.url).pathname, encoding: 'utf8', timeout: 90_000 });

    return output.includes('ok');
  } catch {
    return false;
  }
}

/**
 * Makes sure more than 100 items sort ahead of the items the specs create (by name), so a screen that looks an item up in the first list page of 100
 * cannot find it. Idempotent: it only creates the fillers that are missing, named "AAA filler …".
 */
export async function ensureMoreThanOneHundredItemsAhead(page: Page): Promise<void> {
  const existing = (await api(page, 'GET', '/items?filter[search]=aaa-filler&per_page=1')).meta.total as number;
  if (existing >= 105) return;
  const litre = (await list(page, '/uoms')).find((uom) => uom.code === 'l');
  const base = (await list(page, '/items?filter[search]=demo-pack-thinner')).find((item) => item.code === 'demo-pack-thinner');
  const stamp = Date.now().toString(36);
  for (let n = existing; n < 105; n++) {
    await api(page, 'POST', '/items', {
      code: `aaa-filler-${stamp}-${n}`, name: `AAA filler ${stamp} ${n}`, type: 'RAW', stock_uom_id: litre.id, category_id: base.category_id, valuation_method: 'FIFO', tracking: 'NONE',
      inventory_account_id: base.inventory_account_id, is_stocked: true, is_purchasable: true, is_sellable: false,
    });
  }
}
