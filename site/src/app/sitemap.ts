import type { MetadataRoute } from "next";
import { embedUrl, hasBlog, posts } from "@/lib/blog";
import { hasComparison } from "@/lib/comparison";
import { routes } from "@/lib/routes";
import { site } from "@/lib/site";

export const dynamic = "force-static";

// Static pages carry no lastmod: the build date would say "changed today" for
// pages that did not, and Google learns to ignore a lastmod it can't trust.
// Posts and the compare table carry the real date they changed.
export default function sitemap(): MetadataRoute.Sitemap {
  const entries: MetadataRoute.Sitemap = routes.map((r) => ({ url: `${site.url}${r.path}`, priority: r.priority }));

  if (hasComparison) entries.push({ url: `${site.url}/compare/`, priority: 0.8 });

  if (hasBlog) {
    const newest = posts.map((p) => p.updated_at).sort().at(-1);
    entries.push({ url: `${site.url}/blog/`, priority: 0.7, lastModified: newest });
    for (const p of posts) {
      // A video entry is only valid with a thumbnail: the post's cover, else the default preview.
      const thumb = p.cover ?? (site.ogImage || null);
      entries.push({
        url: `${site.url}/blog/${p.slug}/`,
        priority: 0.6,
        lastModified: p.updated_at,
        ...(p.video && thumb
          ? {
              videos: [
                {
                  title: p.title,
                  description: p.meta_description ?? p.excerpt,
                  thumbnail_loc: `${site.url}${thumb}`,
                  player_loc: embedUrl(p.video),
                  publication_date: p.published_at,
                },
              ],
            }
          : {}),
      });
    }
  }

  return entries;
}
