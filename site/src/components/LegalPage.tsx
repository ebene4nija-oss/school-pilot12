import type { ReactNode } from "react";
import { site } from "@/lib/site";

/**
 * Privacy, terms and data policy. Marked as drafts until legal review signs
 * them off (Landing PRD §24, item 7); switched off in the console then
 * (Super Admin > Website > Legal pages reviewed).
 */
export function LegalPage({ title, updated, children }: { title: string; updated: string; children: ReactNode }) {
  const reviewed = site.legalReviewed;

  return (
    <section data-hero className="bg-canvas py-12 md:py-16">
      <div className="container-site prose-site max-w-3xl text-lg">
        <h1 className="text-4xl font-bold">{title}</h1>
        <p className="text-base text-ink-muted">Last updated {updated}</p>
        {!reviewed && (
          <p className="rounded-lg border border-accent bg-accent/10 p-4 text-base">
            This is a plain-language draft awaiting legal review. It describes how SchoolPilot works today.
          </p>
        )}
        {children}
      </div>
    </section>
  );
}
