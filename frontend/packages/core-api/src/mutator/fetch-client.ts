// Sanctum: cookie session for the SPA (credentials: 'include' + XSRF header from the
// XSRF-TOKEN cookie), bearer token for mobile/Capacitor. Whichever app consumes this
// package configures the base URL and, on mobile, the token — neither is known here.
let baseUrl = '';
let authToken: string | null = null;

export function configureCoreApi(options: { baseUrl: string }): void {
  baseUrl = options.baseUrl;
}

export function setAuthToken(token: string | null): void {
  authToken = token;
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match?.[1] ? decodeURIComponent(match[1]) : null;
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
