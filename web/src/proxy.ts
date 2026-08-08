import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

/**
 * Route guard.
 *
 * Next 16 renamed the `middleware` convention to `proxy` (see
 * `node_modules/next/dist/docs/01-app/02-guides/upgrading/version-16.md`), and
 * the runtime is node — the edge runtime is not supported here.
 *
 * This only decides *where to send an unauthenticated browser*. It is not the
 * authorization boundary: the cookie it reads is one the browser could forge,
 * so every API call is still authorised by Laravel against the Sanctum token.
 * Treating a redirect as security is how role-gated SPAs leak data.
 */

const PUBLIC_PATHS = ['/login', '/verify-result'];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  if (PUBLIC_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`))) {
    return NextResponse.next();
  }

  const hasSession = request.cookies.has('sp_token');

  if (!hasSession) {
    const loginUrl = new URL('/login', request.url);
    // So a deep link survives the round trip through sign-in.
    if (pathname !== '/') loginUrl.searchParams.set('next', pathname);

    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  /*
   * Everything except Next's own assets and the API proxy. The proxy route
   * returns a 401 as JSON rather than a redirect, because a fetch following a
   * 302 to an HTML login page produces a confusing parse error at the caller.
   */
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api/backend).*)'],
};
