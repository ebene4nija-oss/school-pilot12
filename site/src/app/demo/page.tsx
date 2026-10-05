import type { Metadata } from "next";
import { LeadForm } from "@/components/LeadForm";
import { hasWhatsApp } from "@/lib/site";
import { PrimaryCta } from "@/components/Cta";

export const metadata: Metadata = {
  title: "Book a demo",
  description: "See SchoolPilot run your results, CBT, fees and parents. Book a free demo for your school.",
  alternates: { canonical: "/demo/" },
};

const expect = [
  "A walk through your results process, from CA to report card, set up the way your school grades.",
  "Fees, CBT and the parent app, on your own phone.",
  "What it would cost your school, and what is free.",
  "How we move your records in, free, within 24 hours.",
];

export default function DemoPage() {
  return (
    <section data-hero className="bg-canvas py-12 md:py-16">
      <div className="container-site grid gap-10 lg:grid-cols-[1fr_1.2fr] [&>*]:min-w-0">
        <div>
          <p className="eyebrow">Book a demo</p>
          <h1 className="mt-3 text-4xl font-bold md:text-5xl">See SchoolPilot run your school.</h1>
          <p className="mt-4 text-lg text-ink-muted">Tell us a little about your school. We&apos;ll arrange a time that suits you.</p>
          <h2 className="mt-8 text-xl font-semibold">What you&apos;ll see</h2>
          <ul className="mt-3 space-y-3">
            {expect.map((e) => (
              <li key={e} className="flex gap-3">
                <span aria-hidden="true" className="mt-2 h-2 w-2 shrink-0 rounded-full bg-accent" />
                {e}
              </li>
            ))}
          </ul>
          {hasWhatsApp && (
            <div className="mt-8 rounded-xl bg-surface p-5">
              <p className="font-semibold text-brand">Prefer to just talk?</p>
              <div className="mt-3"><PrimaryCta where="page" /></div>
            </div>
          )}
        </div>
        <LeadForm intent="demo" submitLabel="Book my demo" />
      </div>
    </section>
  );
}
