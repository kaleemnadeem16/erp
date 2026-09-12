import { test as setup, expect } from '@playwright/test';

const EMAIL = process.env.E2E_USER_EMAIL ?? 'user@erp.x1techs.com';
const PASSWORD = process.env.E2E_USER_PASSWORD ?? '!User@Erp@123';

/**
 * Logs in once through the real login form and saves the resulting session
 * cookie for every other spec to reuse (`storageState` in playwright.config)
 * — signing in is Sanctum's own SPA cookie flow, not a test-only shortcut,
 * so a login regression still fails this exactly like a user would hit it.
 */
setup('authenticate', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email', { exact: true }).fill(EMAIL);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL('/');
  await page.context().storageState({ path: '.auth/user.json' });
});
