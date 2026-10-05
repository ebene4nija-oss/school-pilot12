import type { Metadata } from "next";
import Link from "next/link";
import { features } from "@/content/features";
import { CtaBand, PageHero, Section } from "@/components/Blocks";

export const metadata: Metadata = {
  title: "Features",
  description: "The all-in-one school management system for Nigerian private schools: results, CBT with a computer-lab client, LMS, fees and school administration.",
  alternates: { canonical: "/features/" },
};

/** Everything else the platform covers, without a page of its own yet. */
const more = [
  "Result checker with PINs",
  "Nursery developmental ratings",
  "Student transfers between schools",
  "Session rollover",
  "Insights on student progress",
  "One-click data export",
];

export default function FeaturesHub() {
  return (
    <>
      <PageHero
        eyebrow="Features"
        title="One all-in-one system for your whole school."
        sub="Start with the problem that costs you most. Each one below is solved in the same login, with the same records."
      />
      <Section id="solutions">
        <ul className="grid gap-5 md:grid-cols-2">
          {features.map((f) => (
            <li key={f.slug}>
              <Link href={`/features/${f.slug}/`} className="flex h-full flex-col rounded-2xl border border-line-soft bg-surface p-6 hover:border-brand">
                <span className="eyebrow">{f.name}</span>
                <span className="mt-2 font-display text-2xl font-bold text-brand">{f.title}</span>
                <span className="mt-3 text-ink-muted">{f.sub}</span>
                <span className="mt-4 font-semibold text-brand underline underline-offset-4">See how it works →</span>
              </Link>
            </li>
          ))}
        </ul>
      </Section>
      <Section id="more" tone="surface" eyebrow="And when you need them" title="Also in SchoolPilot">
        <ul className="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
          {more.map((m) => (
            <li key={m} className="flex gap-3">
              <span aria-hidden="true" className="mt-2.5 h-2 w-2 shrink-0 rounded-full bg-accent" />
              {m}
            </li>
          ))}
        </ul>
      </Section>
      <CtaBand />
    </>
  );
}
