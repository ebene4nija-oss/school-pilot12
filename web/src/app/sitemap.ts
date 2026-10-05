import type { MetadataRoute } from 'next';
import { getPublicSchoolProfile, requestOrigin } from '@/lib/school-profile';

/** A published school's one public page; empty everywhere else. */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const profile = await getPublicSchoolProfile();

  if (!profile?.published) return [];

  return [{ url: `${(await requestOrigin()).origin}/`, changeFrequency: 'monthly', priority: 1 }];
}
