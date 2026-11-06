import { expect, test } from '@playwright/test';

import { api } from './package-stock-support';

/**
 * Supplier addresses can be changed and removed from inside the supplier's own edit form, not only from the record.
 * The supplier is made through the API with a unique code and removed again in `finally`.
 */
test('a supplier address can be edited and removed inside the supplier edit form', async ({ page }) => {
  await page.goto('/suppliers');
  const stamp = Date.now().toString().slice(-7);
  const code = `e2e-addr-${stamp}`;
  const party = (await api(page, 'POST', '/parties', { code, name: `E2E Address ${stamp}` })).data;
  const payables = (await api(page, 'GET', '/chart-of-accounts?filter[search]=2100&per_page=5')).data;
  const account = (
    await api(page, 'POST', '/supplier-accounts', { party_id: party.id, code, payment_terms_days: 30, payable_account_id: payables[0].id })
  ).data;

  try {
    await page.goto(`/suppliers/${account.id}/edit`);
    const sheet = page.getByRole('dialog', { name: new RegExp(`Edit supplier ${code}`, 'i') });
    await expect(sheet).toBeVisible();

    await sheet.getByRole('button', { name: 'Add address' }).click();
    const add = page.getByRole('dialog', { name: 'Add a supplier site' });
    await add.getByLabel('Name').fill('Yard one');
    await add.getByLabel('Address line 1').fill('1 Depot Road');
    await add.getByLabel('Country code').fill('PK');
    await add.getByRole('button', { name: 'Add site' }).click();
    await expect(sheet.getByText('Yard one', { exact: false })).toBeVisible();

    await sheet.getByRole('button', { name: 'Edit address Yard one' }).click();
    const edit = page.getByRole('dialog', { name: 'Edit supplier site' });
    await edit.getByLabel('Name').fill('Yard two');
    await edit.getByRole('button', { name: 'Save site' }).click();
    await expect(sheet.getByText('Yard two', { exact: false })).toBeVisible();
    await expect(sheet.getByText('Yard one')).toHaveCount(0);

    await sheet.getByRole('button', { name: 'Remove address Yard two' }).click();
    const remove = page.getByTestId('remove-site-dialog');
    await expect(remove).toContainText('Remove Yard two from this supplier?');
    await remove.getByRole('button', { name: 'Remove address' }).click();
    await expect(sheet.getByText('No supplier addresses yet.')).toBeVisible();
  } finally {
    await api(page, 'DELETE', `/supplier-accounts/${account.id}`);
    await api(page, 'DELETE', `/parties/${party.id}`);
  }
});
