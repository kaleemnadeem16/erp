import { expect, test } from '@playwright/test';
import { thinnerDraftReceipt } from './purchasing-test-support';

/**
 * ADR 0069: a Receiving Clerk works with quantities and identity and never sees supplier economics, while
 * the administrator sees the same documents priced. The clerk signs in through the real login form in a
 * fresh browser context (no shared admin session); demo draft receipts carry the cost the
 * server derived from their issued purchase orders.
 */
const CLERK = 'demo-receiver@erp.x1techs.com';
const PASSWORD = process.env.E2E_USER_PASSWORD ?? '!User@Erp@123';

test.describe('a Receiving Clerk', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('sees receipts and purchase orders without prices, costs or FX', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(CLERK);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.goto('/purchase-orders');
    await expect(page.getByRole('heading', { name: 'Purchase Orders' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: /Total/ })).toHaveCount(0);
    await page.getByRole('row').nth(1).click();
    await expect(page.getByText(/FX rate/)).toHaveCount(0);
    await expect(page.getByText(/Unit price|Line amount/)).toHaveCount(0);

    // Filtered to drafts: newer posted receipts (other specs, manual work) fill the first page of the unfiltered list.
    await page.goto('/goods-receipts?filter.status=DRAFT');
    await expect(page.getByRole('heading', { name: 'Goods Receipts' })).toBeVisible();
    const receiptId = await thinnerDraftReceipt(page);
    await page.goto(`/goods-receipts/${receiptId}`);
    await expect(page.getByRole('heading', { name: /demo-thinner/ })).toBeVisible();
    await expect(page.getByText(/^\d+\.0000 l$/).first()).toBeVisible();
    await expect(page.getByText('Provisional cost')).toHaveCount(0);
    await expect(page.getByText('FX rate')).toHaveCount(0);
  });
});

test('the administrator sees the same receipt priced', async ({ page }) => {
  await page.goto('/goods-receipts?filter.status=DRAFT');
  await page.getByRole('row').filter({ hasText: /Draft|DRAFT/ }).first().click();
  await expect(page.getByText('Provisional unit cost').first()).toBeVisible();
});
