import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { buildSync } from "esbuild";
import { readdirSync, readFileSync } from "node:fs";
import { backendUrl, type WorldUser } from "./world";

const script = buildSync({
  entryPoints: [new URL("../fixtures/real-backend-app.tsx", import.meta.url).pathname],
  bundle: true,
  platform: "browser",
  format: "iife",
  jsx: "automatic",
  write: false,
  define: { "process.env.NODE_ENV": JSON.stringify("test") },
}).outputFiles[0]!.text;
const assets = new URL("../../../main/dist/assets/", import.meta.url);
const css = readdirSync(assets)
  .filter((name) => name.endsWith(".css"))
  .map((name) => readFileSync(new URL(name, assets), "utf8"))
  .join("\n");

/**
 * Open a production screen as a real person of the test world, against the real backend. The page is served from the backend's own origin (so there is no cross-origin
 * configuration to fake) and every API request goes to the actual application with the person's real bearer token.
 */
export async function openAs(page: Page, user: WorldUser, path: string, width = 1024): Promise<void> {
  const origin = backendUrl();
  await page.setViewportSize({ width, height: 900 });
  await page.route(`${origin}/__app`, (route) =>
    route.fulfill({
      contentType: "text/html",
      body: `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Real backend journey</title><style>${css}</style></head><body><main id="root"></main></body></html>`,
    }),
  );
  await page.addInitScript((rb) => {
    (window as unknown as { __rb: unknown }).__rb = rb;
  }, { baseUrl: `${origin}/api`, token: user.token, permissions: user.duties, path, userId: user.id });
  await page.goto(`${origin}/__app`);
  await page.addScriptTag({ content: script });
}

/** Every request the page makes to the real API, so a test can state exactly what was (and was not) sent. */
export function recordApi(page: Page): { requests: () => string[] } {
  const seen: string[] = [];
  page.on("request", (r) => {
    const url = new URL(r.url());
    if (url.pathname.startsWith("/api/v1/")) seen.push(`${r.method()} ${url.pathname.replace("/api/v1", "")}`);
  });
  return { requests: () => seen };
}

/** A direct call to the real API as a person (setup and inspection that a person with that duty could do by API). */
export async function api(request: APIRequestContext, user: WorldUser, method: "GET" | "POST", path: string, data?: unknown, key?: string) {
  const response = await request.fetch(`${backendUrl()}/api/v1${path}`, {
    method,
    headers: { Authorization: `Bearer ${user.token}`, Accept: "application/json", ...(key ? { "Idempotency-Key": key } : {}) },
    ...(data === undefined ? {} : { data }),
  });
  const text = await response.text();
  return { status: response.status(), body: text ? (JSON.parse(text) as { data?: unknown; reason_code?: string }) : {} };
}

/** The Jobs the officer can see, by code (a count of exactly one is how "exactly one Job" is proved). */
export async function jobCodes(request: APIRequestContext, user: WorldUser): Promise<string[]> {
  const r = await api(request, user, "GET", "/jobs?per_page=100");
  expect(r.status).toBe(200);
  return ((r.body.data as { number: string }[]) ?? []).map((j) => j.number);
}
