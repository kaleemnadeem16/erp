import { expect, test, type Page } from '@playwright/test';

import { api, findByCode } from './package-stock-support';

/**
 * Backend features that existed with no screen, now reachable: editing and removing a supplier's address, and assigning a warehouse's location roles
 * (STRUCTURED mode only, ADR 0041). Records these tests make carry a unique stamp and are removed in a `finally`; the role test puts the demo assignment back.
 */
const stamp = Date.now().toString().slice(-7);

/** An address card on the supplier record (the hidden print layout repeats the same text, so the card is found by its own box). */
const addressCard = (sheet: ReturnType<Page['getByRole']>, name: string) => sheet.locator('div.rounded-lg.border', { hasText: name });

async function actAs(page: Page, permissions: string[]) {
  await page.route(/\/api\/v1\/user(?:\?.*)?$/, async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, permissions } }) });
  });
}

test.describe('Supplier addresses', () => {
  test('an address can be edited and removed from the supplier record, with the consequence stated', async ({ page }) => {
    await page.goto('/suppliers');
    const supplier = await findByCode<{ id: number; code: string }>(page, '/supplier-accounts', 'sup-punjab');
    expect(supplier).toBeTruthy();
    const name = `E2E site ${stamp}`;
    await api(page, 'POST', `/supplier-accounts/${supplier!.id}/sites`, { name, role: 'ORDER_FROM', city: 'Lahore', country_code: 'PK' });
    try {
      await page.goto(`/suppliers/${supplier!.id}`);
      const sheet = page.getByRole('dialog');
      await expect(addressCard(sheet, name)).toContainText('Lahore');

      await sheet.getByRole('button', { name: `Edit address ${name}` }).click();
      const edit = page.getByRole('dialog').last();
      await expect(edit.getByRole('heading', { name: 'Edit supplier site' })).toBeVisible();
      // It opens on what is stored, not on blanks.
      await expect(edit.getByLabel('City')).toHaveValue('Lahore');
      await expect(edit.getByLabel('Country code')).toHaveValue('PK');
      await edit.getByLabel('City').fill('Karachi');
      await edit.getByRole('button', { name: 'Save site' }).click();
      await expect(addressCard(sheet, name)).toContainText('Karachi');

      await sheet.getByRole('button', { name: `Remove address ${name}` }).click();
      const confirm = page.getByTestId('remove-site-dialog');
      await expect(confirm).toContainText('The address itself is kept');
      await confirm.getByRole('button', { name: 'Remove address' }).click();
      await expect(addressCard(sheet, name)).toHaveCount(0);
    } finally {
      const sites = (await api(page, 'GET', `/supplier-accounts/${supplier!.id}/sites?per_page=100`)).data as Array<{ id: number; party_site?: { name?: string } }>;
      for (const site of sites.filter((candidate) => candidate.party_site?.name === name)) await api(page, 'DELETE', `/supplier-sites/${site.id}`);
    }
  });

  test('a person without the site duty can read addresses but is offered no edit or remove', async ({ page }) => {
    await page.goto('/suppliers');
    const supplier = await findByCode<{ id: number }>(page, '/supplier-accounts', 'sup-punjab');
    const name = `E2E ro site ${stamp}`;
    await api(page, 'POST', `/supplier-accounts/${supplier!.id}/sites`, { name, role: 'SHIP_FROM', city: 'Multan' });
    try {
      await actAs(page, ['purchasing.purchase-order.view']);
      await page.goto(`/suppliers/${supplier!.id}`);
      const sheet = page.getByRole('dialog');
      await expect(addressCard(sheet, name)).toBeVisible();
      await expect(sheet.getByRole('button', { name: /Edit address/ })).toHaveCount(0);
      await expect(sheet.getByRole('button', { name: /Remove address/ })).toHaveCount(0);
      await expect(sheet.getByRole('button', { name: 'Add address' })).toHaveCount(0);
    } finally {
      const sites = (await api(page, 'GET', `/supplier-accounts/${supplier!.id}/sites?per_page=100`)).data as Array<{ id: number; party_site?: { name?: string } }>;
      for (const site of sites.filter((candidate) => candidate.party_site?.name === name)) await api(page, 'DELETE', `/supplier-sites/${site.id}`);
    }
  });

  test('on a phone the address actions are 44 px targets and the sheet does not overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/suppliers');
    const supplier = await findByCode<{ id: number }>(page, '/supplier-accounts', 'sup-punjab');
    const name = `E2E phone site ${stamp}`;
    await api(page, 'POST', `/supplier-accounts/${supplier!.id}/sites`, { name, role: 'ORDER_FROM', city: 'Quetta' });
    try {
      await page.goto(`/suppliers/${supplier!.id}`);
      const edit = page.getByRole('dialog').getByRole('button', { name: `Edit address ${name}` });
      await expect(edit).toBeVisible();
      expect((await edit.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    } finally {
      const sites = (await api(page, 'GET', `/supplier-accounts/${supplier!.id}/sites?per_page=100`)).data as Array<{ id: number; party_site?: { name?: string } }>;
      for (const site of sites.filter((candidate) => candidate.party_site?.name === name)) await api(page, 'DELETE', `/supplier-sites/${site.id}`);
    }
  });
});

test.describe('Warehouse location roles', () => {
  test('a structured warehouse shows each role and lets a manager change and clear one; the demo assignment is restored', async ({ page }) => {
    await page.goto('/warehouses');
    const warehouse = await findByCode<{ id: number }>(page, '/warehouses', 'demo-main');
    const roles = (await api(page, 'GET', `/warehouse-location-roles?filter[warehouse_id]=${warehouse!.id}&per_page=20`)).data as Array<{ id: number; role: string; location_id: number }>;
    const original = roles.find((assignment) => assignment.role === 'TRANSIT');
    const locations = (await api(page, 'GET', `/locations?filter[warehouse_id]=${warehouse!.id}&per_page=100`)).data as Array<{ id: number; code: string }>;
    expect(original, 'the structured demo assigns the TRANSIT role').toBeTruthy();
    const other = locations.find((location) => location.id !== original!.location_id)!;
    try {
      await page.goto(`/warehouses/${warehouse!.id}`);
      const panel = page.getByTestId('location-roles');
      await expect(panel).toBeVisible();
      for (const label of ['Receiving', 'Quarantine', 'Dispatch', 'Returns', 'In transit', 'Production']) await expect(panel).toContainText(label);
      const row = panel.getByTestId('location-role-TRANSIT');
      await expect(row).not.toContainText('Not assigned');

      // Change: pick another of this warehouse's own locations.
      await row.getByRole('combobox', { name: 'In transit location' }).click();
      const input = page.locator('[cmdk-input]');
      await input.fill(other.code);
      await page.getByRole('option', { name: new RegExp(other.code) }).first().press('Enter');
      await expect(row).toContainText(other.code);

      // Clear: the role is unassigned and the control offers an assignment again.
      await row.getByRole('button', { name: 'Clear In transit location' }).click();
      await expect(row).toContainText('Not assigned');
      await expect(row.getByRole('combobox', { name: 'In transit location' })).toContainText('Assign a location…');
    } finally {
      const now = (await api(page, 'GET', `/warehouse-location-roles?filter[warehouse_id]=${warehouse!.id}&filter[role]=TRANSIT&per_page=5`)).data as Array<{ id: number; location_id: number }>;
      if (now.length === 0) await api(page, 'POST', '/warehouse-location-roles', { warehouse_id: warehouse!.id, location_id: original!.location_id, role: 'TRANSIT' });
      else if (now[0]!.location_id !== original!.location_id) {
        await api(page, 'DELETE', `/warehouse-location-roles/${now[0]!.id}`);
        await api(page, 'POST', '/warehouse-location-roles', { warehouse_id: warehouse!.id, location_id: original!.location_id, role: 'TRANSIT' });
      }
    }
  });

  test('a person without the location duty sees the roles but no controls; a simple warehouse mode shows nothing role-related', async ({ page }) => {
    await page.goto('/warehouses');
    const warehouse = await findByCode<{ id: number }>(page, '/warehouses', 'demo-main');
    await actAs(page, ['inventory.warehouse.manage']);
    await page.goto(`/warehouses/${warehouse!.id}`);
    const panel = page.getByTestId('location-roles');
    await expect(panel).toBeVisible();
    await expect(panel.getByRole('combobox')).toHaveCount(0);
    await expect(panel.getByRole('button', { name: /Clear/ })).toHaveCount(0);

    // SIMPLE mode: the company setting says so, and the panel is absent (ADR 0041: nothing role-related is shown).
    await page.route(/\/api\/v1\/inventory-settings(?:\?.*)?$/, async (route) => {
      const response = await route.fetch();
      const body = await response.json();
      await route.fulfill({ response, body: JSON.stringify({ ...body, data: { ...body.data, inventory_location_mode: 'SIMPLE' } }) });
    });
    await page.goto(`/warehouses/${warehouse!.id}`);
    await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible();
    await expect(page.getByTestId('location-roles')).toHaveCount(0);
  });
});
