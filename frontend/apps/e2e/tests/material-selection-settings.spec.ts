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
