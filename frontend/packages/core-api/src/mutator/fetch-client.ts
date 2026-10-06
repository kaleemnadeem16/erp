// Sanctum: cookie session for the SPA (credentials: 'include' + XSRF header from the
// XSRF-TOKEN cookie), bearer token for mobile/Capacitor. Whichever app consumes this
// package configures the base URL and, on mobile, the token — neither is known here.
let baseUrl = '';
let authToken: string | null = null;
let onSessionExpired: (() => void) | undefined;

export function configureCoreApi(options: { baseUrl: string; onSessionExpired?: () => void }): void {
  baseUrl = options.baseUrl;
  onSessionExpired = options.onSessionExpired;
}

export function setAuthToken(token: string | null): void {
  authToken = token;
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * Sanctum's cookie-session flow needs a valid XSRF-TOKEN cookie before the
 * first state-changing request, but nothing else in the app issues a prior
 * GET that would set one for a user landing straight on /login (a plain
 * `POST /login` with no cookie at all always 419s, and — since a rejected
 * CSRF check throws before Laravel's VerifyCsrfToken middleware gets to set
 * the cookie on the response — retrying the same failed request never
 * recovers on its own). Laravel Sanctum ships `GET /sanctum/csrf-cookie`
 * for exactly this: https://laravel.com/docs/sanctum#spa-authenticating.
 * No-op for bearer-token (mobile) auth, which never uses XSRF at all.
 */
export async function primeCsrfCookie(): Promise<void> {
  if (authToken || !baseUrl) return;

  await fetch(`${new URL(baseUrl).origin}/sanctum/csrf-cookie`, { credentials: 'include' });
}

export async function fetchClient<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');

  if (authToken) {
    headers.set('Authorization', `Bearer ${authToken}`);
  } else {
    const xsrfToken = readCookie('XSRF-TOKEN');
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken);
  }

  const response = await fetch(`${baseUrl}${url}`, {
    ...options,
    headers,
    credentials: authToken ? 'omit' : 'include',
  });

  if (!response.ok) {
    if (response.status === 401 || response.status === 419) onSessionExpired?.();
    const body = await response.json().catch(() => null);
    throw Object.assign(new Error(`Request failed: ${response.status}`), {
      status: response.status,
      body,
    });
  }

  const data = response.status === 204 ? undefined : await response.json();

  // Orval's generated types for multi-response endpoints (e.g. `loginResponse` in
  // generated/auth/auth.ts) discriminate on `.status` and read the body off `.data` —
  // this envelope is what the fetch client contract expects the mutator to return.
  return { data, status: response.status, headers: response.headers } as T;
}
