import type { Metadata } from "next";
import { LeadForm } from "@/components/LeadForm";
import { PrimaryCta } from "@/components/Cta";
import { hasWhatsApp, site } from "@/lib/site";

export const metadata: Metadata = {
  title: "Contact",
  description: "Talk to the SchoolPilot team.",
  alternates: { canonical: "/contact/" },
};

export default function ContactPage() {
  return (
    <section data-hero className="bg-canvas py-12 md:py-16">
      <div className="container-site grid gap-10 lg:grid-cols-[1fr_1.2fr] [&>*]:min-w-0">
        <div>
          <p className="eyebrow">Contact</p>
          <h1 className="mt-3 text-4xl font-bold md:text-5xl">Talk to a person.</h1>
          <p className="mt-4 text-lg text-ink-muted">Questions about SchoolPilot, pricing or moving your school over. We reply {site.responseHours}.</p>
          <ul className="mt-8 space-y-3 text-lg">
            {hasWhatsApp && <li><PrimaryCta where="page" /></li>}
            {site.contactEmail && (
              <li>Email: <a className="font-semibold text-brand underline underline-offset-4" href={`mailto:${site.contactEmail}`}>{site.contactEmail}</a></li>
            )}
            {site.contactAddress && <li>Office: {site.contactAddress}</li>}
          </ul>
          <p className="mt-8 text-ink-muted">
            Already use SchoolPilot? Contact your school&apos;s administrator, or <a href="/schools/" className="font-semibold text-brand underline underline-offset-4">find your school&apos;s login</a>.
          </p>
        </div>
        <LeadForm intent="contact" submitLabel="Send message" />
      </div>
    </section>
  );
}
