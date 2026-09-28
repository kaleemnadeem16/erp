import { expect, test } from '@playwright/test';

/**
 * The override queue derives each request's status label from its approval or review
 * (there is no status column on the request), so the list must ask the API for them. It once
 * asked only for the item — every request then read "Pending" whatever had been decided, a
 * defect nothing caught while the demo data contained no decided request. The demo data now has
 * one request in each later state (NegativeStockLifecycleDemoSeeder) beside StockDemoSeeder's
 * two undecided ones.
 */
test('the override list shows what each request has actually been decided or reviewed as', async ({ page }) => {
  await page.goto('/negative-stock-overrides');
  await expect(page.getByRole('heading', { name: /Negative-Stock Override/i }).first()).toBeVisible();

  const rowFor = (item: string, status: string) =>
    page.locator('tbody tr', { hasText: item }).filter({ hasText: status });

  // A prior-approval item with one request of each decision.
  await expect(rowFor('demo-neg-primer', 'Rejected')).toHaveCount(1);
  await expect(rowFor('demo-neg-primer', 'Approved')).toHaveCount(1);
  // Immediate overrides carry a review outcome instead.
  await expect(rowFor('demo-neg-hardener', 'Justified')).toHaveCount(1);
  await expect(rowFor('demo-neg-sealant', 'Flagged')).toHaveCount(1);
  // The stock seeder's requests are deliberately still undecided.
  await expect(rowFor('demo-pig-blue', 'Pending approval')).not.toHaveCount(0);
});
