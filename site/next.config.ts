import type { NextConfig } from "next";

/**
 * The marketing site is a static export (Landing PRD §14.1): every page is
 * HTML built ahead of time and served from any CDN, with no runtime tie to
 * the backend except form posts and the stats call, which the browser makes.
 *
 * It is deliberately a separate app from web/: the root of web/ is the
 * signed-in dashboard, and a visitor deciding whether to talk to us should
 * never download it.
 */
const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  poweredByHeader: false,
  images: {
    // No image server in a static export; screenshots are pre-sized WebP.
    unoptimized: true,
  },
};

export default nextConfig;
