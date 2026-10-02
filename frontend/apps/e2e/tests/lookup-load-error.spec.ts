import { expect, test } from '@playwright/test';

/**
 * A dropdown whose options failed to load must say so (Q32, ADR 0064) instead of reading as
 * "no results", and must let the person try again without reloading the page. The Work unit picker on
 * Material Preview is the probe: its units request is made to fail, then to succeed.
 */
test('a picker whose list failed says why and recovers on retry', async ({ page }) => {
  let failing = true;
  await page.route(/\/api\/v1\/uoms\?/, async (route) => {
    if (failing) {
      await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ message: 'Forbidden' }) });
    } else {
      await route.continue();
    }
  });

  await page.goto('/material-preview');
  const picker = page.getByRole('combobox', { name: 'Work unit' });
  await expect(picker).toContainText("Couldn't load units");
  await expect(picker).toHaveAttribute('aria-invalid', 'true');

  await picker.click();
  const alert = page.getByRole('alert').filter({ hasText: "Couldn't load units." });
  await expect(alert).toContainText("You don't have permission to view this.");
  // Not the misleading empty-list wording.
  await expect(page.getByText('No units found.')).toHaveCount(0);

  failing = false;
  await alert.getByRole('button', { name: 'Try again' }).click();
  await expect(alert).toHaveCount(0);
  await expect(page.getByPlaceholder('Search units…')).toBeVisible();
  await expect(page.getByRole('option').first()).toBeVisible();
});
