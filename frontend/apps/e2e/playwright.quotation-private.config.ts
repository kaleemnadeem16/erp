import { defineConfig } from "@playwright/test";

// Separate from the shared-stack project's auth and teardown.
export default defineConfig({
  testDir: "./private",
  testMatch: /.*\.private\.spec\.ts/,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  reporter: "list",
  use: { baseURL: "http://localhost:5178", serviceWorkers: "block" },
});
