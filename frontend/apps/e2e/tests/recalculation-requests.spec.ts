import { expect, test } from '@playwright/test';

/**
 * A recalculation request has no status column: the list derives Executed / Rejected / Pending
 * review from its latest impact version, so what it shows depends on the API returning the
 * approval and execution facts. The demo data now has one request in each later state
 * (RecalculationLifecycleDemoSeeder) beside StockDemoSeeder's own. Only the states that never
 * change with time are asserted: an approval expires after its TTL, so "Approved — ready to
 * execute" and "overdue" drift and would make this spec flaky.
 */
test('the recalculation list shows what each request has actually reached', async ({ page }) => {
  await page.goto('/recalculation-requests');
  await expect(page.getByRole('heading', { name: 'Stock Recalculation' })).toBeVisible();

  const rowFor = (item: string, status: string) =>
    page.locator('tbody tr', { hasText: item }).filter({ hasText: status });

  await expect(rowFor('demo-recalc-titanium', 'Executed')).toHaveCount(1);
  await expect(rowFor('demo-recalc-enamel', 'Executed')).toHaveCount(1);
  await expect(rowFor('demo-recalc-binder', 'Rejected')).toHaveCount(1);
  await expect(rowFor('demo-recalc-stain', 'Pending review')).toHaveCount(1);
});
