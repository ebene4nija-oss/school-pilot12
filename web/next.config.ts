import type { NextConfig } from "next";

/**
 * Content-Security-Policy, sent as Report-Only.
 *
 * Report-Only on purpose: the browser logs every violation in the console but
 * blocks nothing, so a directive that is too tight cannot blank a page for a
 * school. Browse each role's screens in production, fix whatever is reported,
 * then rename the header to `Content-Security-Policy` to enforce it.
 *
 * `'unsafe-inline'` for scripts is what Next's inline bootstrap needs without a
 * per-request nonce; the gain here is the rest (no foreign scripts, no
 * framing, no plugins, forms only to ourselves). Production only: the dev
 * server needs `eval` for hot reload.
 */
const csp = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline'",
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' data: https://fonts.gstatic.com",
  "img-src 'self' data: blob: https:",
  "connect-src 'self'",
  "frame-src 'self' https://www.google.com",
  "frame-ancestors 'self'",
  "base-uri 'self'",
  "form-action 'self'",
  "object-src 'none'",
].join('; ');

const nextConfig: NextConfig = {
  /**
   * Emit .next/standalone — a server plus only the node_modules the traced
   * routes actually reach. The Dockerfile copies that instead of installing
   * dependencies again in the runtime stage.
   */
  output: "standalone",

  /**
   * Parents pay per megabyte. gzip on the Node server matters more here than
   * it would on a CDN-fronted deployment, because nginx proxies these
   * responses through untouched.
   */
  compress: true,

  /** Don't advertise the framework and version to every visitor. */
  poweredByHeader: false,

  async headers() {
    if (process.env.NODE_ENV !== 'production') return [];

    return [
      {
        source: '/:path*',
        headers: [{ key: 'Content-Security-Policy-Report-Only', value: csp }],
      },
    ];
  },
};

export default nextConfig;
