"use client";

import { useEffect, useState } from "react";
import { hasWhatsApp, site } from "@/lib/site";
import { openWhatsApp, WhatsAppGlyph } from "./Cta";

/**
 * The floating WhatsApp button, bottom right on every page. It bounces in,
 * pulses, and opens a short "chat with us" bubble once per visit. On phones
 * it sits above the sticky CTA bar. Without a configured number it renders
 * nothing, like every other WhatsApp button.
 */
export function FloatingWhatsApp() {
  const [bubble, setBubble] = useState(false);

  useEffect(() => {
    let seen = false;
    try {
      seen = sessionStorage.getItem("sp-wa-bubble") === "1";
    } catch {}
    if (seen) return;

    const show = window.setTimeout(() => {
      setBubble(true);
      try {
        sessionStorage.setItem("sp-wa-bubble", "1");
      } catch {}
    }, 4000);
    const hide = window.setTimeout(() => setBubble(false), 12000);

    return () => {
      window.clearTimeout(show);
      window.clearTimeout(hide);
    };
  }, []);

  if (!hasWhatsApp) return null;

  const message = "Hello SchoolPilot, I'd like to learn more for my school.";

  return (
    <div className="wa-float fixed bottom-[5.5rem] right-4 z-40 flex items-end gap-3 md:bottom-6 md:right-6">
      {bubble && (
        <div className="wa-bubble relative mb-2 hidden max-w-[14rem] rounded-2xl bg-surface px-4 py-3 text-sm text-ink shadow-xl ring-1 ring-line-soft sm:block">
          <button
            type="button"
            aria-label="Dismiss"
            onClick={() => setBubble(false)}
            className="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-surface text-ink-muted shadow ring-1 ring-line-soft hover:text-ink"
          >
            ×
          </button>
          <p className="font-semibold text-brand">Questions about SchoolPilot?</p>
          <p className="mt-0.5 text-ink-muted">Chat with a real person on WhatsApp.</p>
        </div>
      )}

      <a
        href={`https://wa.me/${site.whatsappNumber}`}
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Chat with SchoolPilot on WhatsApp"
        title="Chat on WhatsApp"
        onClick={(e) => {
          e.preventDefault();
          setBubble(false);
          openWhatsApp("float", message);
        }}
        className="wa-button relative flex h-14 w-14 items-center justify-center rounded-full bg-[#25d366] text-white shadow-lg shadow-black/20 transition-transform hover:scale-110 active:scale-95"
      >
        <span aria-hidden="true" className="wa-ring absolute inset-0 rounded-full bg-[#25d366]" />
        <span className="wa-icon relative">
          <WhatsAppGlyph size={30} />
        </span>
      </a>
    </div>
  );
}
