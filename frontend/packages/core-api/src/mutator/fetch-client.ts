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

  if (response.status === 204) return undefined as T;

  return response.json() as Promise<T>;
}
