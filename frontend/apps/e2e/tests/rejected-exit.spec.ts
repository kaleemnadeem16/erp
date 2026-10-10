import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { activateGrouped, api, createQaOrder, createStockedItem, EXIT_PERSONAS, rejectedDelivery, signInAs } from './package-stock-support';

/**
 * Supplier Return and Scrap for rejected stock (ADR 0097): the screens over the documents API. A QA decision moves nothing; these documents are the
 * actual exit, previewed before posting and frozen after.
 */
test('a grouped delivery’s rejected can goes back to the supplier: preview, post, frozen record', async ({ page }) => {
  test.setTimeout(240_000);
  const world = await createStockedItem(page, 'e2e-ret');
  await activateGrouped(page, world); // 3 cans + 5 L loose usable = 20 L
  const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'RETURN_TO_SUPPLIER', { grouped: true });

  // The delivery's QA panel offers the exit for the rejected stock.
  await page.goto(`/goods-receipts/${delivery.receiptId}`);
  await expect(page.getByTestId('rejected-stock-actions')).toContainText('5 rejected, still to return');
  await page.getByTestId('start-supplier_return').click();

  // The line is already chosen; the preview names the can that would leave, whole.
  await expect(page.getByTestId('exit-line')).toContainText(world.item.code);
  await expect(page.getByTestId('exit-line-available')).toContainText('5');
  await expect(page.getByTestId('exit-portion-chosen')).toContainText('1 sealed Sealed 5 L can');
  await expect(page.getByTestId('exit-portion-check')).toContainText('adds up');
  await expect(page.getByTestId('exit-line-remaining')).toContainText('0');
  await expect(page.getByTestId('outcome-change')).toHaveCount(0);

  // A reason is required; the expected credit is information only.
  await page.getByTestId('exit-save').click();
  await expect(page.getByText('Say why this stock is going back.')).toBeVisible();
  await page.getByLabel('Reason', { exact: true }).fill('Failed viscosity test');
  await page.getByRole('button', { name: /Shipping and expected credit/ }).click();
  await expect(page.getByText(/For your reference only/)).toBeVisible();
  await page.getByLabel('Expected credit (information only)').fill('450');
  await page.getByLabel('Credit note reference').fill('CN-E2E-1');
  // An amount needs its currency: refused beside the field, before anything is sent.
  await page.getByTestId('exit-save').click();
  await expect(page.getByTestId('credit-problem')).toContainText('Choose the currency');
  await page.getByRole('combobox', { name: 'Currency' }).click();
  await page.getByRole('option', { name: 'PKR' }).click();
  await page.getByTestId('exit-save').click();

  // The saved draft has moved nothing: it previews, and posting is a named, explained action.
  await expect(page).toHaveURL(/\/supplier-returns\/\d+$/);
  await expect(page.getByTestId('exit-detail').getByText('Draft', { exact: true }).first()).toBeVisible();
  await expect(page.getByTestId('expected-credit')).toContainText('450');
  await expect(page.getByTestId('exit-line')).toContainText('1 sealed Sealed 5 L can');
  let balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect(balance.rejected.total).toBe('5.0000');

  await page.getByTestId('exit-post').click();
  await expect(page.getByTestId('exit-confirm')).toContainText('goes back to the supplier');
  await page.getByTestId('exit-confirm-action').click();

  // Posted: numbered, frozen, no controls.
  await expect(page.getByText('Posted', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('SRN-');
  await expect(page.getByTestId('exit-frozen-line')).toContainText('1 sealed Sealed 5 L can');
  await expect(page.getByTestId('exit-post')).toHaveCount(0);
  await expect(page.getByTestId('exit-edit')).toHaveCount(0);
  await expect(page.getByTestId('exit-cancel')).toHaveCount(0);
  balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect(balance.rejected?.total ?? '0.0000').toBe('0.0000');
});

test('rejected grouped stock is scrapped from the item page: the delivery is chosen on the document, then posted and frozen', async ({ page }) => {
  test.setTimeout(240_000);
  const world = await createStockedItem(page, 'e2e-scr');
  await activateGrouped(page, world);
  const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'SCRAP', { grouped: true });

  await page.goto(`/items/${world.item.id}?tab=packaging`);
  await expect(page.getByTestId('package-balance-row').first().getByTestId('breakdown-rejected')).toContainText(/1 × Sealed 5 L can = 5 l/i);
  await expect(page.getByTestId('return-rejected-stock')).toBeVisible();
  await page.getByTestId('scrap-rejected-stock').click();

  await expect(page.getByTestId('exit-no-lines')).toBeVisible();
  await page.getByRole('combobox', { name: 'Delivery' }).click();
  await page.getByRole('option', { name: delivery.number }).click();
  await page.getByTestId('pickable-line').getByRole('button', { name: 'Add' }).click();
  await expect(page.getByRole('button', { name: 'Added' })).toBeDisabled();
  await expect(page.getByTestId('exit-line-remaining')).toBeVisible(); // previewed, quantity filled in
  await expect(page.getByTestId('exit-portion-chosen')).toContainText('1 sealed Sealed 5 L can');
  await expect(page.getByTestId('outcome-change')).toHaveCount(0);

  await page.getByLabel('Reason', { exact: true }).fill('Contaminated');
  await page.getByRole('button', { name: /Disposal details/ }).click();
  await page.getByLabel('Disposal method').fill('Licensed waste collector');
  await page.getByTestId('exit-save').click();

  await expect(page).toHaveURL(/\/stock-scraps\/\d+$/);
  await expect(page.getByText('Licensed waste collector')).toBeVisible();
  await page.getByTestId('exit-post').click();
  await expect(page.getByTestId('exit-confirm')).toContainText('written off');
  await page.getByTestId('exit-confirm-action').click();

  await expect(page.getByRole('heading', { level: 1 })).toContainText('SCR-');
  await expect(page.getByTestId('exit-frozen-line')).toContainText('1 sealed Sealed 5 L can');
  // Frozen: the edit address shows a notice, not a form, and no action remains.
  const url = page.url();
  await page.goto(`${url}/edit`);
  await expect(page.getByTestId('exit-frozen')).toContainText('can no longer be changed');
  await page.goto(url);
  await expect(page.getByTestId('exit-post')).toHaveCount(0);

  // Nothing is left to take out: the delivery no longer offers the exit.
  await page.goto(`/goods-receipts/${delivery.receiptId}`);
  await expect(page.getByTestId('rejected-stock-actions')).toBeEmpty();
});

test('an exit that differs from the QA decision needs its own reason', async ({ page }) => {
  test.setTimeout(180_000);
  const world = await createStockedItem(page, 'e2e-diff');
  // QA decided SCRAP for 4 L; the warehouse sends it back instead.
  const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'SCRAP');

  await page.goto(`/goods-receipts/${delivery.receiptId}`);
  await page.getByTestId('start-supplier_return').click();
  await page.getByLabel('Reason', { exact: true }).fill('Supplier agreed to take it back');
  await expect(page.getByTestId('outcome-change')).toContainText('Different from the quality decision');
  await expect(page.getByTestId('outcome-change')).toContainText('4');
  await page.getByTestId('exit-save').click();
  await expect(page.getByText('This differs from the quality decision — say why.')).toBeVisible();
  await expect(page).toHaveURL(/\/supplier-returns\/new/);

  await page.getByLabel('Why is it leaving this way?').fill('Supplier accepted a return authorisation');
  await page.getByTestId('exit-save').click();
  await expect(page).toHaveURL(/\/supplier-returns\/\d+$/);
  await expect(page.getByTestId('outcome-change')).toHaveCount(1);
  await page.getByTestId('exit-post').click();
  await page.getByTestId('exit-confirm-action').click();
  await expect(page.getByTestId('exit-frozen-line')).toContainText('Different from the quality decision because: Supplier accepted a return authorisation');
});

test('one document refuses deliveries from two suppliers and from two warehouses', async ({ page }) => {
  test.setTimeout(240_000);
  const world = await createStockedItem(page, 'e2e-mix');
  const suppliers = (await api(page, 'GET', '/supplier-accounts?per_page=100')).data as Array<{ id: number }>;
  const otherSupplier = suppliers.find((supplier) => supplier.id !== world.supplier.id);
  expect(otherSupplier).toBeTruthy();

  const first = await rejectedDelivery(page, world, await createQaOrder(page, world), 'RETURN_TO_SUPPLIER');
  const fromOtherSupplier = await rejectedDelivery(page, world, await createQaOrder(page, world, { supplierId: otherSupplier!.id }), 'RETURN_TO_SUPPLIER');
  const inKarachi = await rejectedDelivery(page, world, await createQaOrder(page, world, { locationId: world.karachi.id }), 'SCRAP');

  // Whatever the screen does, the server refuses a mixed document itself.
  async function refused(path: string, lines: number[], reasonCode: string) {
    const cookies = await page.context().cookies('http://127.0.0.1:8090');
    const xsrf = decodeURIComponent(cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');
    const response = await page.request.fetch(`http://127.0.0.1:8090/api/v1${path}`, {
      method: 'POST',
      data: { reason: 'Mixed attempt', lines: lines.map((id) => ({ goods_receipt_line_id: id, quantity: '1' })) },
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Origin: 'http://127.0.0.1:5176', Referer: 'http://127.0.0.1:5176/' },
    });
    expect(response.status()).toBe(422);
    expect((await response.json()).reason_code).toBe(reasonCode);
  }
  await refused('/supplier-returns', [first.lineId, fromOtherSupplier.lineId], 'MIXED_SUPPLIERS');
  await refused('/stock-scraps', [first.lineId, inKarachi.lineId], 'MIXED_WAREHOUSES');

  async function pick(delivery: { number: string }) {
    await page.getByRole('combobox', { name: 'Delivery' }).click();
    await page.getByRole('option', { name: delivery.number }).click();
    await page.getByTestId('pickable-line').getByRole('button', { name: 'Add' }).click();
  }

  // A supplier return goes to one supplier.
  await page.goto(`/supplier-returns/new?receipt=${first.receiptId}&line=${first.lineId}`);
  await expect(page.getByTestId('exit-line')).toHaveCount(1);
  await pick(fromOtherSupplier);
  await expect(page.getByTestId('exit-line')).toHaveCount(2);
  await expect(page.getByTestId('exit-mixed')).toContainText('one supplier');
  await page.getByLabel('Reason', { exact: true }).fill('Mixed attempt');
  await page.getByTestId('exit-save').click();
  await expect(page.getByText('Fix the points above to save.')).toBeVisible();
  await expect(page).toHaveURL(/\/supplier-returns\/new/);
  await page.getByRole('button', { name: 'Remove this line' }).last().click();
  await expect(page.getByTestId('exit-mixed')).toHaveCount(0);

  // A scrap covers one warehouse.
  await page.goto(`/stock-scraps/new?receipt=${first.receiptId}&line=${first.lineId}`);
  await expect(page.getByTestId('exit-line')).toHaveCount(1);
  await pick(inKarachi);
  await expect(page.getByTestId('exit-line')).toHaveCount(2);
  await expect(page.getByTestId('exit-mixed')).toContainText('one warehouse');
  await page.getByLabel('Reason', { exact: true }).fill('Mixed attempt');
  await page.getByTestId('exit-save').click();
  await expect(page.getByText('Fix the points above to save.')).toBeVisible();
  await expect(page).toHaveURL(/\/stock-scraps\/new/);
});

test.describe('people with part of the capability', () => {
  test('someone who may only prepare a document can save a draft but not post it', async ({ page, browser }) => {
    test.setTimeout(180_000);
    const world = await createStockedItem(page, 'e2e-prep');
    const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'RETURN_TO_SUPPLIER');

    const preparer = await signInAs(browser, EXIT_PERSONAS.preparer);
    await preparer.goto(`/goods-receipts/${delivery.receiptId}`);
    await preparer.getByTestId('start-supplier_return').click();
    await expect(preparer.getByTestId('exit-line-remaining')).toBeVisible(); // the line is previewed and its quantity filled in
    await preparer.getByLabel('Reason', { exact: true }).fill('Failed inspection');
    await preparer.getByTestId('exit-save').click();

    await expect(preparer).toHaveURL(/\/supplier-returns\/\d+$/);
    await expect(preparer.getByTestId('exit-edit')).toBeVisible();
    await expect(preparer.getByTestId('exit-post')).toHaveCount(0);
    await expect(preparer.getByTestId('exit-needs-poster')).toContainText('Someone with permission to post');
    // Nothing about cost: this person holds no valuation capability.
    await expect(preparer.getByTestId('exit-line-cost')).toHaveCount(0);
    await preparer.context().close();
  });

  test('someone limited to their own units cannot see another warehouse’s documents or stock', async ({ page, browser }) => {
    test.setTimeout(180_000);
    const world = await createStockedItem(page, 'e2e-lim');
    const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'SCRAP');
    const document = (await api(page, 'POST', '/stock-scraps', { reason: 'Scope check', lines: [{ goods_receipt_line_id: delivery.lineId, quantity: '4' }] })).data;

    const limited = await signInAs(browser, EXIT_PERSONAS.limited);
    await limited.goto('/stock-scraps');
    await expect(limited.getByTestId('exit-list')).toBeVisible();
    await expect(limited.getByText(String(document.number ?? `Draft #${document.id}`), { exact: true })).toHaveCount(0);
    await limited.goto(`/stock-scraps/${document.id}`);
    await expect(limited.getByTestId('exit-error')).toBeVisible();
    await expect(limited.getByTestId('exit-post')).toHaveCount(0);
    // The delivery's rejected stock is not shown to them either: nothing about it appears and nothing can be saved.
    await limited.goto(`/stock-scraps/new?receipt=${delivery.receiptId}&line=${delivery.lineId}`);
    await expect(limited.getByTestId('exit-form')).toBeVisible();
    await expect(limited.getByTestId('pickable-line')).toHaveCount(0);
    await expect(limited.getByTestId('exit-line-facts')).toHaveCount(0);
    await limited.getByLabel('Reason', { exact: true }).fill('Should be refused');
    await limited.getByTestId('exit-save').click();
    await expect(limited).toHaveURL(/\/stock-scraps\/new/);
    await limited.context().close();
  });
});

for (const mode of ['light', 'dark'] as const) {
  test(`the form, the draft and the list have no serious accessibility or contrast problems (${mode})`, async ({ page }) => {
    test.setTimeout(180_000);
    await page.addInitScript((appearance) => localStorage.setItem('erp.appearance', JSON.stringify(appearance)), { mode, theme: 'erp-blue' });
    const world = await createStockedItem(page, `e2e-a11y-${mode}`);
    const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'SCRAP');
    const scan = async (label: string) => {
      await page.waitForLoadState('networkidle');
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
      expect(serious, `${label}: ${JSON.stringify(serious, null, 2)}`).toEqual([]);
    };

    await page.goto(`/stock-scraps/new?receipt=${delivery.receiptId}&line=${delivery.lineId}`);
    await expect(page.getByTestId('exit-line-remaining')).toBeVisible();
    await page.getByRole('button', { name: /Disposal details/ }).click();
    await scan('form');
    await page.getByLabel('Reason', { exact: true }).fill('Contaminated');
    await page.getByTestId('exit-save').click();
    await expect(page).toHaveURL(/\/stock-scraps\/\d+$/);
    await expect(page.getByTestId('exit-line')).toBeVisible();
    await scan('draft');
    await page.goto('/stock-scraps');
    await expect(page.getByTestId('exit-list')).toBeVisible();
    await scan('list');
  });
}

test('rejected loose litres leave in part: 1 L of 2 L loose is scrapped and the rest stays rejected', async ({ page }) => {
  test.setTimeout(240_000);
  const world = await createStockedItem(page, 'e2e-loose');
  await activateGrouped(page, world);
  // 12 L arrive (2 cans + 2 L loose); QA passes a can and rejects the other can plus the 2 L loose for scrap.
  const delivery = await rejectedDelivery(page, world, await createQaOrder(page, world), 'SCRAP', { grouped: true, withLoose: true });

  await page.goto(`/goods-receipts/${delivery.receiptId}`);
  await expect(page.getByTestId('rejected-stock-actions')).toContainText('7 rejected, still to scrap');
  await page.getByTestId('start-scrap').click();
  await expect(page.getByTestId('exit-line-remaining')).toBeVisible();
  await expect(page.getByTestId('exit-portion')).toContainText('1 × Sealed 5 L can');
  await expect(page.getByTestId('exit-portion')).toContainText('2 l loose');

  // Take out just 1 L: it can only be loose litres, so the portion becomes 1 L loose.
  await page.getByLabel('Quantity to scrap').fill('1');
  await expect(page.getByTestId('exit-portion-chosen')).toContainText('1 l loose');
  await expect(page.getByTestId('exit-portion-check')).toContainText('adds up');
  await expect(page.getByTestId('exit-line-remaining')).toContainText('6');
  await page.getByLabel('Reason', { exact: true }).fill('Skinned over');
  await page.getByTestId('exit-save').click();
  await expect(page).toHaveURL(/\/stock-scraps\/\d+$/);
  await expect(page.getByTestId('exit-line')).toContainText('1 l loose');
  await page.getByTestId('exit-post').click();
  await page.getByTestId('exit-confirm-action').click();
  await expect(page.getByTestId('exit-frozen-line')).toContainText('1 loose');

  // The can and 1 L loose are still rejected, awaiting their own exit; the sealed can was not touched.
  const balance = (await api(page, 'GET', `/package-balances?item_id=${world.item.id}`)).data[0];
  expect([balance.rejected.total, balance.rejected.loose, balance.rejected.sealed[0].count]).toEqual(['6.0000', '1.0000', 1]);
  await page.goto(`/goods-receipts/${delivery.receiptId}`);
  await expect(page.getByTestId('rejected-stock-actions')).toContainText('6 rejected, still to scrap');
});
