import { expect, test } from '@playwright/test';

import { pickSelect } from './support';

test.describe('Settings → Document Numbering', () => {
  test('lists every numbered document type with its format and a live example', async ({ page }) => {
    await page.goto('/settings/document-numbering');
    await expect(page.getByRole('heading', { name: 'Document numbering' })).toBeVisible();

    for (const label of ['Purchase order', 'Goods receipt']) {
      const row = page.getByRole('row').filter({ hasText: label });
      await expect(row).toBeVisible();
      await expect(row.locator('td').nth(2)).toHaveText(/\S/); // an example, whatever the format is
      await expect(row.getByRole('button', { name: new RegExp(`(Edit|View) ${label} numbering`) })).toBeVisible();
    }
  });

  test('the tester explains a format as it is typed, rejects colliding counters, and a change needs a reason and can be put back', async ({ page }) => {
    await page.goto('/settings/document-numbering');
    await page.getByRole('button', { name: 'Edit Purchase order numbering' }).click();
    const sheet = page.getByRole('dialog', { name: 'Purchase order numbers' });
    await expect(sheet).toBeVisible();
    await expect(page).toHaveURL(/\/settings\/document-numbering\/PURCHASE_ORDER$/);

    const format = sheet.getByLabel('Number format');
    const original = await format.inputValue();
    const example = sheet.getByTestId('numbering-example');
    await expect(example).toHaveText(/\S/);

    // A dated, yearly format: the tester shows the example, the counter, when it restarts and the next period
    await format.fill('PO-{YYYY}-{SEQ:5}');
    await pickSelect(page, 'Restart the count', 'Every calendar year');
    await expect(example).toHaveText(/^PO-\d{4}-\d{5}$/);
    await expect(sheet.getByText('Company + Year')).toBeVisible();
    await expect(sheet.getByText(/Restarts at 1 on the first number issued in each calendar year/)).toBeVisible();
    await expect(sheet.getByText('First number of the next period')).toBeVisible();
    await expect(sheet.getByText(/not its order or document date/)).toBeVisible();
    await expect(sheet.getByText('No two counters can issue the same number.')).toBeVisible();

    // A yearly counter whose text never names the year would repeat 00001 every year: refused, and Save is disabled
    await format.fill('PO-{SEQ:5}');
    await expect(sheet.getByText(/both produce PO-/).first()).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Save format' })).toBeDisabled();

    // A format the framework does not know is explained, not saved
    await format.fill('PO-{NOPE}-{SEQ:5}');
    await expect(sheet.getByText(/\{NOPE\} is not available for Purchase order numbers/)).toBeVisible();

    // Variables insert at the cursor
    await format.fill('PO-');
    await sheet.getByRole('button', { name: /\{YYYY\}/ }).click();
    await expect(format).toHaveValue('PO-{YYYY}');

    // Putting it back exactly: nothing to save, so the button says so
    await format.fill(original);
    await pickSelect(page, 'Restart the count', 'Never restart');
    await expect(sheet.getByRole('button', { name: 'No change to save' })).toBeDisabled();
  });

  test('a saved change keeps its reason in the history and applies from now on; the original can be restored', async ({ page }) => {
    await page.goto('/settings/document-numbering/PURCHASE_ORDER');
    const sheet = page.getByRole('dialog', { name: 'Purchase order numbers' });
    const format = sheet.getByLabel('Number format');
    await expect(format).toHaveValue(/\S/);
    const original = await format.inputValue();
    const reason = `End-to-end test of a format change ${Date.now()}`;

    try {
      await format.fill(`${original.replace(/\{SEQ:\d+\}/, '')}E2E-{SEQ:6}`);
      await expect(sheet.getByTestId('numbering-example')).toHaveText(/E2E-\d{6}$/);
      await sheet.getByLabel('Why is it changing?').fill(reason);
      await sheet.getByRole('button', { name: 'Save format' }).click();
      await expect(sheet.getByText('Saved. New numbers follow the new format.')).toBeVisible();

      // The change is in the history with its reason, beside the version it replaced
      await sheet.getByRole('button', { name: 'Advanced options' }).click();
      await page.getByRole('menuitemcheckbox', { name: 'History' }).click();
      await page.keyboard.press('Escape');
      const history = sheet.getByRole('list', { name: 'Format history' });
      await expect(history.getByText(reason)).toBeVisible();
      await expect(history.getByText('In force').first()).toBeVisible();
    } finally {
      // Always put the original back, even when an assertion above failed, so a failed run never leaves numbering changed.
      await format.fill(original);
      if (await sheet.getByRole('button', { name: 'Save format' }).isEnabled()) {
        await sheet.getByLabel('Why is it changing?').fill('Restoring the original format after the test');
        await sheet.getByRole('button', { name: 'Save format' }).click();
        await expect(sheet.getByText('Saved. New numbers follow the new format.')).toBeVisible();
      }
    }
    await expect(sheet.getByRole('button', { name: 'No change to save' })).toBeDisabled();
  });

  test('a company constant is created, offered as a variable, and retired', async ({ page }) => {
    await page.goto('/settings/document-numbering');
    await page.getByRole('button', { name: 'Company constants' }).click();
    await page.getByLabel('Name', { exact: true }).fill('E2E_REGION');
    await page.getByLabel('Value', { exact: true }).fill('NORTH');
    await page.getByLabel('Why', { exact: true }).fill('End-to-end test');
    await page.getByRole('button', { name: 'Save constant' }).click();
    const list = page.getByRole('list', { name: 'Constants in force' });
    await expect(list.getByText('{E2E_REGION}')).toBeVisible();

    // It is offered in the editor and usable in a format
    await page.getByRole('button', { name: 'Edit Purchase order numbering' }).click();
    const sheet = page.getByRole('dialog', { name: 'Purchase order numbers' });
    await expect(sheet.getByRole('button', { name: /\{E2E_REGION\}/ })).toBeVisible();
    await sheet.getByLabel('Number format').fill('{E2E_REGION}-{SEQ:4}');
    await expect(sheet.getByTestId('numbering-example')).toHaveText(/^NORTH-\d{4}$/);
    await sheet.getByRole('button', { name: 'Close' }).click();

    // Retire it again (the list behind the sheet is still there)
    await expect(page.getByRole('list', { name: 'Constants in force' })).toBeVisible();
    await page.getByRole('listitem').filter({ hasText: '{E2E_REGION}' }).getByRole('button', { name: 'Retire' }).click();
    await expect(page.getByRole('listitem').filter({ hasText: '{E2E_REGION}' })).toHaveCount(0);
  });

  test('the screen has no serious accessibility violations', async ({ page }) => {
    const { default: AxeBuilder } = await import('@axe-core/playwright');
    await page.goto('/settings/document-numbering');
    await expect(page.getByRole('heading', { name: 'Document numbering' })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Purchase order' })).toBeVisible();
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''))).toEqual([]);
  });
});
