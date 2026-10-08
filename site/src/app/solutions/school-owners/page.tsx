import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand, FaqList, PageHero, Section } from "@/components/Blocks";
import { ProductShot } from "@/components/ProductShot";
import { objections, problems } from "@/content/home";

export const metadata: Metadata = {
  title: "For proprietors and school leaders",
  description: "See your whole school from your phone: attendance, fees, results and learning. Priced per student, per term, in Naira.",
  alternates: { canonical: "/solutions/school-owners/" },
};

const week = [
  { when: "Monday morning", what: "Today's attendance across every class." },
  { when: "Mid-week", what: "Fees collected this term against what's outstanding, class by class." },
  { when: "Exam week", what: "Scores coming in, and which results are waiting for your approval." },
  { when: "Result day", what: "Report cards released to parents in one step, no late nights." },
];

export default function SchoolLeadersPage() {
  return (
    <>
      <PageHero
        eyebrow="For proprietors and school leaders"
        title="Know what's happening across your school, without chasing anyone."
        sub="You sign in as your school's admin and see attendance, fees, results and learning in one place, from your phone or your office."
      />

      <Section id="week" title="A week in your school, from your phone">
        <div className="grid items-start gap-10 lg:grid-cols-2">
          <ol className="space-y-4">
            {week.map((w) => (
              <li key={w.when} className="rounded-xl border border-line-soft bg-surface p-5">
                <p className="eyebrow">{w.when}</p>
                <p className="mt-1 text-lg">{w.what}</p>
              </li>
            ))}
          </ol>
          <ProductShot src="/screens/admin-dashboard.webp" alt="School admin dashboard showing today's attendance, fees collected this term and result progress" />
        </div>
      </Section>

      <Section id="problems" tone="surface" title="The four problems we take off your desk">
        <div className="grid gap-5 md:grid-cols-2">
          {problems.map((p) => (
            <article key={p.id} className="rounded-2xl border border-line-soft bg-canvas p-6">
              <p className="font-display text-lg font-semibold">{p.pain}</p>
              <p className="mt-3 font-semibold text-brand">{p.outcome}</p>
              <Link href={p.href} className="mt-4 inline-block font-semibold text-brand underline underline-offset-4">{p.linkLabel} →</Link>
            </article>
          ))}
        </div>
      </Section>

      <Section id="cost" title="What it costs">
        <p className="max-w-3xl text-lg text-ink-muted">
          School Management with Result Management: ₦500 per active student per term. School Management Subscription: ₦300 after a 30-day free trial. School Management with Boarding School Management: ₦1,000 per term. CBT is
          included. We move your
          records in free within 24 hours, and you can export everything in one click if you ever leave.
        </p>
        <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2 font-semibold text-brand">
          <Link href="/pricing/" className="underline underline-offset-4">See pricing →</Link>
          <Link href="/migrate/" className="underline underline-offset-4">How migration works →</Link>
          <Link href="/trust/" className="underline underline-offset-4">How we protect your data →</Link>
        </div>
      </Section>

      <Section id="faq" tone="surface" title="What proprietors ask us">
        <div className="max-w-3xl"><FaqList items={objections} /></div>
      </Section>

      <CtaBand />
    </>
  );
}
