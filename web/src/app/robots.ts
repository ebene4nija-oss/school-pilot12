import type { MetadataRoute } from 'next';
import { getPublicSchoolProfile, requestOrigin } from '@/lib/school-profile';

/**
 * Per school, because every subdomain is its own site to a crawler.
 *
 * A school that has published its profile lets crawlers see its home page and
 * the assets that render it, and nothing else — every other path is the
 * signed-in portal. One that has not, and any host that names no school, asks
 * crawlers to stay out entirely.
 */
export default async function robots(): Promise<MetadataRoute.Robots> {
  const profile = await getPublicSchoolProfile();

  if (!profile?.published) {
    return { rules: { userAgent: '*', disallow: '/' } };
  }

  const origin = (await requestOrigin()).origin;

  return {
    rules: {
      userAgent: '*',
      // `/$` is the home page alone; the longer rule wins over `Disallow: /`.
      allow: ['/$', '/_next/static/', '/_next/image', '/brand/'],
      disallow: '/',
    },
    sitemap: `${origin}/sitemap.xml`,
  };
}
