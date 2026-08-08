/**
 * Browser-side API client.
 *
 * Calls the same-origin proxy at `/api/backend/*`, which attaches the Sanctum
 * token and the tenant header server-side. Two things this deliberately does
 * not do:
 *
 *  - It does not set a `Host` header. The previous version did, and browsers
 *    forbid it: `fetch` drops it silently, so tenant resolution never ran.
 *  - It does not hold a token. The token is in an httpOnly cookie precisely so
 *    that nothing running on the page can read it.
 */

export const API_BASE_URL = '/api/backend';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly payload?: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The session expired or was never established. */
  get isUnauthenticated(): boolean {
    return this.status === 401;
  }
}

export async function fetchApi<T = unknown>(
  endpoint: string,
  options: RequestInit = {},
): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...options.headers,
    },
    // The session cookie must ride along; it is same-origin, so this is enough.
    credentials: 'same-origin',
  });

  const payload = await response.json().catch(() => undefined);

  if (!response.ok) {
    if (response.status === 401 && typeof window !== 'undefined') {
      // Bounce to sign-in rather than leaving skeleton loaders spinning
      // forever, which is what the old client did with every 401.
      window.location.href = `/login?next=${encodeURIComponent(window.location.pathname)}`;
    }

    const message =
      (payload as { message?: string; error?: string })?.message ??
      (payload as { error?: string })?.error ??
      `Request failed (${response.status})`;

    throw new ApiError(response.status, message, payload);
  }

  return payload as T;
}
