"use client";

import { useEffect, useState } from "react";
import { PrimaryCta, SecondaryCta } from "./Cta";

/**
 * Mobile sticky CTA bar (Landing PRD §11.1): appears once the hero has
 * scrolled away, hides while the footer is on screen or a field has focus
 * (the keyboard is up), so it never covers what the visitor is doing.
 */
export function StickyBar() {
  const [heroGone, setHeroGone] = useState(false);
  const [footerShown, setFooterShown] = useState(false);
  const [typing, setTyping] = useState(false);

  useEffect(() => {
    const hero = document.querySelector("[data-hero]");
    const footer = document.querySelector("footer");
    const observers: IntersectionObserver[] = [];

    // Every page marks its hero with data-hero; the bar waits for it to leave.
    if (hero) {
      const o = new IntersectionObserver(([e]) => setHeroGone(!e.isIntersecting));
      o.observe(hero);
      observers.push(o);
    }

    if (footer) {
      const o = new IntersectionObserver(([e]) => setFooterShown(e.isIntersecting));
      o.observe(footer);
      observers.push(o);
    }

    const isField = (el: EventTarget | null) =>
      el instanceof HTMLElement && el.matches("input, textarea, select");
    const onFocus = (e: FocusEvent) => setTyping(isField(e.target));
    const onBlur = () => setTyping(false);
    document.addEventListener("focusin", onFocus);
    document.addEventListener("focusout", onBlur);

    return () => {
      observers.forEach((o) => o.disconnect());
      document.removeEventListener("focusin", onFocus);
      document.removeEventListener("focusout", onBlur);
    };
  }, []);

  const visible = heroGone && !footerShown && !typing;

  return (
    <div
      aria-hidden={!visible}
      className={`fixed inset-x-0 bottom-0 z-30 border-t border-line-soft bg-surface/95 p-3 backdrop-blur transition-transform md:hidden ${
        visible ? "translate-y-0" : "pointer-events-none translate-y-full"
      }`}
    >
      <div className="flex gap-3">
        <PrimaryCta where="sticky" className="flex-1 px-3 text-[0.95rem]" />
        <SecondaryCta where="sticky" className="flex-1 px-3 text-[0.95rem]" />
      </div>
    </div>
  );
}
