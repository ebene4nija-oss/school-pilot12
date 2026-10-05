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

// /apply: a school's online admission form, for parents with no account.
const PUBLIC_PATHS = ['/login', '/apply', '/verify-result', '/robots.txt', '/sitemap.xml'];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  if (PUBLIC_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`))) {
    return NextResponse.next();
  }

  const hasSession = request.cookies.has('sp_token');

  /*
   * A signed-out visitor at the root sees the school's public home — its
   * name, crest, admissions and the sign-in form — rather than being bounced
   * to /login. app/page.tsx chooses between that and the dashboard itself.
   *
   * Never a rewrite here: Next proxies a rewrite whose origin differs from
   * the server's own as an external request, and behind Apache
   * (X-Forwarded-Proto: https, a standalone server bound to 127.0.0.1) the
   * origins differ, which turned every visit into a TLS call to the
   * plain-http Node port and a 500. scripts/smoke-proxy-headers.mjs guards it.
   */
  if (!hasSession && pathname === '/') {
    return NextResponse.next();
  }

  if (!hasSession) {
    const loginUrl = new URL('/login', request.url);
    // So a deep link survives the round trip through sign-in.
    if (pathname !== '/') loginUrl.searchParams.set('next', pathname);

    return NextResponse.redirect(loginUrl);
  }

  /*
   * Keep a platform operator off a school's own dashboard.
   *
   * `/` scopes to the acting user's school, and a super admin has none — the
   * endpoint behind it would report them an entire school's worth of zeroes,
   * which reads as "this school has no students" rather than "you are on the
   * wrong screen". Login already routes them to the console; this catches the
   * bookmark, the back button and the typed URL.
   *
   * An operator who has stepped into a school through the picker does have an
   * acting school, so they are left alone. As above, this is routing and not
   * authorization: the cookies are forgeable and the API re-checks every call.
   */
  if (pathname === '/') {
    const role = request.cookies.get('sp_role')?.value;
    const viewing = request.cookies.get('sp_view_school')?.value;

    if (role === 'super_admin' && !viewing) {
      return NextResponse.redirect(new URL('/super-admin', request.url));
    }

    // A nurse has the clinic log and nothing else; the dashboard would 403.
    if (role === 'nurse') {
      return NextResponse.redirect(new URL('/health', request.url));
    }

    // Likewise a bursar: the fee book, not the academic dashboard.
    if (role === 'bursar') {
      return NextResponse.redirect(new URL('/finance', request.url));
    }

    // And a parent: their children, not the school's dashboard (which refuses them).
    if (role === 'parent') {
      return NextResponse.redirect(new URL('/parent', request.url));
    }

    // And a student: their own portal, not the school's dashboard.
    if (role === 'student') {
      return NextResponse.redirect(new URL('/student', request.url));
    }
  }

  return NextResponse.next();
}

export const config = {
  /*
   * Everything except Next's own assets, the API proxy, and static files.
   *
   * The proxy route returns a 401 as JSON rather than a redirect, because a
   * fetch following a 302 to an HTML login page produces a confusing parse
   * error at the caller.
   *
   * The trailing extension group is what keeps `/brand/logo.png` and the
   * generated `/icon.png` out of the guard. Without it every static file was
   * redirected to /login, which is not merely a wasted hop: `next/image`
   * fetches the source server-side and without cookies, so it received an HTML
   * login page where a PNG should have been and answered 400 — "The requested
   * resource isn't a valid image". The logo never rendered on any page, for
   * anyone, signed in or not.
   *
   * These files are public by nature — a logo and a favicon are served to
   * anyone who loads the sign-in page anyway — so exempting them gives nothing
   * away.
   */
  matcher: [
    '/((?!_next/static|_next/image|favicon.ico|api/backend|.*\\.(?:png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|webmanifest)$).*)',
  ],
};
