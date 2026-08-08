import type { NextRequest } from 'next/server';
import { getSession, resolveSubdomain } from '@/lib/session';

/**
 * Same-origin proxy to the Laravel API.
 *
 * Everything the browser calls goes through here so that two things can be
 * attached server-side, where the page cannot see or tamper with them:
 *
 *  - `Authorization: Bearer <token>` from the httpOnly session cookie
 *  - `X-School-Subdomain`, the tenant
 *
 * The tenant header exists because the previous client code set `Host` on
 * `fetch`. Browsers forbid that — it is a forbidden header name, silently
 * dropped — so tenant resolution never ran for a single browser request.
 */

const API_BASE_URL =
  process.env.BACKEND_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1';

// Hop-by-hop and content-negotiation headers that must not be copied verbatim:
// forwarding the browser's own encoding/length would misdescribe the body we
// actually send.
const STRIPPED_REQUEST_HEADERS = new Set([
  'host',
  'connection',
  'content-length',
  'accept-encoding',
  'transfer-encoding',
  'upgrade',
  'cookie',
]);

const STRIPPED_RESPONSE_HEADERS = new Set([
  'content-encoding',
  'content-length',
  'transfer-encoding',
  'connection',
]);

async function handle(request: NextRequest, context: { params: Promise<{ path: string[] }> }) {
  const { path } = await context.params;
  const session = await getSession();

  if (!session) {
    return Response.json(
      { error: 'Your session has expired. Please sign in again.' },
      { status: 401 },
    );
  }

  const subdomain = await resolveSubdomain();
  const search = request.nextUrl.search;
  const target = `${API_BASE_URL}/${path.join('/')}${search}`;

  const outgoing = new Headers();
  request.headers.forEach((value, key) => {
    if (!STRIPPED_REQUEST_HEADERS.has(key.toLowerCase())) outgoing.set(key, value);
  });

  outgoing.set('Authorization', `Bearer ${session.token}`);
  outgoing.set('Accept', 'application/json');
  if (subdomain) outgoing.set('X-School-Subdomain', subdomain);

  const method = request.method;
  const body = method === 'GET' || method === 'HEAD' ? undefined : await request.arrayBuffer();

  let upstream: Response;

  try {
    upstream = await fetch(target, {
      method,
      headers: outgoing,
      body,
      redirect: 'manual',
      cache: 'no-store',
    });
  } catch {
    // A dead backend should read as a backend problem, not a mystery client
    // error — schools on unreliable links hit this often enough to name it.
    return Response.json(
      { error: 'Could not reach the school server. Check your connection and try again.' },
      { status: 502 },
    );
  }

  const responseHeaders = new Headers();
  upstream.headers.forEach((value, key) => {
    if (!STRIPPED_RESPONSE_HEADERS.has(key.toLowerCase())) responseHeaders.set(key, value);
  });

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: responseHeaders,
  });
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const PATCH = handle;
export const DELETE = handle;
