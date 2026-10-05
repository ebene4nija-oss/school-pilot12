import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import SchoolDashboard from './SchoolDashboard';
import SchoolHomePage, { generateMetadata as schoolHomeMetadata } from './school-home/page';
import { SESSION_COOKIES } from '@/lib/session';

/**
 * `/` on a school's subdomain.
 *
 * Signed out: the school's public home (its name, crest, admissions and the
 * sign-in form). Signed in: the school dashboard.
 *
 * This used to be a rewrite in proxy.ts to /school-home. A proxy rewrite needs
 * an absolute URL, and Next treats one whose origin differs from the server's
 * own as external and proxies it over the network. Behind Apache the origins
 * did differ — X-Forwarded-Proto: https, and the standalone server's own
 * HOSTNAME — so the rewrite became a TLS request to the plain-http Node port
 * and every signed-out visit to a school's address returned 500. Choosing the
 * page here involves no URL at all, so no header can redirect it anywhere.
 */

async function signedIn(): Promise<boolean> {
  return (await cookies()).has(SESSION_COOKIES.token);
}

export async function generateMetadata(): Promise<Metadata> {
  return (await signedIn()) ? {} : schoolHomeMetadata();
}

export default async function Home() {
  if (await signedIn()) return <SchoolDashboard />;

  return <SchoolHomePage />;
}
