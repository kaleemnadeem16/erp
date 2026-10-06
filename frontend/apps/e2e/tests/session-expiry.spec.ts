import { expect, test } from '@playwright/test';

test('an expired list session redirects to sign in', async ({ page }) => {
  await page.goto('/settings/account');
  await expect(page.getByRole('button', { name: 'Save changes', exact: true })).toBeVisible();
  await page.route('**/api/v1/purchase-orders?*', route => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
  await page.goto('/purchase-orders');
  await expect(page).toHaveURL('/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});

test('expired CSRF on a save redirects without retrying the write', async ({ page }) => {
  await page.goto('/settings/account');
  await expect(page.getByRole('button', { name: 'Save changes', exact: true })).toBeVisible();
  let writes = 0;
  await page.route('**/api/v1/account', route => {
    writes++;
    return route.fulfill({ status: 419, json: { message: 'CSRF token mismatch.' } });
  });
  await page.getByLabel('Name', { exact: true }).fill('Expired session test');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(page).toHaveURL('/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  expect(writes).toBe(1);
});

test('permission denial remains on the screen', async ({ page }) => {
  await page.route('**/api/v1/purchase-orders?*', route => route.fulfill({ status: 403, json: { message: 'Forbidden.' } }));
  await page.goto('/purchase-orders');
  await expect(page.getByText("You don't have permission to view this.")).toBeVisible();
  await expect(page).toHaveURL('/purchase-orders');
});

test('invalid credentials stay on the sign-in form', async ({ page }) => {
  await page.goto('/login');
  await page.route('**/api/v1/login', route => route.fulfill({ status: 401, json: { message: 'Invalid credentials.' } }));
  await page.getByLabel('Email', { exact: true }).fill('invalid@example.test');
  await page.getByLabel('Password', { exact: true }).fill('invalid-password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByText('Invalid credentials.', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue('invalid@example.test');
});
