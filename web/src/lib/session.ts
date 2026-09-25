import { cookies, headers } from 'next/headers';

/**
 * Server-side session handling.
 *
 * The Sanctum token lives in an httpOnly cookie, never in localStorage and
 * never in a JS variable the page can read. Anything injected into the admin
 * portal — a dependency, a pasted snippet, a school's own custom report-card
 * template — would otherwise be able to read a school administrator's token
 * and act as them.
 *
 * Because the token is unreadable from the browser, client components do not
 * call the Laravel API directly. They call the same-origin proxy in
 * `app/api/backend/[...path]/route.ts`, which attaches the token server-side.
 */

const TOKEN_COOKIE = 'sp_token';
const ROLE_COOKIE = 'sp_role';
const SUBDOMAIN_COOKIE = 'sp_school';

/**
 * Which tenant a platform operator is currently inspecting.
 *
 * Only ever set for a super admin, and only ever a school id. It is not an
 * authorization decision and is not treated as one: the API honours the
 * matching header for super admins alone and ignores it for every other role,
 * so writing this cookie by hand gains a school-side user nothing.
 */
const VIEW_SCHOOL_COOKIE = 'sp_view_school';

export type SessionRole =
  | 'super_admin'
  | 'school_admin'
  | 'teacher'
  | 'student'
  | 'parent';

export interface Session {
  token: string;
  role: SessionRole | null;
  subdomain: string | null;
  /** Set only when a super admin has picked one school to look at. */
  viewingSchoolId: string | null;
}

/**
 * Where a role belongs after signing in.
 *
 * A super admin is a SchoolPilot operator with no school. Sending them to `/`
 * put them on a *school's* dashboard, which for them reports zeroes for
 * everything, because the endpoint behind it scopes to their (null) school. The
 * operator console and a school's own dashboard answer different questions and
 * are not interchangeable.
 */
export function landingPathFor(role: SessionRole | null): string {
  if (role === 'super_admin') return '/super-admin';
  if (role === 'teacher') return '/results';
  return '/';
}

export async function getSession(): Promise<Session | null> {
  const store = await cookies();
  const token = store.get(TOKEN_COOKIE)?.value;

  if (!token) return null;

  const role = (store.get(ROLE_COOKIE)?.value as SessionRole) ?? null;

  return {
    token,
    role,
    subdomain: store.get(SUBDOMAIN_COOKIE)?.value ?? null,
    // Read for platform operators only. A school-side user with this cookie set
    // would otherwise have a stray school id travelling on their requests.
    viewingSchoolId: role === 'super_admin' ? (store.get(VIEW_SCHOOL_COOKIE)?.value ?? null) : null,
  };
}

/** Point a platform operator at one school, or clear the selection. */
export async function setViewedSchool(schoolId: string | null): Promise<void> {
  const store = await cookies();

  if (!schoolId) {
    store.delete(VIEW_SCHOOL_COOKIE);
    return;
  }

  store.set(VIEW_SCHOOL_COOKIE, schoolId, {
    httpOnly: false,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: 60 * 60 * 12,
  });
}

export async function createSession(
  token: string,
  role: SessionRole | null,
  subdomain: string | null,
): Promise<void> {
  const store = await cookies();
  const secure = process.env.NODE_ENV === 'production';

  store.set(TOKEN_COOKIE, token, {
    httpOnly: true,
    secure,
    sameSite: 'lax',
    path: '/',
    // Matches a school day comfortably. A bursar should not be re-authenticating
    // mid-way through entering a morning's fee payments.
    maxAge: 60 * 60 * 12,
  });

  // Role is readable by the client on purpose: it only decides which nav items
  // render. It is not an authorization decision — the API re-checks every
  // request, because a cookie the browser can write is not a permission.
  if (role) {
    store.set(ROLE_COOKIE, role, { httpOnly: false, secure, sameSite: 'lax', path: '/', maxAge: 60 * 60 * 12 });
  }

  if (subdomain) {
    store.set(SUBDOMAIN_COOKIE, subdomain, { httpOnly: false, secure, sameSite: 'lax', path: '/', maxAge: 60 * 60 * 12 });
  }
}

export async function destroySession(): Promise<void> {
  const store = await cookies();
  [TOKEN_COOKIE, ROLE_COOKIE, SUBDOMAIN_COOKIE, VIEW_SCHOOL_COOKIE].forEach((name) =>
    store.delete(name),
  );
}

/**
 * Which school this request is for.
 *
 * Derived from the host the admin portal is being served on
 * (`greenfield.schoolpilot.com` → `greenfield`), falling back to the cookie set
 * at login for local development, where everything is `localhost`.
 *
 * The old client-side code set a `Host` header on `fetch`, which browsers
 * forbid and silently drop — so tenant resolution never happened at all.
 */
export async function resolveSubdomain(): Promise<string | null> {
  const store = await cookies();
  const cookieValue = store.get(SUBDOMAIN_COOKIE)?.value;

  if (cookieValue) return cookieValue;

  const host = (await headers()).get('host') ?? '';
  const hostname = host.split(':')[0];
  const parts = hostname.split('.');

  if (parts.length < 2 || hostname === 'localhost') return null;
  if (['www', 'app'].includes(parts[0])) return null;

  return parts[0];
}

export const SESSION_COOKIES = {
  token: TOKEN_COOKIE,
  role: ROLE_COOKIE,
  subdomain: SUBDOMAIN_COOKIE,
  viewSchool: VIEW_SCHOOL_COOKIE,
};
