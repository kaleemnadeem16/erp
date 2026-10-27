import { defineConfig, devices } from '@playwright/test';

/**
 * Targets an already-running stack (dev docker-compose locally, or the
 * backend/frontend the CI job starts natively) — this suite never builds or
 * boots the app itself. Default matches local dev exactly: the Vite dev
 * server (5176) cross-origin to Laravel behind nginx (8090), which is why
 * CORS_ALLOWED_ORIGINS/SANCTUM_STATEFUL_DOMAINS already list :5176 by
 * default (see backend/.env.example) — no extra config needed to run this
 * against `docker compose up`.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5176';

// Remembered for the teardown, which archives only what this run created.
process.env.E2E_RUN_STARTED ??= String(Date.now());

export default defineConfig({
  globalTeardown: './tests/global-teardown.ts',
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['github']] : 'list',
  // The journey spec deliberately drives many real page loads/form
  // submissions in sequence (PO create+issue, receipt create+post, QA
  // disposition, two inventory-overview checks) — the default 30s budget is
  // tuned for single-page specs and was cutting that one off mid-run.
  timeout: 60_000,
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      // Isolated specs (`*.unit.spec.ts`): no sign-in or running application/database.
      // Decimal checks are pure functions; quotation fixtures launch Chromium and mount
      // real React components against intercepted requests, or inspect static markup.
      // Build the frontend first for their production CSS: from frontend/apps/main run
      // npx vite build, then from the repo root docker/frontend/run-e2e.sh --project=unit.
      name: 'unit',
      testMatch: /.*\.unit\.spec\.ts/,
    },
    {
      name: 'chromium',
      testIgnore: /.*\.unit\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], storageState: '.auth/user.json' },
      dependencies: ['setup'],
    },
  ],
});
