'use server';

import { updateTag } from 'next/cache';
import { getSession, resolveSubdomain } from '@/lib/session';
import { schoolProfileTag } from '@/lib/school-profile';

/**
 * Drop the cached public profile after the school admin saves, so the public
 * page and sign-in screen show the change on the next load rather than up to
 * five minutes later. Only refetches; it cannot change what the API returns.
 */
export async function refreshPublicSchoolProfile(): Promise<void> {
  const session = await getSession();
  const subdomain = await resolveSubdomain();

  if (!session || !subdomain) return;

  updateTag(schoolProfileTag(subdomain));
}
