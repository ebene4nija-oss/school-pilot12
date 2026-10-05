"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { recordPageView } from "@/lib/attribution";

/** Records each page view for lead attribution. Renders nothing. */
export function PageView() {
  const pathname = usePathname();

  useEffect(() => {
    if (pathname) recordPageView(pathname.replace(/\/$/, "") || "/");
  }, [pathname]);

  return null;
}
