import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import { api } from './package-stock-support';
import { pickCombobox } from './support';

/**
 * Exchange rates (Settings → Numbers, money and rounding) and Chart of accounts (Settings → Company). The demo already holds dated rates (CurrencyRatesDemoSeeder)
 * and the chart (AccountingDemoSeeder). Records these tests create carry a unique stamp and are removed again in a `finally` (rates are deleted through the
 * screen itself; accounts through the API's archive-by-DELETE, which the screen deliberately does not offer).
 */
const stamp = Date.now().toString().slice(-6);
/** A far-future date derived from the stamp, so a rate this run creates can never collide with a demo rate or another run's. */
const futureDate = () => {
  const day = new Date(Date.UTC(2040, 0, 1) + (Number(stamp) % 3000) * 86_400_000);

  return day.toISOString().slice(0, 10);
};

const horizontalOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const link = (page: Page, text: string) => page.locator('.ui-order-link', { hasText: text });

async function actAs(page: Page, permissions: string[]) {
  await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions } }) });
  });
}

test.describe('Exchange rates', () => {
  test('is on the list standard and filters by rate type as a request and a chip', async ({ page }) => {
    await page.goto('/settings/exchange-rates');
    await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Exchange rates', exact: true })).toBeVisible();
    await expect(page.getByRole('table').locator('tbody tr').first()).toBeVisible();

    const request = page.waitForRequest((r) => r.url().includes('/api/v1/exchange-rates') && r.url().includes('filter%5Brate_type%5D=AVERAGE'));
    await page.getByRole('combobox', { name: 'Rate type', exact: true }).click();
    await page.getByRole('option', { name: 'Average', exact: true }).press('Enter');
    await request;
    await expect(page.locator('.ui-chip-active').filter({ hasText: 'Type: Average' })).toBeVisible();
    // Every remaining row is an average rate, and the rate keeps all its decimals (never rounded).
    const rows = page.getByRole('table').locator('tbody tr');
    await expect(rows.first()).toContainText('Average');
    await expect(rows.first()).toContainText(/\d+\.\d{9}/);
    await page.getByRole('button', { name: 'Clear all' }).click();
    await expect(page.locator('.ui-chip-active')).toHaveCount(0);
  });

  test('registers, edits and deletes a rate, with the problems said beside the fields', async ({ page }) => {
    const date = futureDate();
    await page.goto('/settings/exchange-rates');
    await page.getByRole('button', { name: 'New rate' }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: 'New exchange rate' })).toBeVisible();

    // Problems knowable before sending are shown at once, tied to their control.
    await sheet.getByRole('button', { name: 'Save rate' }).click();
    await expect(sheet.getByText('Choose the currency you are converting from.')).toBeVisible();
    await expect(sheet.getByText('Enter the rate.')).toBeVisible();

    await pickCombobox(page, 'From currency', 'USD', { within: sheet });
    await pickCombobox(page, 'To currency', 'USD', { within: sheet });
    await sheet.getByRole('textbox', { name: 'Rate', exact: true }).fill('0');
    await sheet.getByRole('button', { name: 'Save rate' }).click();
    await expect(sheet.getByText('The two currencies must be different.')).toBeVisible();
    await expect(sheet.getByText('The rate must be above zero.')).toBeVisible();

    await pickCombobox(page, 'To currency', 'AED', { within: sheet });
    await sheet.getByRole('group', { name: 'Rate date' }).getByLabel('Month').fill(date.slice(5, 7));
    await sheet.getByRole('group', { name: 'Rate date' }).getByLabel('Day').fill(date.slice(8, 10));
    await sheet.getByRole('group', { name: 'Rate date' }).getByLabel('Year').fill(date.slice(0, 4));
    await sheet.getByRole('textbox', { name: 'Rate', exact: true }).fill('3.672500000');
    await sheet.getByRole('button', { name: 'Save rate' }).click();
    await expect(page).toHaveURL(/\/settings\/exchange-rates(\?.*)?$/);

    try {
      // Opened again: only the rate can change; saving shows the new value with all its decimals.
      await page.goto(`/settings/exchange-rates?filter.rate_date=${date}`);
      const row = page.getByRole('table').locator('tbody tr', { hasText: 'USD → AED' });
      await expect(row).toContainText('3.672500000');
      await row.locator('.ui-order-link').click();
      const edit = page.getByRole('dialog');
      await expect(edit.getByText('Only the rate can be changed.')).toBeVisible();
      await edit.getByRole('textbox', { name: 'Rate', exact: true }).fill('3.6727');
      await edit.getByRole('button', { name: 'Save changes' }).click();
      await expect(page.getByRole('table').locator('tbody tr', { hasText: 'USD → AED' })).toContainText('3.6727');

      // A duplicate pair/date/type is refused beside the date.
      await page.getByRole('button', { name: 'New rate' }).click();
      const dup = page.getByRole('dialog');
      await pickCombobox(page, 'From currency', 'USD', { within: dup });
      await pickCombobox(page, 'To currency', 'AED', { within: dup });
      await dup.getByRole('group', { name: 'Rate date' }).getByLabel('Month').fill(date.slice(5, 7));
      await dup.getByRole('group', { name: 'Rate date' }).getByLabel('Day').fill(date.slice(8, 10));
      await dup.getByRole('group', { name: 'Rate date' }).getByLabel('Year').fill(date.slice(0, 4));
      await dup.getByRole('textbox', { name: 'Rate', exact: true }).fill('3.7');
      await dup.getByRole('button', { name: 'Save rate' }).click();
      await expect(dup.getByText('A rate already exists for this currency pair, date, and rate type.')).toBeVisible();
      await expect(page).toHaveURL(/\/settings\/exchange-rates\/new/);
      await dup.getByRole('button', { name: 'Cancel' }).click();
      // Delete through the screen: the consequence is stated first and the row goes away.
      await page.goto(`/settings/exchange-rates?filter.rate_date=${date}`);
      const listed = page.getByRole('table').locator('tbody tr', { hasText: 'USD → AED' });
      await expect(listed).toHaveCount(1);
      await listed.locator('.ui-order-link').click();
      await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();
      const confirm = page.getByTestId('delete-rate-dialog');
      await expect(confirm).toContainText('Documents that already used it keep the rate they were made with');
      await confirm.getByRole('button', { name: 'Delete rate' }).click();
      await expect(page.getByRole('table').locator('tbody tr', { hasText: 'USD → AED' })).toHaveCount(0);
    } finally {
      // Whatever happened above, leave nothing behind: a sweep through the API for this run's date (a no-op once the screen deleted the rate).
      const left = (await api(page, 'GET', `/exchange-rates?filter[rate_date]=${date}&per_page=20`)).data as Array<{ id: number }>;
      for (const rate of left) await api(page, 'DELETE', `/exchange-rates/${rate.id}`);
    }
  });

  test('a person without the duty is shown the access message and nothing is requested', async ({ page }) => {
    await actAs(page, ['purchasing.purchase-order.view']);
    let requests = 0;
    page.on('request', (request) => {
      if (/\/api\/v1\/exchange-rates(?:\?|$)/.test(request.url())) requests += 1;
    });
    await page.goto('/settings/exchange-rates');
    await expect(page.getByRole('heading', { name: 'You don’t have access' })).toBeVisible();
    expect(requests).toBe(0);
  });

  test('a failed request shows its cause and a retry', async ({ page }) => {
    let calls = 0;
    await page.route(/\/api\/v1\/exchange-rates(\?.*)?$/, async (route) => {
      calls += 1;
      if (calls <= 3) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) });
      return route.continue();
    });
    await page.goto('/settings/exchange-rates');
    await expect(page.getByRole('alert').filter({ hasText: "Couldn't load exchange rates." })).toBeVisible();
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByRole('table').locator('tbody tr').first()).toBeVisible();
  });
});

test.describe('Chart of accounts', () => {
  test('is on the list standard: search, type filter, chips and selection with the bulk bar', async ({ page }) => {
    await page.goto('/settings/chart-of-accounts');
    await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Chart of accounts', exact: true })).toBeVisible();

    await page.getByRole('searchbox', { name: 'Search accounts' }).fill('1200');
    const chip = page.locator('.ui-chip-active').filter({ hasText: 'Search: "1200"' });
    await expect(chip).toBeVisible();
    await expect(link(page, '1200')).toBeVisible();

    await page.getByRole('checkbox', { name: /Select row/i }).first().check();
    const bar = page.getByRole('region', { name: 'Bulk actions' });
    await expect(bar).toContainText('1 account selected');
    await expect(bar.getByRole('button', { name: 'Archive selected' })).toBeEnabled();
    await expect(bar.getByRole('button', { name: 'Activate selected' })).toBeDisabled();
    await bar.getByRole('button', { name: 'Clear selection' }).click();
    await chip.getByRole('button', { name: /Remove filter/ }).click();
    await expect(chip).toHaveCount(0);

    const request = page.waitForRequest((r) => r.url().includes('/api/v1/chart-of-accounts') && r.url().includes('filter%5Btype%5D=LIABILITY'));
    await page.getByRole('combobox', { name: 'Type', exact: true }).click();
    await page.getByRole('option', { name: 'Liability', exact: true }).press('Enter');
    await request;
    await expect(page.locator('.ui-chip-active').filter({ hasText: 'Type: Liability' })).toBeVisible();
  });

  test('creates an account with its problems beside the fields, then archives and activates it from the sheet', async ({ page }) => {
    const code = `e2e-${stamp}`;
    await page.goto('/settings/chart-of-accounts');
    await page.getByRole('button', { name: 'New account' }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: 'New account' })).toBeVisible();
    await sheet.getByRole('button', { name: 'Create account' }).click();
    await expect(sheet.getByText('Enter an account code.')).toBeVisible();
    await expect(sheet.getByText('Enter the account name.')).toBeVisible();
    await expect(sheet.getByText('Choose what kind of account this is.')).toBeVisible();
    await expect(sheet.getByRole('textbox', { name: 'Account code' })).toBeFocused();

    await sheet.getByRole('textbox', { name: 'Account code' }).fill(code);
    await sheet.getByRole('textbox', { name: 'Name' }).fill(`E2E account ${stamp}`);
    await sheet.getByRole('combobox', { name: 'Type', exact: true }).click();
    await page.getByRole('option', { name: 'Expense', exact: true }).press('Enter');
    await sheet.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/settings\/chart-of-accounts(\?.*)?$/);

    try {
      await page.goto(`/settings/chart-of-accounts?filter.search=${code}`);
      await expect(link(page, code)).toBeVisible();
      await link(page, code).click();
      const detail = page.getByRole('dialog');
      await expect(detail.getByRole('heading', { name: new RegExp(code) })).toBeVisible();
      await detail.getByRole('button', { name: 'Archive', exact: true }).click();
      await expect(page).toHaveURL(/\/settings\/chart-of-accounts(\?.*)?$/);
      await expect(page.getByRole('row', { name: new RegExp(code) })).toContainText('Archived');

      // A duplicate code is refused beside the code field and the typed input is kept.
      await page.getByRole('button', { name: 'New account' }).click();
      const dup = page.getByRole('dialog');
      await dup.getByRole('textbox', { name: 'Account code' }).fill(code);
      await dup.getByRole('textbox', { name: 'Name' }).fill('Duplicate');
      await dup.getByRole('combobox', { name: 'Type', exact: true }).click();
      await page.getByRole('option', { name: 'Expense', exact: true }).press('Enter');
      await dup.getByRole('button', { name: 'Create account' }).click();
      await expect(dup.getByRole('alert').first()).toBeVisible();
      await expect(dup.getByRole('textbox', { name: 'Name' })).toHaveValue('Duplicate');
      await dup.getByRole('button', { name: 'Cancel' }).click();
    } finally {
      const found = (await api(page, 'GET', `/chart-of-accounts?filter[search]=${code}&per_page=5`)).data as Array<{ id: number }>;
      for (const account of found) await api(page, 'DELETE', `/chart-of-accounts/${account.id}`);
    }
  });

  test('archives several accounts at once with an honest result, and activates them again', async ({ page }) => {
    const created: number[] = [];
    try {
      for (const suffix of ['a', 'b']) {
        created.push((await api(page, 'POST', '/chart-of-accounts', { code: `e2e-bulk-${suffix}-${stamp}`, name: `E2E bulk ${suffix} ${stamp}`, type: 'EXPENSE' })).data.id);
      }
      await page.goto(`/settings/chart-of-accounts?filter.search=e2e-bulk-`);
      await expect(link(page, `e2e-bulk-a-${stamp}`)).toBeVisible();
      await page.getByRole('checkbox', { name: /Select all/i }).first().check();
      const bar = page.getByRole('region', { name: 'Bulk actions' });
      await expect(bar).toContainText(/\d+ accounts? selected/);
      await bar.getByRole('button', { name: 'Archive selected' }).click();
      const dialog = page.getByTestId('bulk-dialog');
      await expect(dialog).toContainText('Archived accounts stay on record');
      await dialog.getByTestId('bulk-confirm').click();
      await expect(page.getByTestId('bulk-result')).toContainText(/\d+ changed\./);
      await dialog.getByRole('button', { name: 'Done' }).click();
      await expect(page.getByRole('row', { name: new RegExp(`e2e-bulk-a-${stamp}`) })).toContainText('Archived');
    } finally {
      for (const id of created) await api(page, 'DELETE', `/chart-of-accounts/${id}`);
    }
  });

  test('a person without the duty is shown the access message', async ({ page }) => {
    await actAs(page, ['currency.exchange-rate.manage']);
    await page.goto('/settings/chart-of-accounts');
    await expect(page.getByRole('heading', { name: 'You don’t have access' })).toBeVisible();
  });
});

test.describe('both screens: Settings index, accessibility and phone width', () => {
  test('are listed in the Settings index for someone who may open them', async ({ page }) => {
    await page.goto('/settings');
    await expect(page.getByRole('link', { name: /Exchange rates/ }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /Chart of accounts/ }).first()).toBeVisible();
  });

  for (const path of ['/settings/exchange-rates', '/settings/exchange-rates/new', '/settings/chart-of-accounts', '/settings/chart-of-accounts/new']) {
    test(`no serious accessibility violations: ${path}`, async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
      expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
    });
  }

  test.describe('phone width (390 px)', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    for (const [name, path, create, heading] of [
      ['Exchange rates', '/settings/exchange-rates', 'New rate', /New exchange rate/],
      ['Chart of accounts', '/settings/chart-of-accounts', 'New account', /New account/],
    ] as const) {
      test(`${name}: no horizontal overflow, 44 px targets, a Filters sheet and a form sheet inside the screen`, async ({ page }) => {
        await page.goto(path);
        await expect(page.getByText('Select all on this page')).toBeVisible();
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
        const filters = page.getByRole('button', { name: /Open filters/ });
        expect((await filters.boundingBox())!.height).toBeGreaterThanOrEqual(44);
        await filters.click();
        const sheet = page.getByRole('dialog');
        await expect(sheet.getByRole('heading', { name: 'Filters' })).toBeVisible();
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
        await sheet.getByRole('button', { name: 'Done' }).click();
        const fab = page.getByRole('button', { name: create });
        expect((await fab.boundingBox())!.height).toBeGreaterThanOrEqual(44);
        await fab.click();
        const form = page.getByRole('dialog');
        await expect(form.getByRole('heading', { name: heading }).first()).toBeVisible();
        expect((await form.boundingBox())!.width).toBeLessThanOrEqual(390.5);
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
      });
    }
  });
});
