import { expect, test } from '@playwright/test';
import { escapeRegExp, pickCombobox, readCell } from './support';

const SUPPLIER = 'Punjab Pigments';
const ITEM = 'Phthalo Blue Pigment';
const ITEM_CODE = 'demo-pig-blue';
const QTY = 10;

/**
 * The Phase 2 acceptance criterion this covers: a user completes the whole
 * operational journey — understand current inventory, create and issue a
 * PO, receive it, resolve required QA, then see the resulting availability —
 * without touching the API/DB directly. Every step drives the real UI (the
 * same login/CSRF flow, the same forms, the same confirmation panels) rather
 * than seeding state around the parts under test, so a regression anywhere
 * in that chain fails here the way it would fail a real user.
 *
 * Quantities are asserted as deltas off a baseline captured at the start,
 * not absolute values — this item's demo data already carries whatever
 * state earlier seeders/test runs left it in, and the point is proving this
 * run's own receipt and QA disposition moved the right figures by the right
 * amount, not that the item starts at zero.
 */
test('purchase order through receipt, QA and availability', async ({ page }) => {
  await page.goto('/inventory-overview');
  await expect(page.getByRole('heading', { name: 'Inventory Overview' })).toBeVisible();

  const overviewTable = page.getByRole('table');
  await page.getByPlaceholder('Search code or name…').fill(ITEM_CODE);
  const overviewRow = page.getByRole('row', { name: new RegExp(ITEM) });
  await expect(overviewRow).toBeVisible();

  const baseline = {
    onHand: Number(await readCell(overviewTable, overviewRow, 'On hand')),
    available: Number(await readCell(overviewTable, overviewRow, 'Available')),
    qaHeld: Number(await readCell(overviewTable, overviewRow, 'QA held')),
  };

  // --- Create and issue the purchase order ---
  // Create is a 3-step wizard (Supplier & dates -> Lines -> Review) in a side
  // sheet over the PO list; "Next" gates on that step's own validation, same
  // as submitting used to. Everything is scoped to the sheet because the list
  // behind it has its own "Supplier" filter with the same label.
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await pickCombobox(page, 'Supplier', SUPPLIER, { within: sheet });
  await expect(sheet.getByLabel('Currency', { exact: true })).not.toContainText('Select currency');
  await sheet.getByRole('button', { name: 'Next' }).click();

  await pickCombobox(page, 'Item', ITEM, { within: sheet });
  await sheet.getByLabel('Quantity', { exact: true }).fill(String(QTY));
  await sheet.getByLabel('Unit price', { exact: true }).fill('100.0000');
  // The quality check sits under the line's "More details", closed until asked for
  await sheet.getByRole('button', { name: 'More details for this line' }).click();
  await sheet.getByLabel('QA required', { exact: true }).check();
  await sheet.getByRole('button', { name: 'Next' }).click();

  await expect(sheet.getByRole('heading', { name: 'Review' })).toBeVisible();
  await sheet.getByRole('button', { name: 'Save draft' }).click();
  await expect(page).toHaveURL(/\/purchase-orders\/\d+$/);
  // Saving is what finishes the order — nothing left behind for the sheet to offer back.
  expect(await page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.startsWith('erp:draft:')))).toEqual([]);

  await page.getByRole('button', { name: 'Issue purchase order' }).click();
  await expect(page.getByRole('heading', { name: 'Issue this purchase order' })).toBeFocused();
  await page.getByRole('button', { name: 'Confirm issue' }).click();
  await expect(page.getByRole('button', { name: 'Cancel order' })).toBeVisible();

  // Issuing allocates the official order number, replacing the page's own
  // "Draft purchase order #<id>" heading — captured so the goods-receipt
  // line picker below can match *this* order specifically. Matching on item
  // code alone isn't enough: this supplier/item pair can already have an
  // older, still-open line sitting in the same dropdown (demo or prior
  // manual testing), and picking the wrong one silently receives someone
  // else's quantity instead of this run's.
  // (Matched by text rather than an <h1>: in a side sheet the title is a dialog heading.)
  const poNumber = (await page.getByText(/^Purchase order \d+$/).first().innerText()).replace('Purchase order ', '').trim();

  // --- Receive the full ordered quantity against the issued line ---
  await page.goto('/goods-receipts/new');
  // The receipt form is a side sheet over the receipts list, whose filter bar has its own "Supplier" control.
  const receiptSheet = page.getByRole('dialog', { name: /goods receipt/i });
  await pickCombobox(page, 'Supplier', SUPPLIER, { within: receiptSheet });
  await pickCombobox(page, 'Purchase-order line', new RegExp(`^${escapeRegExp(poNumber)} / `), { searchText: poNumber });
  await pickCombobox(page, 'Put-away location', 'Main Stock');

  await page.getByRole('button', { name: 'Save draft receipt' }).click();
  await expect(page).toHaveURL(/\/goods-receipts\/\d+$/);
  const receiptUrl = page.url();

  await page.getByRole('button', { name: 'Post receipt' }).click();
  await expect(page.getByRole('heading', { name: 'Post this physical receipt?' })).toBeFocused();
  await page.getByRole('button', { name: 'Confirm posting' }).click();
  await expect(page.getByRole('button', { name: 'Reverse receipt' })).toBeVisible();

  // Held for QA, not yet available — the receipt landed as on-hand but not
  // available stock until a disposition clears it.
  await page.goto('/inventory-overview');
  await page.getByPlaceholder('Search code or name…').fill(ITEM_CODE);
  await expect(overviewRow).toBeVisible();
  await expect
    .poll(async () => Number(await readCell(overviewTable, overviewRow, 'On hand')))
    .toBeCloseTo(baseline.onHand + QTY, 3);
  expect(Number(await readCell(overviewTable, overviewRow, 'QA held'))).toBeCloseTo(baseline.qaHeld + QTY, 3);
  expect(Number(await readCell(overviewTable, overviewRow, 'Available'))).toBeCloseTo(baseline.available, 3);

  // --- Resolve QA: pass the full received quantity ---
  await page.goto(receiptUrl);
  await expect(page.getByText(`QA unresolved ${QTY.toFixed(4)}`)).toBeVisible();
  await page.getByLabel('Disposition reason', { exact: true }).fill('Passed inspection.');
  await page.getByRole('button', { name: 'Post QA disposition' }).click();
  await expect(page.getByText('QA unresolved 0.0000')).toBeVisible();

  // --- Availability reflects the cleared QA hold ---
  await page.goto('/inventory-overview');
  await page.getByPlaceholder('Search code or name…').fill(ITEM_CODE);
  await expect(overviewRow).toBeVisible();
  await expect
    .poll(async () => Number(await readCell(overviewTable, overviewRow, 'Available')))
    .toBeCloseTo(baseline.available + QTY, 3);
  expect(Number(await readCell(overviewTable, overviewRow, 'QA held'))).toBeCloseTo(baseline.qaHeld, 3);
  expect(Number(await readCell(overviewTable, overviewRow, 'On hand'))).toBeCloseTo(baseline.onHand + QTY, 3);

  // --- The ledger trail is reachable from the same row ---
  await overviewRow.getByRole('link', { name: 'View ledger' }).click();
  await expect(page).toHaveURL(new RegExp(`/stock-moves\\?filter\\.item_id=\\d+`));
  await expect(page.getByRole('heading', { name: 'Stock Ledger' })).toBeVisible();
});
