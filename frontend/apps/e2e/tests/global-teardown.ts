import { chromium, type FullConfig } from '@playwright/test';

/**
 * Data hygiene: the specs build their own items (codes starting `e2e-`), and each run used to leave them in the shared dev database, so screens and
 * fixtures that look at "the first page" drifted as the leftovers piled up. After the run, archive only the items *this run* created (by creation time,
 * never by wiping): history that already references them stays intact, they just stop crowding pickers and lists.
 */
export default async function globalTeardown(config: FullConfig) {
  const started = Number(process.env.E2E_RUN_STARTED ?? 0);
  if (!started) return;
  const baseURL = config.projects[0]?.use.baseURL ?? process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5176';
  const api = process.env.E2E_API_URL ?? 'http://127.0.0.1:8090/api/v1';
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ storageState: '.auth/user.json', baseURL });
    const page = await context.newPage();
    await page.goto('/');
    const headers = async () => {
      const cookies = await context.cookies(api);
      const xsrf = decodeURIComponent(cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '');

      return { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Origin: baseURL, Referer: `${baseURL}/` };
    };
    let archived = 0;
    for (const prefix of ['e2e-', 'aaa-filler-']) {
      for (let guard = 0; guard < 20; guard++) {
        const response = await page.request.get(`${api}/items?filter[search]=${prefix}&sort=-created_at&per_page=100`, { headers: await headers() });
        if (!response.ok()) break;
        const rows = ((await response.json()).data as Array<{ id: number; code: string; created_at?: string }>).filter(
          (item) => item.code.startsWith(prefix) && (!item.created_at || Date.parse(item.created_at) >= started),
        );
        if (rows.length === 0) break;
        for (const item of rows) {
          const removed = await page.request.delete(`${api}/items/${item.id}`, { headers: await headers() });
          if (removed.ok()) archived++;
        }
      }
    }
    console.log(`e2e hygiene: archived ${archived} item(s) created by this run`);
  } finally {
    await browser.close();
  }
}
