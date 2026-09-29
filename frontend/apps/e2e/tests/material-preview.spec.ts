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

/**
 * The what-if half (ADR 0054 + 0058): a planned quantity is judged against the calculated standard, a
 * difference that needs a reason says so until one is given, and the stock proposal follows the plan.
 * Nothing is saved. Uses the pigment of `demo-asm-wall-paint`; the reason is created here when absent, so
 * the spec does not lean on any other spec's leftovers. Which effect applies to the pigment depends on the
 * company's configured rules, so the spec asserts the flow, not a particular policy.
 */
test.describe('material preview what-if plan', () => {
  const REASON_CODE = 'e2e-preview-reason';
  const REASON_NAME = 'End-to-end preview reason';

  test.beforeEach(async ({ page }) => {
    await page.goto('/settings/material-variance');
    await expect(page.getByRole('heading', { name: 'Material variance' })).toBeVisible();
    const row = page.locator('li', { hasText: `(${REASON_CODE})` });
    if ((await row.count()) === 0) {
      await page.getByRole('button', { name: 'Add a reason' }).click();
      await page.getByLabel('Name', { exact: true }).fill(REASON_NAME);
      await page.getByLabel('Code', { exact: true }).fill(REASON_CODE);
      await page.getByRole('button', { name: 'Add reason' }).click();
    } else if ((await row.getByText('Retired').count()) > 0) {
      await row.getByRole('button', { name: /^Reinstate/ }).click();
    }
    await expect(row).toBeVisible();
    await expect(row.getByText('Retired')).toHaveCount(0);
  });

  test('a planned quantity is judged against the standard, and the stock proposal follows the plan', async ({ page }) => {
    await page.goto('/material-preview');
    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByLabel('Work quantity', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Calculate preview' }).click();

    const editor = page.getByLabel('Planned quantity of demo-pig-blue');
    await expect(editor).toBeVisible();
    // No verdict until a plan is entered, and no claim that anything can be adopted.
    await expect(page.getByRole('group', { name: /Variance verdict/ })).toHaveCount(0);

    // Plan far above the standard, no reason yet.
    await editor.fill('999');
    await expect(page.getByText(/Calculate the preview again to judge/)).toBeVisible();
    await page.getByRole('button', { name: 'Calculate preview' }).click();

    const verdict = page.getByRole('group', { name: 'Variance verdict for demo-pig-blue' });
    await expect(verdict).toBeVisible();
    await expect(verdict.getByText(/Standard .* · Planned 999/)).toBeVisible();
    await expect(verdict.getByText('Over plan by', { exact: false })).toBeVisible();

    const stock = page.getByRole('group', { name: /demo-pig-blue —/ }).filter({ hasText: 'Needs 999' });
    await expect(stock.getByText(/planned; the standard is/)).toBeVisible();

    // If the rules demand a reason, the screen says so and cannot-adopt is shown; giving one clears it.
    if ((await verdict.getByText('Reason missing').count()) > 0) {
      await expect(page.getByText('This plan cannot be adopted yet.')).toBeVisible();
      await pickSelect(page, 'Reason for the plan of demo-pig-blue', REASON_NAME);
      await page.getByRole('button', { name: 'Calculate preview' }).click();
      await expect(verdict.getByText('Reason given')).toBeVisible();
      await expect(page.getByText('This plan can be adopted as it stands.')).toBeVisible();
    }

    // Clearing the plan asks the original question again: no verdict, stock back at the standard.
    await editor.fill('');
    await page.getByRole('button', { name: 'Calculate preview' }).click();
    await expect(page.getByRole('group', { name: /Variance verdict/ })).toHaveCount(0);
    await expect(page.getByText(/planned; the standard is/)).toHaveCount(0);
  });

  test('changing the assembly discards the typed plan', async ({ page }) => {
    await page.goto('/material-preview');
    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByRole('button', { name: 'Calculate preview' }).click();
    await page.getByLabel('Planned quantity of demo-pig-blue').fill('5');
    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByRole('button', { name: 'Calculate preview' }).click();
    await expect(page.getByLabel('Planned quantity of demo-pig-blue')).toHaveValue('');
    await expect(page.getByRole('group', { name: /Variance verdict/ })).toHaveCount(0);
  });
});
