import type { Metadata } from "next";
import Link from "next/link";
import { features } from "@/content/features";
import { CtaBand, PageHero, Section } from "@/components/Blocks";
import { PrimaryCta, SecondaryCta } from "@/components/Cta";
import { FeatureVisual } from "@/components/FeatureVisual";
import { Icon, IconTile, type IconName } from "@/components/Icon";

export const metadata: Metadata = {
  title: "School Management System Features",
  description: "School management software features for Nigerian private schools: result computation, offline CBT, LMS, school fees, student records, HR and payroll.",
  alternates: { canonical: "/features/" },
};

/** Everything else the platform covers, without a page of its own yet. */
const more: { icon: IconName; title: string; text: string }[] = [
  { icon: "pin", title: "Result checker with PINs", text: "Parents check results online with a PIN your school issues or sells." },
  { icon: "star", title: "Nursery developmental ratings", text: "Ratings instead of scores for the early years." },
  { icon: "transfer", title: "Student transfers", text: "Move a student's records to another SchoolPilot school." },
  { icon: "rollover", title: "Session rollover", text: "Classes, subjects and teachers carried into the new session." },
  { icon: "insights", title: "Student progress insights", text: "See who is improving and who is slipping, term on term." },
  { icon: "export", title: "One-click data export", text: "Everything your school holds, exported whenever you want it." },
];

export default function FeaturesHub() {
  return (
    <>
      <PageHero
        eyebrow="Features"
        title="One all-in-one system for your whole school."
        sub="Start with the problem that costs you most. Each one below is solved in the same login, with the same records."
      >
        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
          <PrimaryCta where="hero" />
          <SecondaryCta where="hero" />
        </div>
        <ul aria-label="Jump to a feature" className="mt-8 flex flex-wrap gap-2.5">
          {features.map((f) => (
            <li key={f.slug}>
              <a
                href={`#${f.slug}`}
                className="inline-flex items-center gap-2 rounded-full border border-line-soft bg-canvas px-4 py-2 text-sm font-semibold text-brand transition hover:border-brand hover:bg-surface"
              >
                <Icon name={f.icon} size={18} />
                {f.name}
              </a>
            </li>
          ))}
        </ul>
      </PageHero>

      {features.map((f, i) => (
        <section
          key={f.slug}
          id={f.slug}
          aria-labelledby={`${f.slug}-title`}
          className={`${i % 2 === 0 ? "bg-canvas" : "bg-surface"} scroll-mt-20 py-14 md:py-20`}
        >
          <div className="container-site grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
            <div className={i % 2 === 1 ? "lg:order-2" : ""}>
              <div className="flex items-center gap-3">
                <IconTile name={f.icon} />
                <p className="eyebrow">{f.name}</p>
              </div>
              <h2 id={`${f.slug}-title`} className="mt-4 text-3xl font-bold md:text-4xl">
                {f.title}
              </h2>
              <p className="mt-4 text-lg text-ink-muted">{f.sub}</p>
              <ul className="mt-6 space-y-3">
                {f.benefits.slice(0, 3).map((b) => (
                  <li key={b.title} className="flex gap-3">
                    <span className="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-positive/15 text-positive">
                      <Icon name="check" size={14} />
                    </span>
                    <span>
                      <span className="font-semibold text-ink">{b.title}.</span> <span className="text-ink-muted">{b.text}</span>
                    </span>
                  </li>
                ))}
              </ul>
              <Link href={`/features/${f.slug}/`} className="btn btn-secondary mt-8">
                See how {f.name.replace(/ \(.*\)$/, "")} works →
              </Link>
            </div>
            <div className={i % 2 === 1 ? "lg:order-1" : ""}>
              <FeatureVisual shot={f.shot} icon={f.icon} name={f.name} points={f.benefits.map((b) => b.title)} />
            </div>
          </div>
        </section>
      ))}

      <Section id="more" tone="sunken" eyebrow="And when you need them" title="Also in SchoolPilot">
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {more.map((m) => (
            <li key={m.title} className="flex gap-4 rounded-2xl border border-line-soft bg-surface p-5">
              <IconTile name={m.icon} size="sm" tone="accent" />
              <span>
                <span className="block font-semibold text-brand">{m.title}</span>
                <span className="mt-1 block text-sm text-ink-muted">{m.text}</span>
              </span>
            </li>
          ))}
        </ul>
      </Section>
      <CtaBand />
    </>
  );
}
