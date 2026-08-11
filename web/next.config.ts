import type { NextConfig } from "next";

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
};

export default nextConfig;
