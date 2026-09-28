import { expect, test } from '@playwright/test';
import { pickCombobox } from './support';

/**
 * ADR 0033 keeps an item's purchase unit on the item-supplier row, because it varies per item and
 * supplier. A purchase order line used to start on the item's *stock* unit whatever the supplier
 * sells it in, so an item bought by the roll had to be switched by hand every time. It now starts on
 * the unit the chosen supplier sells that item in, and falls back to the stock unit when there is no
 * such row. Nothing is saved: the spec reads the line's default and leaves.
 *
 * Demo data (PurchasingDemoSeeder): Industrial Coatings Ltd sells masking tape by the roll and sanding
 * discs by the box, and has no row for blue pigment.
 */
const SUPPLIER = 'Industrial Coatings Ltd';

test('a purchase order line starts on the unit the supplier sells the item in', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await pickCombobox(page, 'Supplier', SUPPLIER, { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();

  const unit = sheet.getByLabel('Purchase UoM', { exact: true });

  await pickCombobox(page, 'Item', 'Masking Tape', { within: sheet });
  await expect(unit).toContainText('roll — Roll');

  // Choosing another item re-defaults the unit for that item, from the same supplier's rows.
  await pickCombobox(page, 'Item', 'Sanding Discs', { within: sheet });
  await expect(unit).toContainText('box — Box');
});

test('an item this supplier has no row for starts on its stock unit', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await pickCombobox(page, 'Supplier', SUPPLIER, { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();

  await pickCombobox(page, 'Item', 'Phthalo Blue Pigment', { within: sheet });

  await expect(sheet.getByLabel('Purchase UoM', { exact: true })).toContainText('kg — Kilogram');
});
