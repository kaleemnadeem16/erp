import { expect, test } from '@playwright/test';

import { pickCombobox } from './support';

test.describe('Settings → Company', () => {
  test('shows the profile and settings, explains why the base currency is locked, and keeps a profile change', async ({ page }) => {
    await page.goto('/settings/company');
    await expect(page.getByRole('heading', { name: 'Company', exact: true })).toBeVisible();
    await expect(page.getByLabel('Company name')).toHaveValue(/\S/);
    await expect(page.getByLabel('Time zone')).toBeVisible();

    // The dev company has stock movements, so its base currency is read-only with the reason beside it.
    await expect(page.getByTestId('locked-base_currency_id')).toContainText('can no longer be changed');
    await expect(page.getByLabel('Base currency')).toBeDisabled();

    const city = page.getByLabel('City');
    const original = await city.inputValue();
    try {
      await city.fill('E2E City');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expect(page.getByText('Saved.')).toBeVisible();
      await page.reload();
      await expect(page.getByLabel('City')).toHaveValue('E2E City');
    } finally {
      await page.getByLabel('City').fill(original);
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expect(page.getByText('Saved.')).toBeVisible();
    }
  });

  test('a time zone change is searched by name, needs a reason, and is recorded in the history', async ({ page }) => {
    await page.goto('/settings/company');
    const zone = page.getByLabel('Time zone');
    const original = (await zone.innerText()).split(/\s/)[0] ?? 'UTC';

    async function choose(search: string, option: RegExp) {
      await pickCombobox(page, 'Time zone', option, { searchText: search });
    }

    try {
      await choose('Lisbon', /Europe\/Lisbon/);
      const save = page.getByRole('button', { name: 'Save changes' });
      await expect(save).toBeDisabled(); // a governed change is not saved without a reason
      await page.getByLabel('Reason for the change').fill('End-to-end check');
      await save.click();
      await expect(page.getByText('Saved.')).toBeVisible();
      await expect(page.getByText(/UTC.*→ Europe\/Lisbon|→ Europe\/Lisbon/).first()).toBeVisible();
    } finally {
      await choose(original, new RegExp(`^${original.replace('/', '\\/')}`));
      await page.getByLabel('Reason for the change').fill('End-to-end restore');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expect(page.getByText('Saved.')).toBeVisible();
    }
  });

  test('printing settings read the company name from the profile', async ({ page }) => {
    await page.goto('/settings/printing');
    await expect(page.getByRole('link', { name: 'Settings → Company' })).toBeVisible();
  });
});
