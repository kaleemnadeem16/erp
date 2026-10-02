import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { pickCombobox, pickSelect } from './support';

/**
 * Stored selection preferences (ADR 0071). Scoped to a dedicated demo item (`demo-neg-hardener`, used by no
 * preview spec) so what this changes cannot alter what another spec sees. Every run ends by clearing the
 * preference again, leaving two more history rows on that item — the history the screen exists to keep.
 */
const ITEM = 'demo-neg-hardener';

async function open(page: Page) {
  await page.goto('/settings/material-selection');
  await expect(page.getByRole('heading', { name: 'Material selection' })).toBeVisible();
}

async function chooseItem(page: Page) {
  await pickSelect(page, 'Preference applies to', 'One item');
  await pickCombobox(page, 'Item', ITEM);
  await expect(page.getByRole('heading', { name: new RegExp(`Preference for ${ITEM}`) })).toBeVisible();
}

test('the demo preferences show where each level is set', async ({ page }) => {
  await open(page);
  const overview = page.locator('section', { hasText: 'Where a preference is set' });
  await expect(overview.getByText('Lowest internal cost').first()).toBeVisible();
  await expect(overview.getByText('Fewest packages').first()).toBeVisible();
});

test('a preference needs a reason, is kept as history, and can be removed', async ({ page }) => {
  // Each run adds history rows, so its reasons are unique to it.
  const run = Date.now();
  const setReason = `Opening tins takes time on this job (${run})`;
  const clearReason = `Back to the default (${run})`;
  await open(page);
  await chooseItem(page);
  // Start from "nothing set" whatever an earlier, interrupted run left behind (saving an unchanged value writes no row).
  await expect(page.getByText(/^(Currently |Nothing is set here)/)).toBeVisible();
  if ((await page.getByText(/^Currently /).count()) > 0) {
    await pickSelect(page, 'Selection preset', 'No preference here (use the next level up)');
    await page.getByLabel('Reason for the change').fill(`Reset before the run (${run})`);
    await page.getByRole('button', { name: 'Save preference' }).click();
  }
  await expect(page.getByText('Nothing is set here')).toBeVisible();

  await pickSelect(page, 'Selection preset', 'Fewest packages');
  await page.getByRole('button', { name: 'Save preference' }).click();
  await expect(page.getByText('A reason is required')).toBeVisible();

  await page.getByLabel('Reason for the change').fill(setReason);
  await page.getByRole('button', { name: 'Save preference' }).click();
  await expect(page.getByText('Currently Fewest packages.')).toBeVisible();
  const history = page.locator('li', { hasText: setReason });
  await expect(history).toContainText('in force');

  await pickSelect(page, 'Selection preset', 'No preference here (use the next level up)');
  await page.getByLabel('Reason for the change').fill(clearReason);
  await page.getByRole('button', { name: 'Save preference' }).click();
  await expect(page.getByText('Nothing is set here')).toBeVisible();
  await expect(page.locator('li', { hasText: clearReason })).toContainText('Preference removed');
  await expect(history).not.toContainText('in force');
});

test('the settings screen has no accessibility violations', async ({ page }) => {
  await open(page);
  await chooseItem(page);
  // Serious and critical only, like the other settings specs: the moderate "region" finding is the app shell's sidebar.
  const serious = (await new AxeBuilder({ page }).analyze()).violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
  expect(serious).toEqual([]);
});

/**
 * The company's control over hand-chosen stock (ADR 0076). It is company-wide, so the spec changes the one real rule and
 * always puts it back to the demo's value (reason needed above 10%) before finishing — and starts by doing the same, so
 * an interrupted earlier run leaves nothing behind. Its reasons are unique to the run because every change is history.
 */
test.describe('stock-choice control', () => {
  async function setLevel(page: Page, level: string | RegExp, threshold: string | null, reason: string) {
    await pickSelect(page, 'Stock choice level', level);
    if (threshold !== null) await page.getByLabel('Percentage above the proposal').fill(threshold);
    await page.getByLabel('Reason for the stock choice change').fill(reason);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
  }

  async function restoreDemoRule(page: Page, run: number) {
    await page.goto('/settings/material-selection');
    await expect(page.getByRole('heading', { name: 'Choosing stock by hand' })).toBeVisible();
    await expect(page.getByText(/^Currently: /)).toBeVisible();
    if (!(await page.getByText('Currently: Reason needed when it costs much more — 10% above the proposal.').count())) {
      await setLevel(page, /Reason needed/, '10', `Back to the demo rule (${run})`);
    }
    await expect(page.getByText('Currently: Reason needed when it costs much more — 10% above the proposal.')).toBeVisible();
  }

  test('a level needs a reason and a percentage, is kept as history, and decides what the preview offers', async ({ page }) => {
    const run = Date.now();
    await restoreDemoRule(page, run);

    // The two stricter levels act on a percentage: refused before anything is sent.
    await pickSelect(page, 'Stock choice level', /Reason needed/);
    await page.getByLabel('Percentage above the proposal').fill('');
    await page.getByLabel('Reason for the stock choice change').fill('Check the percentage is required');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Enter a percentage above zero')).toBeVisible();

    // "Not allowed" takes the control away from the preview.
    await setLevel(page, 'Not allowed', null, `Follow the proposal for now (${run})`);
    await expect(page.getByText('Currently: Not allowed.')).toBeVisible();
    await expect(page.locator('li', { hasText: `Follow the proposal for now (${run})` })).toContainText('in force');

    await page.goto('/material-preview');
    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByLabel('Work quantity', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Calculate preview' }).click();
    const panel = page.locator('section[aria-labelledby="available-stock-heading"]');
    await expect(panel.getByText(/choosing stock by hand is not offered/)).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Choose the stock yourself' })).toHaveCount(0);

    // Back to the demo rule: the reason box appears with the control.
    await restoreDemoRule(page, run);
    await page.goto('/material-preview');
    await pickCombobox(page, 'Assembly', /demo-asm-wall-paint/);
    await page.getByLabel('Work quantity', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Calculate preview' }).click();
    await panel.getByRole('button', { name: 'Choose the stock yourself' }).first().click();
    await expect(panel.getByLabel(/^Reason for the mix of /).first()).toBeVisible();
  });
});
