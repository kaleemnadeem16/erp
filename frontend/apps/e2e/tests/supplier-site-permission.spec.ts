import { expect, test } from '@playwright/test';

const BUYER = 'demo-buyer@erp.x1techs.com';
const PASSWORD = process.env.E2E_USER_PASSWORD ?? '!User@Erp@123';

test('the administrator can reach inline supplier-site creation from a purchase order', async ({ page }) => {
  await page.goto('/purchase-orders/new');
  const form = page.getByRole('dialog', { name: 'New purchase order' });
  await expect(form.getByRole('button', { name: 'Add a supplier site' })).toBeVisible();
});

test.describe('a Buyer without supplier-site maintenance', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('can draft a purchase order but cannot open supplier-site creation', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(BUYER);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.goto('/purchase-orders/new');
    const form = page.getByRole('dialog', { name: 'New purchase order' });
    await expect(form).toBeVisible();
    await expect(form.getByRole('button', { name: 'Add a supplier site' })).toHaveCount(0);
  });
});
