import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand, PageHero, Section } from "@/components/Blocks";
import { PrimaryCta } from "@/components/Cta";
import { hasWhatsApp } from "@/lib/site";

export const metadata: Metadata = {
  title: "Free School Data Migration in 24 Hours",
  description: "We move your students, guardians, staff, past results and fee balances into SchoolPilot free, within 24 hours. Leave any time with a one-click export.",
  alternates: { canonical: "/migrate/" },
};

const what = [
  "Students and their guardians",
  "Classes, arms and subjects",
  "Staff records",
  "Past results and academic history",
  "Outstanding fee balances",
];

const from = [
  { title: "Excel or CSV", text: "Send us your spreadsheets as they are. We map the columns." },
  { title: "Paper records", text: "We send you simple templates to fill, then import them." },
  { title: "Another portal", text: "Export what you can from it, and we take it from there." },
];

export default function MigratePage() {
  return (
    <>
      <PageHero
        eyebrow="Switching to SchoolPilot"
        title="We move your school in. Free, within 24 hours."
        sub="Switching mid-session doesn't have to mean chaos. Send us what you have, and your records are in SchoolPilot the next day. Start with results, add the rest when you're ready."
      >
        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
          {hasWhatsApp ? (
            <PrimaryCta where="hero" label="Start my migration on WhatsApp" message="Hello SchoolPilot, I'd like to move my school over to SchoolPilot." />
          ) : (
            <Link href="/start/" className="btn btn-primary">Start my migration</Link>
          )}
          <Link href="/demo/" className="btn btn-secondary">Book a demo first</Link>
        </div>
      </PageHero>

      <Section id="what" title="What we move for you">
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {what.map((w) => (
            <li key={w} className="rounded-xl border border-line-soft bg-surface p-5 font-semibold text-brand">{w}</li>
          ))}
        </ul>
      </Section>

      <Section id="from" tone="surface" title="From wherever it lives today">
        <div className="grid gap-5 md:grid-cols-3">
          {from.map((f) => (
            <article key={f.title} className="rounded-xl border border-line-soft bg-canvas p-5">
              <h3 className="text-lg font-semibold">{f.title}</h3>
              <p className="mt-1 text-ink-muted">{f.text}</p>
            </article>
          ))}
        </div>
      </Section>

      <Section id="facts" title="The short version">
        <dl className="grid gap-5 sm:grid-cols-3">
          <div className="rounded-xl bg-surface p-5"><dt className="text-ink-muted">How long</dt><dd className="mt-1 font-display text-2xl font-bold text-brand">24 hours or less</dd></div>
          <div className="rounded-xl bg-surface p-5"><dt className="text-ink-muted">What it costs</dt><dd className="mt-1 font-display text-2xl font-bold text-brand">Nothing</dd></div>
          <div className="rounded-xl bg-surface p-5"><dt className="text-ink-muted">If you leave</dt><dd className="mt-1 font-display text-2xl font-bold text-brand">One-click export</dd></div>
        </dl>
        <p className="mt-8 max-w-3xl text-lg text-ink-muted">
          The promise works both ways. Your school&apos;s data is yours: export all of it, any time, in one click. No lock-in.
        </p>
      </Section>

      <CtaBand title="Start the switch today" text="Tell us what you use now and what to bring over. We'll handle the rest." />
    </>
  );
}
