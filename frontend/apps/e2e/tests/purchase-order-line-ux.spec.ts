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

test('a line shows only the everyday fields; the rest are its own advanced options, each with a hint', async ({ page }) => {
  const sheet = await openNew(page);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });

  for (const label of ['Item', 'Description', 'Quantity', 'Purchase UoM', 'Unit price'])
    await expect(sheet.getByLabel(label, { exact: true })).toBeVisible();
  for (const label of ['Package or loose', 'Line discount (amount)', 'Line tax (amount)', 'Line expected date (optional)', 'QA required'])
    await expect(sheet.getByLabel(label, { exact: true })).toHaveCount(0);

  // What the price means is on hand, and the line says what it is "per"
  await expect(sheet.getByText(/^per 1 \w+/)).toBeVisible();
  await sheet.getByRole('button', { name: 'About Unit price' }).hover();
  await expect(page.getByRole('tooltip').first()).toContainText('pricing unit');

  // A set value is never hidden: choosing a discount opens its group and unticking it puts the default back
  await sheet.getByRole('button', { name: 'Options for line 1' }).click();
  await page.getByRole('menuitemcheckbox', { name: 'Line discount and tax' }).click();
  await page.keyboard.press('Escape');
  await sheet.getByLabel('Line discount (amount)').fill('5');
  await sheet.getByRole('button', { name: 'Options for line 1' }).click();
  await page.getByRole('menuitemcheckbox', { name: 'Line discount and tax' }).click();
  await page.keyboard.press('Escape');
  await expect(sheet.getByLabel('Line discount (amount)')).toHaveCount(0);
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

test('"replaces" is a picker of the supplier\'s earlier orders, behind the order\'s advanced options', async ({ page }) => {
  const sheet = await openNew(page);
  await expect(sheet.getByLabel('Replaces order', { exact: true })).toHaveCount(0);
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await sheet.getByRole('button', { name: 'Advanced options', exact: true }).click();
  await page.getByRole('menuitemcheckbox', { name: 'This order replaces another' }).click();
  await page.keyboard.press('Escape');
  await expect(sheet.getByLabel('Replaces order', { exact: true })).toBeVisible();
});
