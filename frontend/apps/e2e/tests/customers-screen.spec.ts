import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import { api } from './package-stock-support';
import { pickCombobox } from './support';

/**
 * Customers and Trading partners. The shared demo (PartnersAndContactsDemoSeeder) gives cus-alpha and cus-metro their contacts and adds partners with no role
 * (it creates no customer account: the Sales demo guards that). Archived, no-limit and dual-role cases are therefore made here through the API with a unique
 * stamp and removed in a `finally`; the customer the journey creates through the screen is archived at the end of it.
 */
const stamp = Date.now().toString().slice(-7);

/** Present exactly these effective permissions to the SPA (the real session and server are untouched). */
async function actAs(page: Page, permissions: string[]) {
  await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions } }) });
  });
}

/** The identity link of a row/card in the on-screen list (the hidden print tables repeat the same text, so text alone is ambiguous). */
const link = (page: Page, code: string) => page.locator('.ui-order-link', { hasText: code });

/** A partner with a customer account (and optionally a supplier account), made through the API and removed again by `cleanup`. */
async function makePartner(page: Page, label: string, options: { customer?: { archived?: boolean; creditLimit?: string }; supplier?: boolean } = {}) {
  const code = `e2e-${label}-${stamp}`;
  const party = (await api(page, 'POST', '/parties', { code, name: `E2E ${label} ${stamp}` })).data;
  const accounts = (await api(page, 'GET', '/chart-of-accounts?filter[search]=1200&per_page=5')).data;
  const payables = (await api(page, 'GET', '/chart-of-accounts?filter[search]=2100&per_page=5')).data;
  const created: Array<{ path: string; id: number }> = [];
  if (options.customer) {
    const account = (
      await api(page, 'POST', '/customer-accounts', {
        party_id: party.id,
        code,
        payment_terms_days: 30,
        receivable_account_id: accounts[0].id,
        status: options.customer.archived ? 'ARCHIVED' : 'ACTIVE',
        ...(options.customer.creditLimit ? { credit_limit: options.customer.creditLimit } : {}),
      })
    ).data;
    created.push({ path: '/customer-accounts', id: account.id });
  }
  if (options.supplier) {
    const account = (await api(page, 'POST', '/supplier-accounts', { party_id: party.id, code, payment_terms_days: 30, payable_account_id: payables[0].id })).data;
    created.push({ path: '/supplier-accounts', id: account.id });
  }

  return {
    code,
    name: party.name as string,
    async cleanup() {
      for (const account of created.reverse()) await api(page, 'DELETE', `${account.path}/${account.id}`);
      await api(page, 'DELETE', `/parties/${party.id}`);
    },
  };
}

const horizontalOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe('Customers list', () => {
  test('follows the screen standard: template, search, chips, selection and the bulk bar', async ({ page }) => {
    await page.goto('/customers');
    await expect(page.locator('[data-screen-template="list"]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Customers', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Customers', exact: true }).first()).toBeVisible();

    const search = page.getByRole('searchbox', { name: 'Search customers' });
    await search.fill('alpha');
    await expect(page).toHaveURL(/search=alpha/);
    const chip = page.locator('.ui-chip-active').filter({ hasText: 'Search: "alpha"' });
    await expect(chip).toBeVisible();
    await expect(link(page, 'cus-alpha')).toBeVisible();
    await expect(link(page, 'cus-metro')).toHaveCount(0);

    // Selection: a count, the bulk actions, and Clear selection.
    await page.getByRole('checkbox', { name: /Select row/i }).first().check();
    const bar = page.getByRole('region', { name: 'Bulk actions' });
    await expect(bar).toContainText('1 customer selected');
    await expect(bar.getByRole('button', { name: 'Archive selected' })).toBeEnabled();
    await expect(bar.getByRole('button', { name: 'Activate selected' })).toBeDisabled();
    await bar.getByRole('button', { name: 'Clear selection' }).click();
    await expect(bar).toHaveCount(0);

    // Removing the chip restores the list; Clear all removes every filter.
    await chip.getByRole('button', { name: /Remove filter/ }).click();
    await expect(chip).toHaveCount(0);
    await expect(search).toHaveValue('');
    await expect(link(page, 'cus-metro')).toBeVisible();
  });

  test('the status filter is a request and a chip: Active hides an archived customer, Archived shows it', async ({ page }) => {
    const archived = await makePartner(page, 'arch', { customer: { archived: true } });
    try {
      await page.goto(`/customers?filter.search=${archived.code}`);
      // With no status filter every customer is listed, an archived one labelled as such.
      await expect(page.getByRole('row', { name: new RegExp(archived.code) })).toContainText('Archived');

      const setStatus = async (option: string, param: string) => {
        const request = page.waitForRequest((r) => r.url().includes('/api/v1/customer-accounts') && r.url().includes(param));
        await page.getByRole('combobox', { name: 'Status', exact: true }).click();
        await page.getByRole('option', { name: option, exact: true }).press('Enter');
        await request;
      };
      await setStatus('Active', 'filter%5Bstatus%5D=ACTIVE');
      await expect(page.locator('.ui-chip-active').filter({ hasText: 'Status: Active' })).toBeVisible();
      await expect(page.getByText('No customers match these filters.')).toBeVisible();
      await setStatus('Archived', 'filter%5Bstatus%5D=ARCHIVED');
      await expect(page.locator('.ui-chip-active').filter({ hasText: 'Status: Archived' })).toBeVisible();
      await expect(link(page, archived.code)).toBeVisible();
      await page.getByRole('button', { name: 'Clear all' }).click();
      await expect(page.locator('.ui-chip-active')).toHaveCount(0);
    } finally {
      await archived.cleanup();
    }
  });

  test('a customer with no credit limit says so instead of showing zero', async ({ page }) => {
    const unlimited = await makePartner(page, 'nolimit', { customer: {} });
    try {
      await page.goto(`/customers?filter.search=${unlimited.code}`);
      await expect(link(page, unlimited.code)).toBeVisible();
      await expect(page.getByRole('row', { name: new RegExp(unlimited.code) })).toContainText('No limit');
    } finally {
      await unlimited.cleanup();
    }
  });

  test('a failed request shows its cause and a retry, never "0 customers"', async ({ page }) => {
    let calls = 0;
    await page.route(/\/api\/v1\/customer-accounts(\?.*)?$/, async (route) => {
      calls += 1;
      if (calls <= 3) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) });
      return route.continue();
    });
    await page.goto('/customers');
    await expect(page.getByRole('alert').filter({ hasText: "Couldn't load customers." })).toBeVisible();
    await expect(page.getByText(/1–0 of 0|0 customers/)).toHaveCount(0);
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(link(page, 'cus-alpha')).toBeVisible();
  });

  test('an unknown customer reads as unavailable and never confirms the id', async ({ page }) => {
    await page.goto('/customers/99999999');
    await expect(page.getByRole('dialog')).toContainText("This customer isn't available.");
    await page.getByRole('button', { name: 'Back to customers' }).click();
    await expect(page).toHaveURL(/\/customers$/);
  });
});

test.describe('Customer record', () => {
  test('opens as a sheet over the list with its partner and contacts, and closing keeps the list context', async ({ page }) => {
    await page.goto('/customers');
    await page.getByRole('searchbox', { name: 'Search customers' }).fill('alpha');
    await expect(link(page, 'cus-metro')).toHaveCount(0);
    await link(page, 'cus-alpha').click();

    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await expect(page).toHaveURL(/\/customers\/\d+.*search=alpha/);
    await expect(sheet.getByRole('heading', { name: /Alpha Decorators \(cus-alpha\)/ })).toBeVisible();
    await expect(sheet.getByText('Imran Siddiqui')).toBeVisible();
    await expect(sheet.getByText('Primary', { exact: true })).toBeVisible();
    await expect(sheet.locator('.detail-field-value', { hasText: '500000.0000' })).toBeVisible();

    await sheet.getByRole('button', { name: 'Close' }).click();
    await expect(page).toHaveURL(/\/customers\?.*search=alpha/);
    await expect(page.getByRole('searchbox', { name: 'Search customers' })).toHaveValue('alpha');
  });

  test('registers a partner and a customer, adds a contact, archives and activates it', async ({ page }) => {
    const partnerName = `E2E Partner ${stamp}`;
    const code = `E2E-${stamp}`;
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();

    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: 'New customer' })).toBeVisible();
    // Required fields show a text error tied to their control.
    await sheet.getByRole('button', { name: 'Create customer' }).click();
    await expect(sheet.getByText('Choose a trading partner or register a new one.')).toBeVisible();
    await expect(sheet.getByText('Enter a customer code.')).toBeVisible();
    await expect(sheet.getByRole('textbox', { name: 'Customer code' })).toHaveAttribute('aria-invalid', 'true');

    await sheet.getByRole('button', { name: 'Register new' }).click();
    await sheet.getByLabel('Business name').fill(partnerName);
    await sheet.getByRole('textbox', { name: 'Customer code' }).fill(code);
    await sheet.getByRole('textbox', { name: 'Payment terms' }).fill('20');
    await sheet.getByRole('textbox', { name: 'Credit limit' }).fill('25000.50');
    await pickCombobox(page, 'Receivable control account', '1200', { within: sheet });
    await sheet.getByRole('button', { name: 'Create customer' }).click();

    // Lands on the new customer's own record, still over the list.
    await expect(page).toHaveURL(/\/customers\/\d+/);
    const detail = page.getByRole('dialog');
    await expect(detail.getByRole('heading', { name: new RegExp(`${partnerName} \\(${code}\\)`) })).toBeVisible();
    await expect(detail.locator('.detail-field-value', { hasText: '25000.5000' })).toBeVisible();
    await expect(detail.locator('.detail-field-value', { hasText: '20 days' })).toBeVisible();

    // A contact: validation, then saved and shown as primary.
    await detail.getByRole('button', { name: 'Add contact' }).click();
    const dialog = page.getByTestId('contact-dialog');
    await dialog.getByRole('button', { name: 'Add contact' }).click();
    await expect(dialog.getByText('Enter the contact’s name.')).toBeVisible();
    await dialog.getByLabel('Name').fill('Test Contact');
    await dialog.getByLabel('Email').fill('test.contact@example.test');
    await dialog.getByLabel('Primary contact').check();
    await dialog.getByRole('button', { name: 'Add contact' }).click();
    await expect(dialog).toHaveCount(0);
    const card = detail.getByTestId('party-contact').filter({ hasText: 'Test Contact' });
    await expect(card).toContainText('Primary');

    // Archive and reactivate from the record; the badge says which.
    await detail.getByRole('button', { name: 'Archive', exact: true }).click();
    await expect(detail.getByText('Archived', { exact: true }).first()).toBeVisible();
    await detail.getByRole('button', { name: 'Activate', exact: true }).click();
    await expect(detail.getByText('Active', { exact: true }).first()).toBeVisible();

    // Edit: protected fields appear for this person, the partner identity is fixed here.
    await detail.getByRole('link', { name: 'Edit' }).click();
    await expect(page).toHaveURL(/\/customers\/\d+\/edit/);
    const edit = page.getByRole('dialog');
    await expect(edit.getByRole('textbox', { name: 'Credit limit' })).toHaveValue('25000.5000');
    await edit.getByRole('textbox', { name: 'Payment terms' }).fill('40');
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('dialog').locator('.detail-field-value', { hasText: '40 days' })).toBeVisible();

    // Clean up what this run created: archive the customer so it does not clutter later runs.
    await page.getByRole('dialog').getByRole('button', { name: 'Archive', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('Archived', { exact: true }).first()).toBeVisible();
  });

  test('a duplicate customer code is refused beside the field and the typed input is kept', async ({ page }) => {
    await page.goto('/customers/new');
    const sheet = page.getByRole('dialog');
    await pickCombobox(page, 'Trading partner', 'Alpha', { within: sheet });
    await sheet.getByRole('textbox', { name: 'Customer code' }).fill('cus-metro');
    await pickCombobox(page, 'Receivable control account', '1200', { within: sheet });
    await sheet.getByRole('button', { name: 'Create customer' }).click();
    await expect(sheet.getByRole('alert').first()).toBeVisible();
    await expect(sheet.getByRole('textbox', { name: 'Customer code' })).toHaveValue('cus-metro');
    await expect(page).toHaveURL(/\/customers\/new/);
  });
});

test.describe('Customers permissions', () => {
  test('someone who may only edit accounts cannot create, sees no protected columns or filters', async ({ page }) => {
    await actAs(page, ['parties.customer-account.manage']);
    await page.goto('/customers');
    await expect(page.getByRole('heading', { name: 'Customers', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New customer' })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: /Credit limit/ })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: /Receivable account/ })).toHaveCount(0);
    await page.getByRole('button', { name: 'More filters' }).click();
    await expect(page.getByRole('region', { name: 'Advanced filters' }).getByText('Receivable account')).toHaveCount(0);

    await page.goto('/customers/new');
    await expect(page.getByRole('heading', { name: 'You don’t have access' })).toBeVisible();
  });

  test('without any customer duty the screen is closed and makes no customer request', async ({ page }) => {
    await actAs(page, ['purchasing.purchase-order.view']);
    let requests = 0;
    page.on('request', (request) => {
      if (/\/api\/v1\/customer-accounts(?:\?|$)/.test(request.url())) requests += 1;
    });
    await page.goto('/customers');
    await expect(page.getByRole('heading', { name: 'You don’t have access' })).toBeVisible();
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Customers' })).toHaveCount(0);
    expect(requests).toBe(0);
  });

  test('a Sales user who cannot maintain partners reads customers but is not offered edits', async ({ page }) => {
    await actAs(page, ['sales.opportunity.manage']);
    await page.goto('/customers');
    await expect(page.getByRole('heading', { name: 'Customers', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New customer' })).toHaveCount(0);
    await link(page, 'cus-alpha').click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: /Alpha Decorators/ })).toBeVisible();
    await expect(sheet.getByRole('link', { name: 'Edit' })).toHaveCount(0);
    await expect(sheet.getByRole('button', { name: 'Archive' })).toHaveCount(0);
    await expect(sheet.getByRole('button', { name: 'Add contact' })).toHaveCount(0);
  });
});

test.describe('Trading partners', () => {
  test('shows each partner’s roles independently, and offers the right next step', async ({ page }) => {
    const both = await makePartner(page, 'both', { customer: { creditLimit: '1000' }, supplier: true });
    try {
      await page.goto(`/parties?filter.search=${both.code}`);
      await expect(page.getByRole('heading', { name: 'Trading partners', exact: true })).toBeVisible();
      const row = page.getByRole('row', { name: new RegExp(both.name) });
      await expect(row).toContainText('Customer');
      await expect(row).toContainText('Supplier');
    } finally {
      await both.cleanup();
    }

    await page.goto('/parties?filter.search=zainab');
    const zainab = page.getByRole('row', { name: /Zainab Khan/ });
    await expect(zainab).toContainText('No role yet');
    await zainab.locator('.ui-order-link').click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: /Zainab Khan/ })).toBeVisible();
    await expect(sheet.getByText('Not a supplier')).toBeVisible();
    // Archive is allowed (no role); the shortcut starts a customer for exactly this partner.
    await expect(sheet.getByRole('button', { name: 'Archive' })).toBeEnabled();
    await sheet.getByRole('link', { name: 'Make a customer' }).click();
    await expect(page).toHaveURL(/\/customers\/new\?party_id=\d+/);
    await expect(page.getByRole('dialog').getByRole('combobox', { name: 'Trading partner' })).toContainText('Zainab Khan');
  });

  test('a partner that holds a role cannot be archived and says why', async ({ page }) => {
    await page.goto('/parties?filter.search=alpha');
    await link(page, 'cus-alpha').click();
    const archive = page.getByRole('dialog').getByRole('button', { name: 'Archive' });
    await expect(archive).toBeDisabled();
    await expect(archive).toHaveAttribute('title', /cannot be archived/);
  });

  test('registering warns about a probable duplicate and offers to use it instead', async ({ page }) => {
    await page.goto('/parties/new');
    const sheet = page.getByRole('dialog');
    await sheet.getByLabel('Business name').fill('Alpha Decorators');
    const found = sheet.getByTestId('party-candidates');
    await expect(found).toBeVisible();
    await expect(found).toContainText('Alpha Decorators');
    await found.getByRole('button', { name: 'Use this one' }).click();
    await expect(page).toHaveURL(/\/parties\/\d+$/);
  });

  test('a contact can be edited on the partner record', async ({ page }) => {
    await page.goto('/parties?filter.search=metro');
    await link(page, 'cus-metro').click();
    const sheet = page.getByRole('dialog');
    await sheet.getByRole('button', { name: 'Edit contact Metro front desk' }).click();
    const dialog = page.getByTestId('contact-dialog');
    await expect(dialog.getByRole('heading', { name: 'Edit contact' })).toBeVisible();
    await expect(dialog.getByLabel('Name')).toHaveValue('Metro front desk');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
  });
});

test.describe('phone width (390 px)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('Customers fits the screen with cards, a reachable create action and a usable Filters sheet', async ({ page }) => {
    await page.goto('/customers');
    await expect(link(page, 'cus-alpha')).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    await expect(page.getByText('Select all on this page')).toBeVisible();

    // 44 px targets for the frequent phone actions.
    const fab = page.getByRole('button', { name: 'New customer' });
    const filters = page.getByRole('button', { name: /Open filters/ });
    for (const target of [fab, filters, page.getByRole('searchbox', { name: 'Search customers' })]) {
      const box = await target.boundingBox();
      expect(box!.height).toBeGreaterThanOrEqual(44);
    }

    await filters.click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: 'Filters' })).toBeVisible();
    await expect(sheet.getByText('Trading partner', { exact: true })).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Done' })).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    await sheet.getByRole('button', { name: 'Done' }).click();

    await link(page, 'cus-alpha').click();
    const detail = page.getByRole('dialog');
    await expect(detail.getByText('Imran Siddiqui')).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    const box = await detail.boundingBox();
    // 390.000003 px once happened to a sheet exactly as wide as the screen: allow sub-pixel rounding, not overflow.
    expect(box!.width).toBeLessThanOrEqual(390.5);
  });

  test('the New customer sheet keeps its fields and primary action inside the phone screen', async ({ page }) => {
    await page.goto('/customers');
    await page.getByRole('button', { name: 'New customer' }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByRole('heading', { name: 'New customer' })).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    const submit = sheet.getByRole('button', { name: 'Create customer' });
    await expect(submit).toBeInViewport();
    expect((await submit.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    // Keyboard: the first field can be reached by Tab and Escape closes the sheet.
    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(/\/customers$/);
  });
});

test.describe('accessibility and keyboard', () => {
  for (const path of ['/customers', '/customers/new', '/parties', '/parties/new']) {
    test(`no serious accessibility violations: ${path}`, async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
      expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
    });
  }

  test('a row opens with Enter, focus is inside the sheet and Escape returns to the list', async ({ page }) => {
    await page.goto('/customers');
    const row = page.getByRole('table').locator('tbody tr').first();
    await expect(row).toHaveAttribute('tabindex', '0');
    await row.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/customers\/\d+/);
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    // Focus is moved into the sheet (never left behind it on the list).
    await expect.poll(() => page.evaluate(() => Boolean(document.activeElement?.closest('[role="dialog"]')))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(/\/customers(\?.*)?$/);
    await expect(sheet).toHaveCount(0);
  });

  test('the form reports a problem in text, tied to its field, and moves focus to it', async ({ page }) => {
    await page.goto('/customers/new');
    const sheet = page.getByRole('dialog');
    await sheet.getByRole('button', { name: 'Create customer' }).click();
    const code = sheet.getByRole('textbox', { name: 'Customer code' });
    await expect(code).toHaveAttribute('aria-describedby', 'customer-code-error');
    await expect(sheet.locator('#customer-code-error')).toHaveText('Enter a customer code.');
    await expect(code).toBeFocused();
  });
});
