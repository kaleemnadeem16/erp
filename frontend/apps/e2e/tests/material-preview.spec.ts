import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { pickCombobox, pickSelect } from './support';

/**
 * The Material Preview screen's inventory proposal (ADR 0058): a permitted
 * user sees advisory stock availability beside the technical requirement, can
 * narrow the stock scope and choose a strategy, and nothing on the screen
 * offers to reserve. Runs against the demo data (`demo-asm-wall-paint`, whose
 * blue pigment is stocked in `demo-main`) as the demo login, who holds every
 * registered permission.
 */
test.describe('material preview inventory proposal', () => {
  test('shows advisory availability with an explained recommendation, and stays accessible', async ({ page }) => {
    await page.goto('/material-preview');
    await expect(page.getByRole('heading', { name: 'Material Preview' })).toBeVisible();

    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByLabel('Work quantity', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Calculate preview' }).click();

    const panel = page.locator('section[aria-labelledby="available-stock-heading"]');
    await expect(panel.getByRole('heading', { name: 'Available stock' })).toBeVisible();
    // Precision is stated, never implied: the first slice is quantity-only.
    await expect(panel.getByText('Quantity only — approximate')).toBeVisible();
    await expect(panel.getByRole('table', { name: /Recommended draw of demo-pig-blue/ })).toBeVisible();
    await expect(panel.getByText(/reserves nothing/)).toBeVisible();
    await expect(page.getByText('Advisory — not reserved')).toBeVisible();

    for (const summary of await panel.locator('summary').all()) await summary.click();
    await expect(panel.getByText('Fewest physical picks')).toBeVisible();

    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('narrowing the scope and choosing a strategy change the question that is asked', async ({ page }) => {
    await page.goto('/material-preview');
    await expect(page.getByRole('heading', { name: 'Material Preview' })).toBeVisible();

    // The strategy control is an advanced option: absent until opted into.
    await expect(page.getByLabel('Selection strategy', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Advanced options' }).click();
    await page.getByRole('menuitemcheckbox', { name: 'Choose stock selection strategy' }).click();
    await page.keyboard.press('Escape');
    await pickSelect(page, 'Selection strategy', 'Fewest packages');

    await pickCombobox(page, 'Warehouse', /demo-main/);
    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByLabel('Work quantity', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Calculate preview' }).click();

    const panel = page.locator('section[aria-labelledby="available-stock-heading"]');
    await expect(panel.getByText(/Proposed by fewest packages \(chosen for this preview\)/)).toBeVisible();
    await expect(panel.getByText(/limited to the stock scope you selected/)).toBeVisible();

    // Changing any input discards a result that no longer answers the question on screen.
    await page.getByLabel('Work quantity', { exact: true }).fill('20');
    await expect(panel).toHaveCount(0);
  });
});
