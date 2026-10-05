"use client";

import Link from "next/link";
import { hasWhatsApp, site } from "@/lib/site";
import { newRefCode, sendTouch, track } from "@/lib/attribution";

type Where = "nav" | "hero" | "band" | "sticky" | "footer" | "page";

function WhatsAppGlyph() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
      <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1c-.2.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.4.1-.2 0-.3 0-.5l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.1.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .2-1.2c-.1-.2-.3-.3-.5-.4Z" />
    </svg>
  );
}

/**
 * The primary CTA: Chat on WhatsApp (Landing PRD §11.1), with a ref code that
 * lets sales join the chat to this visit. Without a configured number it
 * falls back to Book a demo, so the site never shows a dead button.
 */
export function PrimaryCta({ where, className = "" }: { where: Where; className?: string }) {
  if (!hasWhatsApp) {
    return (
      <Link
        href="/demo/"
        className={`btn btn-primary ${className}`}
        onClick={() => track("cta_click", { cta: "demo", location: where })}
      >
        Book a demo
      </Link>
    );
  }

  const base = `https://wa.me/${site.whatsappNumber}`;

  return (
    <a
      href={base}
      target="_blank"
      rel="noopener noreferrer"
      className={`btn btn-primary ${className}`}
      onClick={(e) => {
        e.preventDefault();
        const ref = newRefCode();
        const text = `Hello SchoolPilot, I'd like to learn more for my school. (Ref: ${ref})`;
        const url = `${base}?text=${encodeURIComponent(text)}`;
        sendTouch(ref, "whatsapp");
        track("cta_click", { cta: "whatsapp", location: where });
        track("whatsapp_open", { ref_code: ref });
        const opened = window.open(url, "_blank", "noopener,noreferrer");
        if (!opened) window.location.href = url;
      }}
    >
      <WhatsAppGlyph />
      Chat on WhatsApp
    </a>
  );
}

/** The second button: Book a demo, or, when demo is already primary, pricing. */
export function SecondaryCta({ where, onDark = false, className = "" }: { where: Where; onDark?: boolean; className?: string }) {
  const style = onDark ? "btn-on-dark" : "btn-secondary";

  if (!hasWhatsApp) {
    return (
      <Link href="/pricing/" className={`btn ${style} ${className}`} onClick={() => track("cta_click", { cta: "pricing", location: where })}>
        See what it costs
      </Link>
    );
  }

  return (
    <Link href="/demo/" className={`btn ${style} ${className}`} onClick={() => track("cta_click", { cta: "demo", location: where })}>
      Book a demo
    </Link>
  );
}

/** "or try the demo school →", only once the public demo exists (Phase 1b). */
export function DemoSchoolLink({ where, className = "" }: { where: Where; className?: string }) {
  if (!site.demoSchoolUrl) return null;

  return (
    <a
      href={site.demoSchoolUrl}
      className={`font-semibold text-brand underline underline-offset-4 ${className}`}
      onClick={() => track("cta_click", { cta: "demo_school", location: where })}
    >
      or try the demo school →
    </a>
  );
}
