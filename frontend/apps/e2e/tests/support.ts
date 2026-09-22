import type { Locator, Page } from '@playwright/test';

/**
 * Opens a Combobox (searchable popover, e.g. Supplier) by its FormLabel text,
 * types into its search box, and picks the option matching `optionText`.
 * `optionText` doubles as the search term when it's a plain string; pass
 * `searchText` separately when `optionText` needs to be a more precise regex
 * than cmdk's own fuzzy filter would need to narrow the list down (e.g. an
 * anchored pattern disambiguating two lines that share a search term).
 * Pass `within` when the trigger lives in a dialog over a page that has its
 * own field with the same label (the PO create sheet sits over the PO list,
 * whose supplier filter is also labelled "Supplier").
 */
export async function pickCombobox(
  page: Page,
  label: string,
  optionText: string | RegExp,
  { searchText, within }: { searchText?: string; within?: Locator } = {}
) {
  await (within ?? page).getByLabel(label, { exact: true }).click();
  // The popover is portalled to <body>, outside any dialog its trigger is in,
  // and the page behind can have its own "Search…" inputs — cmdk's own
  // attribute picks out the open popover's box unambiguously.
  await page.locator('[cmdk-input]').fill(searchText ?? (typeof optionText === 'string' ? optionText : ''));
  await page.getByRole('option', { name: optionText }).first().click();
}

/** Opens a Radix Select by its FormLabel text and picks an option whose text matches. */
export async function pickSelect(page: Page, label: string, optionText: string | RegExp) {
  await page.getByLabel(label, { exact: true }).click();
  await page.getByRole('option', { name: optionText }).first().click();
}

/** Escapes regex metacharacters so a literal string (e.g. an order number) can be matched exactly. */
export function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Reads one cell's text from a DataTable row, resolving the column index from
 * the header row at runtime instead of a hardcoded position — column
 * visibility defaults (or a reorder) shouldn't break this.
 */
export async function readCell(table: Locator, row: Locator, headerText: string): Promise<string> {
  const headers = table.locator('thead th');
  const count = await headers.count();
  for (let index = 0; index < count; index += 1) {
    const text = (await headers.nth(index).innerText()).trim();
    if (text === headerText) return (await row.locator('td').nth(index).innerText()).trim();
  }
  throw new Error(`Column "${headerText}" not found in table header`);
}
