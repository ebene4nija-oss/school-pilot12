import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand, FaqList, Section } from "@/components/Blocks";
import { PrimaryCta, SecondaryCta } from "@/components/Cta";
import { FeatureVisual } from "@/components/FeatureVisual";
import { Icon, IconTile, type IconName } from "@/components/Icon";
import { JsonLd, breadcrumbs } from "@/components/JsonLd";
import { objections, problems } from "@/content/home";
import { BOARDING, RESULTS, SUBSCRIPTION, naira } from "@/lib/pricing";

export const metadata: Metadata = {
  title: "School Management App for Proprietors",
  description: "Monitor attendance, fees collected, results and learning across your private school from your phone. From ₦300 per student per term, in Naira.",
  alternates: { canonical: "/solutions/school-owners/" },
};

const week: { icon: IconName; when: string; what: string }[] = [
  { icon: "calendar", when: "Monday morning", what: "Today's attendance across every class." },
  { icon: "fees", when: "Mid-week", what: "Fees collected this term against what's outstanding, class by class." },
  { icon: "results", when: "Exam week", what: "Scores coming in, and which results are waiting for your approval." },
  { icon: "bell", when: "Result day", what: "Report cards released to parents in one step, no late nights." },
];

const problemIcon: Record<string, IconName> = { results: "results", fees: "fees", cbt: "cbt", lms: "lms" };

const prices = [
  { name: "School Management Subscription", price: SUBSCRIPTION, note: "30-day free trial. Your own result process." },
  { name: "School Management with Result Management", price: RESULTS, note: "Results, report cards, printing and result PINs.", featured: true },
  { name: "School Management with Boarding School Management", price: BOARDING, note: "Per boarder, in place of the plan rate, on either plan." },
];

const promises: { icon: IconName; title: string; href: string }[] = [
  { icon: "transfer", title: "Records moved in free, within 24 hours", href: "/migrate/" },
  { icon: "unlock", title: "Export everything in one click", href: "/migrate/" },
  { icon: "shield", title: "Built for NDPA, no biometrics", href: "/trust/" },
];

export default function SchoolLeadersPage() {
  return (
    <>
      <JsonLd data={breadcrumbs({ name: "For school leaders", path: "/solutions/school-owners/" })} />

      <section data-hero className="relative isolate overflow-hidden bg-surface py-14 md:py-20">
        <span aria-hidden="true" className="absolute -left-24 -top-24 -z-10 h-80 w-80 rounded-full bg-brand-tint/30 blur-3xl" />
        <span aria-hidden="true" className="absolute -bottom-24 right-0 -z-10 h-80 w-80 rounded-full bg-accent/15 blur-3xl" />
        <div className="container-site grid items-center gap-12 lg:grid-cols-[1.1fr_1fr]">
          <div>
            <div className="flex items-center gap-3">
              <IconTile name="insights" />
              <p className="eyebrow">For proprietors and school leaders</p>
            </div>
            <h1 className="mt-4 text-4xl font-bold md:text-5xl">Know what&apos;s happening across your school, without chasing anyone.</h1>
            <p className="mt-5 text-lg text-ink-muted md:text-xl">
              You sign in as your school&apos;s admin and see attendance, fees, results and learning in one place, from your phone or your office.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <PrimaryCta where="hero" />
              <SecondaryCta where="hero" />
            </div>
          </div>
          <FeatureVisual
            shot={{ src: "/screens/admin-dashboard.webp", alt: "School admin dashboard showing today's attendance, fees collected this term and result progress" }}
            icon="insights"
            name="Your whole school, one screen"
            points={["Attendance across every class", "Fees collected and outstanding", "Results waiting for approval"]}
            priority
          />
        </div>
      </section>

      <Section id="week" eyebrow="From your phone" title="A week in your school">
        <ol className="relative mx-auto max-w-3xl">
          <span aria-hidden="true" className="absolute bottom-6 left-7 top-6 w-0.5 bg-line-soft" />
          {week.map((w) => (
            <li key={w.when} className="relative flex gap-5 pb-6 last:pb-0">
              <span className="relative inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-accent-light shadow-md ring-8 ring-canvas">
                <Icon name={w.icon} size={26} />
              </span>
              <div className="flex-1 rounded-2xl border border-line-soft bg-surface p-5">
                <p className="eyebrow">{w.when}</p>
                <p className="mt-1 text-lg font-medium">{w.what}</p>
              </div>
            </li>
          ))}
        </ol>
      </Section>

      <Section id="problems" tone="surface" eyebrow="Off your desk" title="The four problems we take off your desk">
        <div className="grid gap-5 md:grid-cols-2">
          {problems.map((p) => (
            <article key={p.id} className="flex flex-col rounded-2xl border border-line-soft bg-canvas p-6 transition hover:border-brand hover:shadow-md">
              <IconTile name={problemIcon[p.id] ?? "check"} />
              <p className="mt-4 font-display text-lg font-semibold text-ink-muted">{p.pain}</p>
              <p className="mt-3 flex items-start gap-2 text-lg font-semibold text-brand">
                <span className="mt-1 text-positive">
                  <Icon name="arrow" size={18} />
                </span>
                {p.outcome}
              </p>
              <Link href={p.href} className="mt-auto pt-5 font-semibold text-brand underline underline-offset-4">
                {p.linkLabel} →
              </Link>
            </article>
          ))}
        </div>
      </Section>

      <Section id="cost" eyebrow="What it costs" title="Per student, per term. In Naira." intro="CBT is included on every plan, with no per-exam, per-teacher or per-parent fees.">
        <div className="grid gap-5 md:grid-cols-3">
          {prices.map((p) => (
            <div key={p.name} className={`rounded-2xl bg-surface p-6 ${p.featured ? "border-2 border-brand shadow-lg" : "border border-line-soft"}`}>
              <p className="text-sm font-semibold text-ink-muted">{p.name}</p>
              <p className="mt-3">
                <span className="font-display text-4xl font-bold text-brand">{naira(p.price)}</span>
                <span className="ml-1 text-sm text-ink-muted">per student, per term</span>
              </p>
              <p className="mt-3 text-sm text-ink-muted">{p.note}</p>
            </div>
          ))}
        </div>
        <ul className="mt-8 grid gap-3 sm:grid-cols-3">
          {promises.map((p) => (
            <li key={p.title}>
              <Link href={p.href} className="flex items-center gap-3 rounded-xl bg-surface-sunken px-4 py-3 font-semibold text-brand hover:bg-brand-tint/30">
                <Icon name={p.icon} size={20} />
                {p.title}
              </Link>
            </li>
          ))}
        </ul>
        <Link href="/pricing/" className="btn btn-secondary mt-8">
          See worked examples →
        </Link>
      </Section>

      <Section id="faq" tone="surface" eyebrow="Questions" title="What proprietors ask us">
        <div className="max-w-3xl">
          <FaqList items={objections} />
        </div>
      </Section>

      <CtaBand />
    </>
  );
}
