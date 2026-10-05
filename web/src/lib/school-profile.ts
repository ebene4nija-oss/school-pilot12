import { cache } from 'react';
import { headers } from 'next/headers';
import { resolveSubdomain } from '@/lib/session';

/**
 * The school's public profile: what its subdomain shows a signed-out visitor.
 *
 * Read server-side only, from `/public/school-profile`, which answers from an
 * allowlist of institutional fields. Identity (name, crest, motto, hero,
 * colour) is always there so the sign-in page can say which school it is;
 * about, contact and sections arrive only once the school admin publishes —
 * and only then may search engines index the page.
 */

const API_BASE_URL =
  process.env.BACKEND_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1';

export interface PublicSchool {
  name: string;
  short_name: string | null;
  subdomain: string;
  motto: string | null;
  logo_url: string | null;
  hero_image_url: string | null;
  brand_color: string | null;
  // Present only when published.
  about?: string | null;
  address?: string | null;
  state?: string | null;
  lga?: string | null;
  phone?: string | null;
  email?: string | null;
  website?: string | null;
  social_links?: Partial<Record<SocialNetwork, string>>;
  school_type?: string | null;
  sections?: string[];
}

export type SocialNetwork = 'facebook' | 'instagram' | 'x' | 'youtube' | 'linkedin' | 'tiktok';

/**
 * How this school takes admission enquiries: its own choice of the online
 * form, WhatsApp, email and phone (School Settings → Admissions). null when
 * the school has never set its admissions status.
 */
export type PublicAdmissions =
  | {
      status: 'open';
      session: string | null;
      instructions: string | null;
      methods: {
        online: boolean;
        whatsapp: { number: string; message: string | null } | null;
        email: { address: string; subject: string | null } | null;
        phone: string | null;
      };
    }
  | { status: 'closed'; session: string | null; closed_message: string | null };

export interface PublicSchoolProfile {
  published: boolean;
  admissions_open: boolean;
  admissions?: PublicAdmissions | null;
  school: PublicSchool;
}

/**
 * The profile for the school this request is on, or null when the host names
 * no school (local development, the apex domain) or the API is unreachable.
 *
 * Cached for five minutes per school: these pages are the most-hit and
 * least-changing thing on the subdomain. The subdomain is in the URL as well
 * as the header so the data cache can never hand one school's page to another.
 */
export const getPublicSchoolProfile = cache(async (): Promise<PublicSchoolProfile | null> => {
  const subdomain = await resolveSubdomain();

  if (!subdomain) return null;

  try {
    const res = await fetch(`${API_BASE_URL}/public/school-profile?school=${encodeURIComponent(subdomain)}`, {
      headers: { Accept: 'application/json', 'X-School-Subdomain': subdomain },
      next: { revalidate: 300, tags: [schoolProfileTag(subdomain)] },
    });

    if (!res.ok) return null;

    return (await res.json()) as PublicSchoolProfile;
  } catch {
    return null;
  }
});

/** The data-cache tag for one school's profile, cleared when its admin saves. */
export function schoolProfileTag(subdomain: string): string {
  return `school-profile:${subdomain}`;
}

/** `https://greenfield.schoolpilot.org.ng`, from the request itself. */
export async function requestOrigin(): Promise<URL> {
  const h = await headers();
  const host = h.get('x-forwarded-host') ?? h.get('host') ?? 'localhost:3000';
  const proto = h.get('x-forwarded-proto') ?? (host.startsWith('localhost') ? 'http' : 'https');

  return new URL(`${proto}://${host}`);
}

/** The school's colour, or SchoolPilot's when it has not chosen one. */
export function accentOf(school: PublicSchool | undefined | null): string {
  return school?.brand_color ?? '#002447';
}
