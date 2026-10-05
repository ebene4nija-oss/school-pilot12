import type { MetadataRoute } from "next";
import { routes } from "@/lib/routes";
import { site } from "@/lib/site";

export const dynamic = "force-static";

export default function sitemap(): MetadataRoute.Sitemap {
  return routes.map((r) => ({ url: `${site.url}${r.path}`, priority: r.priority }));
}
