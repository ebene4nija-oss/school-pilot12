import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand, Section } from "@/components/Blocks";
import { PrimaryCta } from "@/components/Cta";
import { Icon, IconTile, type IconName } from "@/components/Icon";
import { JsonLd, breadcrumbs } from "@/components/JsonLd";
import { hasWhatsApp } from "@/lib/site";

export const metadata: Metadata = {
  title: "Free School Data Migration in 24 Hours",
  description: "We move your students, guardians, staff, past results and fee balances into SchoolPilot free, within 24 hours. Leave any time with a one-click export.",
  alternates: { canonical: "/migrate/" },
};

const what: { icon: IconName; title: string; text: string }[] = [
  { icon: "students", title: "Students and guardians", text: "Every learner, with the parents and guardians you contact." },
  { icon: "admin", title: "Classes, arms and subjects", text: "Your structure as it is today, Nursery to SS3." },
  { icon: "apps", title: "Staff records", text: "Teachers and non-teaching staff, ready for their logins." },
  { icon: "results", title: "Past results", text: "Academic history, so last term's results are still there." },
  { icon: "fees", title: "Outstanding fee balances", text: "Who owes what, carried in so nothing is written off." },
];

const from: { icon: IconName; title: string; text: string }[] = [
  { icon: "sheet", title: "Excel or CSV", text: "Send us your spreadsheets as they are. We map the columns." },
  { icon: "paper", title: "Paper records", text: "We send you simple templates to fill, then import them." },
  { icon: "portal", title: "Another portal", text: "Export what you can from it, and we take it from there." },
];

const steps: { icon: IconName; title: string; text: string }[] = [
  { icon: "chat", title: "Tell us what you use now", text: "On WhatsApp or the start form: your school, and what to bring over." },
  { icon: "sheet", title: "Send what you have", text: "Spreadsheets, filled templates or an export from your old portal." },
  { icon: "clock", title: "We move it in", text: "We set up your school and import your records, within 24 hours." },
  { icon: "check", title: "You log in and check", text: "Start with results and add the rest when you're ready." },
];

const facts: { icon: IconName; label: string; value: string }[] = [
  { icon: "clock", label: "How long", value: "24 hours or less" },
  { icon: "gift", label: "What it costs", value: "Nothing" },
  { icon: "unlock", label: "If you leave", value: "One-click export" },
];

export default function MigratePage() {
  return (
    <>
      <JsonLd data={breadcrumbs({ name: "Switching", path: "/migrate/" })} />

      {/* Hero: the promise on the left, the three facts that back it on the right. */}
      <section data-hero className="relative isolate overflow-hidden bg-surface py-14 md:py-20">
        <span aria-hidden="true" className="absolute -right-24 -top-24 -z-10 h-80 w-80 rounded-full bg-accent/15 blur-3xl" />
        <div className="container-site grid items-center gap-12 lg:grid-cols-[1.15fr_1fr]">
          <div>
            <div className="flex items-center gap-3">
              <IconTile name="transfer" />
              <p className="eyebrow">Switching to SchoolPilot</p>
            </div>
            <h1 className="mt-4 text-4xl font-bold md:text-5xl">We move your school in. Free, within 24 hours.</h1>
            <p className="mt-5 text-lg text-ink-muted md:text-xl">
              Switching mid-session doesn&apos;t have to mean chaos. Send us what you have, and your records are in SchoolPilot the next day. Start with
              results, add the rest when you&apos;re ready.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              {hasWhatsApp ? (
                <PrimaryCta where="hero" label="Start my migration on WhatsApp" message="Hello SchoolPilot, I'd like to move my school over to SchoolPilot." />
              ) : (
                <Link href="/start/" className="btn btn-primary">
                  Start my migration
                </Link>
              )}
              <Link href="/demo/" className="btn btn-secondary">
                Book a demo first
              </Link>
            </div>
          </div>

          <div className="rounded-3xl bg-gradient-to-br from-brand via-brand-soft to-navy-deep p-7 text-white shadow-xl md:p-9">
            <p className="font-display text-sm font-semibold uppercase tracking-wide text-accent-light">The short version</p>
            <dl className="mt-6 space-y-4">
              {facts.map((f) => (
                <div key={f.label} className="flex items-center gap-4 rounded-2xl bg-white/10 p-4 ring-1 ring-white/15">
                  <span className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-accent text-navy-deep">
                    <Icon name={f.icon} size={24} />
                  </span>
                  <div>
                    <dt className="text-sm text-white/70">{f.label}</dt>
                    <dd className="font-display text-2xl font-bold">{f.value}</dd>
                  </div>
                </div>
              ))}
            </dl>
          </div>
        </div>
      </section>

      <Section id="what" eyebrow="What comes with you" title="What we move for you">
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {what.map((w) => (
            <li key={w.title} className="flex gap-4 rounded-2xl border border-line-soft bg-surface p-5 transition hover:border-brand hover:shadow-md">
              <IconTile name={w.icon} />
              <span>
                <span className="block font-semibold text-brand">{w.title}</span>
                <span className="mt-1 block text-sm text-ink-muted">{w.text}</span>
              </span>
            </li>
          ))}
          <li className="flex items-center gap-4 rounded-2xl border-2 border-dashed border-line p-5">
            <IconTile name="chat" tone="accent" />
            <span className="text-sm text-ink-muted">
              Something else to bring over? <span className="font-semibold text-brand">Tell us and we&apos;ll look at it.</span>
            </span>
          </li>
        </ul>
      </Section>

      <Section id="how" tone="surface" eyebrow="How it works" title="Four steps, one day">
        <ol className="relative grid gap-6 md:grid-cols-4">
          {/* The line that joins the steps on wide screens. */}
          <span aria-hidden="true" className="absolute left-[12%] right-[12%] top-7 hidden h-0.5 bg-line-soft md:block" />
          {steps.map((s, i) => (
            <li key={s.title} className="relative text-center">
              <span className="relative mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-accent text-navy-deep shadow-md ring-8 ring-surface">
                <Icon name={s.icon} size={26} />
              </span>
              <p className="mt-4 text-sm font-semibold text-accent-ink">Step {i + 1}</p>
              <h3 className="mt-1 text-lg font-semibold">{s.title}</h3>
              <p className="mt-1 text-ink-muted">{s.text}</p>
            </li>
          ))}
        </ol>
      </Section>

      <Section id="from" eyebrow="Wherever it lives today" title="From spreadsheets, paper or another portal">
        <div className="grid gap-5 md:grid-cols-3">
          {from.map((f) => (
            <article key={f.title} className="overflow-hidden rounded-2xl border border-line-soft bg-surface">
              <div className="flex items-center justify-center bg-gradient-to-br from-brand-tint/40 to-surface-sunken py-8 text-brand">
                <Icon name={f.icon} size={48} />
              </div>
              <div className="p-5">
                <h3 className="text-lg font-semibold">{f.title}</h3>
                <p className="mt-1 text-ink-muted">{f.text}</p>
              </div>
            </article>
          ))}
        </div>
      </Section>

      <section className="bg-surface-sunken py-14 md:py-16">
        <div className="container-site">
          <div className="flex flex-col gap-6 rounded-3xl border border-line-soft bg-surface p-7 md:flex-row md:items-center md:p-10">
            <span className="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-positive/15 text-positive">
              <Icon name="unlock" size={32} />
            </span>
            <div>
              <h2 className="text-2xl font-bold md:text-3xl">No lock-in. The promise works both ways.</h2>
              <p className="mt-2 max-w-3xl text-lg text-ink-muted">
                Your school&apos;s data is yours: export all of it, any time, in one click.{" "}
                <Link href="/trust/" className="font-semibold text-brand underline underline-offset-4">
                  How we protect it →
                </Link>
              </p>
            </div>
          </div>
        </div>
      </section>

      <CtaBand title="Start the switch today" text="Tell us what you use now and what to bring over. We'll handle the rest." />
    </>
  );
}
