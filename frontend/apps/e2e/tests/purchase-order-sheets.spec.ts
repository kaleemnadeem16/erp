import { expect, test } from '@playwright/test';

import { pickCombobox } from './support';

// A draft is created through the real create sheet, then edited, issued and watched from the list behind the sheets.
async function createDraft(page: import('@playwright/test').Page) {
  await page.goto('/purchase-orders/new');
  const sheet = page.getByRole('dialog', { name: 'New purchase order' });
  await pickCombobox(page, 'Supplier', 'Punjab Pigments', { within: sheet });
  await expect(sheet.getByLabel('Currency', { exact: true })).not.toContainText('Select currency');
  await sheet.getByRole('button', { name: 'Next' }).click();
  await pickCombobox(page, 'Item', 'Phthalo', { within: sheet });
  await sheet.getByLabel('Quantity', { exact: true }).fill('3');
  await sheet.getByLabel('Unit price', { exact: true }).fill('100');
  // Discount and tax are the line's advanced options, closed until asked for
  await sheet.getByRole('button', { name: 'Options for line 1' }).click();
  await page.getByRole('menuitemcheckbox', { name: 'Line discount and tax' }).click();
  await page.keyboard.press('Escape');
  await sheet.getByLabel('Line discount (amount)').fill('20');
  await sheet.getByLabel('Line tax (amount)').fill('5');
  return sheet;
}

test('discount and tax say what they are, and the order summary shows the lines and an estimated total', async ({ page }) => {
  const sheet = await createDraft(page);

  // The form says these are fixed amounts for the whole line
  await expect(sheet.getByText('A fixed amount off this whole line — not a percentage, not per unit.')).toBeVisible();
  await expect(sheet.getByText('A fixed tax amount for this whole line — not a percentage, not per unit.')).toBeVisible();

  // 3 × 100 − 20 + 5 = 285
  const summary = sheet.locator('aside');
  await expect(summary).toContainText('Order summary');
  await expect(summary).toContainText('Payment terms');
  await expect(summary).toContainText('Exchange rate');
  await expect(summary).toContainText('Line discount −20');
  await expect(summary).toContainText('Line tax +5');
  await expect(summary.getByText('Subtotal').locator('..')).toContainText('300.00');
  await expect(summary.getByText('Estimated total').locator('..')).toContainText('285.00');
  await expect(summary).toContainText('The saved amounts are worked out by the server');
});

test('editing opens in the side panel, and the list behind it never shows stale status or totals', async ({ page }) => {
  const sheet = await createDraft(page);
  await sheet.getByRole('button', { name: 'Next' }).click();
  await sheet.getByRole('button', { name: 'Save draft' }).click();
  await expect(page).toHaveURL(/\/purchase-orders\/(\d+)$/);
  const id = /purchase-orders\/(\d+)$/.exec(page.url())![1];
  // The list sits behind a modal sheet (aria-hidden), so it is found by structure, not by role
  const listRows = page.locator('.po-screen table').first().locator('tbody tr');
  const row = listRows.filter({ hasText: `Draft #${id}` });

  // Saving refreshed the list behind the sheet: the new draft is already there, at its total
  await expect(row).toContainText('285.00');
  await expect(row).toContainText('DRAFT');

  // Edit is a side panel over the list, not a separate screen
  await page.getByRole('button', { name: 'Edit draft' }).click();
  await expect(page).toHaveURL(new RegExp(`/purchase-orders/${id}/edit$`));
  const edit = page.getByRole('dialog', { name: `Edit draft #${id}` });
  await expect(edit).toBeVisible();
  await expect(page.getByText('Raise supplier commitments')).toBeVisible();
  // Editing walks the same steps as creating; the line is on the second, and its discount is already showing
  await edit.getByRole('button', { name: 'Next' }).click();
  await expect(edit.locator('aside')).toContainText('Order summary');
  await expect(edit.getByLabel('Line discount (amount)')).toHaveValue('20.0000');

  // Changing the discount updates the estimate, and saving returns to the order with the new figures
  await edit.getByLabel('Line discount (amount)').fill('30');
  await expect(edit.locator('aside').getByText('Estimated total').locator('..')).toContainText('275.00');
  await edit.getByRole('button', { name: 'Next' }).click();
  await edit.getByRole('button', { name: 'Save changes' }).click();
  await expect(page).toHaveURL(new RegExp(`/purchase-orders/${id}$`));
  await expect(page.getByRole('dialog').getByText('275.0000').first()).toBeVisible();

  // The list behind already shows the new total, with no reload
  await expect(row).toContainText('275.00');

  // Issue from the order: the list behind flips to ISSUED at once
  await page.getByRole('button', { name: 'Issue purchase order' }).click();
  await page.getByRole('button', { name: 'Confirm issue' }).click();
  await expect(page.getByRole('button', { name: 'Cancel order' })).toBeVisible();
  await expect(listRows.filter({ hasText: `Draft #${id}` })).toHaveCount(0);
  await expect(listRows.first()).toContainText('ISSUED');
});
