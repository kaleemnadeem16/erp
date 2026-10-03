import { expect, test } from '@playwright/test';

import { pickCombobox } from './support';

async function openNew(page: import('@playwright/test').Page) {
  await page.goto('/purchase-orders/new');
  return page.getByRole('dialog', { name: 'New purchase order' });
}

test('a "required" message goes away as soon as the field is fixed', async ({ page }) => {
  const sheet = await openNew(page);
  await sheet.getByRole('button', { name: 'Next' }).click();
  await expect(sheet.getByText('Supplier is required')).toBeVisible();

  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await expect(sheet.getByText('Supplier is required')).toHaveCount(0);
});

test('a line shows only the everyday fields; the rest hide behind one "More details" toggle and are still saved', async ({ page }) => {
  const sheet = await openNew(page);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });

  for (const label of ['Item', 'Description', 'Quantity', 'Purchase UoM', 'Unit price'])
    await expect(sheet.getByLabel(label, { exact: true })).toBeVisible();
  await expect(sheet.getByLabel('Line total', { exact: true })).toBeVisible();
  for (const label of ['Line discount (amount)', 'Line tax (amount)', 'Line delivery date', 'QA required'])
    await expect(sheet.getByLabel(label, { exact: true })).toBeHidden();

  // What the price means is on hand, and the line says what it is "per"
  await expect(sheet.getByText(/^per 1 \w+/)).toBeVisible();
  await sheet.getByRole('button', { name: 'About Unit price' }).hover();
  await expect(page.getByRole('tooltip').first()).toContainText('pricing unit');

  // One toggle shows every optional field, with its default; hiding it keeps what was entered and says so
  const toggle = sheet.getByRole('button', { name: 'More details for this line' });
  await toggle.click();
  await sheet.getByLabel('Line discount (amount)', { exact: true }).fill('5');
  await expect(sheet.getByLabel('Line tax (amount)', { exact: true })).toBeVisible();
  await expect(sheet.getByLabel('Line delivery date', { exact: true })).toBeVisible();
  await expect(sheet.getByLabel('QA required', { exact: true })).toBeVisible();
  await sheet.getByRole('button', { name: 'Hide details' }).click();
  await expect(sheet.getByLabel('Line discount (amount)', { exact: true })).toBeHidden();
  await expect(sheet.getByText('Set: discount or tax')).toBeVisible();
  // …and what was typed is still there when it is shown again
  await sheet.getByRole('button', { name: 'More details for this line' }).click();
  await expect(sheet.getByLabel('Line discount (amount)', { exact: true })).toHaveValue('5');
});

test('the purchase unit offers only units the item can be converted to', async ({ page }) => {
  const sheet = await openNew(page);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });
  await sheet.getByLabel('Purchase UoM', { exact: true }).click();
  await page.locator('[cmdk-input]').fill('Hour');
  await expect(page.getByRole('option', { name: /Hour/ })).toHaveCount(0);
  await page.locator('[cmdk-input]').fill('gram');
  await expect(page.getByRole('option', { name: /Gram/ }).first()).toBeVisible();
  await page.keyboard.press('Escape');
});

test('"replaces" is a picker of the supplier\'s earlier orders, behind the order\'s More options', async ({ page }) => {
  const sheet = await openNew(page);
  await expect(sheet.getByLabel('Replaces order', { exact: true })).toBeHidden();
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'More options' }).click();
  await expect(sheet.getByLabel('Replaces order', { exact: true })).toBeVisible();
  await expect(sheet.getByRole('heading', { name: 'Exchange rate' })).toBeVisible();
});

test('a picker list inside the side sheet scrolls with the wheel or touch pad, not only the scrollbar', async ({ page }) => {
  const sheet = await openNew(page);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'More options' }).click();
  await sheet.getByLabel('Replaces order', { exact: true }).click();
  const list = page.locator('[cmdk-list]');
  await expect(list).toBeVisible();
  await expect.poll(async () => list.evaluate((el) => el.scrollHeight > el.clientHeight)).toBe(true);
  const box = (await list.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.wheel(0, 240);
  await expect.poll(async () => list.evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
});

test('on a phone the review shows its lines as a table or as cards, as chosen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const sheet = await openNew(page);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await sheet.getByRole('button', { name: 'Table view' }).click();
  await expect(sheet.getByRole('columnheader', { name: 'Stock quantity' })).toBeVisible();
  await sheet.getByRole('button', { name: 'Card view' }).click();
  await expect(sheet.getByRole('columnheader', { name: 'Stock quantity' })).toHaveCount(0);
  await expect(sheet.getByText('Line 1', { exact: true })).toBeVisible();
});

test('table or cards is a phone choice: a wide screen has no toggle, a phone starts on cards', async ({ page }) => {
  await page.setViewportSize({ width: 1400, height: 900 });
  let sheet = await openNew(page);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await expect(sheet.getByRole('button', { name: 'Card view' })).toBeHidden();
  await expect(sheet.getByRole('columnheader', { name: 'Stock quantity' })).toBeVisible();

  await page.evaluate(() => localStorage.removeItem('erp.list-view.po-review-lines'));
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(sheet.getByRole('button', { name: 'Card view' })).toBeVisible();
  await expect(sheet.getByRole('columnheader', { name: 'Stock quantity' })).toHaveCount(0);
});
