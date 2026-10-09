"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";

/**
 * Animates [data-reveal] elements in as they scroll into view, by adding
 * .is-in (the motion lives in globals.css). Content is only hidden once the
 * <html> has the .js class, so a visitor without JavaScript sees everything.
 * Optional `style={{ "--d": "120ms" }}` staggers siblings.
 */
export function ScrollReveal() {
  const pathname = usePathname();

  useEffect(() => {
    const items = document.querySelectorAll<HTMLElement>("[data-reveal]:not(.is-in)");

    if (!("IntersectionObserver" in window)) {
      items.forEach((el) => el.classList.add("is-in"));
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          entry.target.classList.add("is-in");
          observer.unobserve(entry.target);
        }
      },
      { rootMargin: "0px 0px -8% 0px", threshold: 0.12 },
    );

    items.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [pathname]);

  return null;
}
