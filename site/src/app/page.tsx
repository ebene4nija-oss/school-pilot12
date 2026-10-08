import Link from "next/link";
import { allInOne, hero, objections, pillars, problems, proofStrip, roles, termSteps } from "@/content/home";
import { CtaBand, FaqList, Section } from "@/components/Blocks";
import { DemoSchoolLink, PrimaryCta, SecondaryCta } from "@/components/Cta";
import { ProductShot } from "@/components/ProductShot";
import { RoleTabs } from "@/components/RoleTabs";

/**
 * The homepage: ten blocks that read as a chain of solutions (Landing spec
 * §5). The owner's problems, how a term runs, what each role gets, and why it
 * costs almost nothing. It never lists modules.
 */
export default function Home() {
  const faqLd = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: objections.map((o) => ({
      "@type": "Question",
      name: o.q,
      acceptedAnswer: { "@type": "Answer", text: o.a },
    })),
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(faqLd) }} />

      {/* 2 · Hero */}
      <section data-hero className="bg-surface pb-12 pt-10 md:pb-20 md:pt-16">
        <div className="container-site grid items-center gap-10 lg:grid-cols-[1.05fr_1fr]">
          <div>
            <p className="eyebrow">{hero.eyebrow}</p>
            <h1 className="mt-3 text-[2.25rem] font-bold leading-[1.1] md:text-[3.25rem]">{hero.headline}</h1>
            <p className="mt-5 max-w-xl text-lg text-ink-muted md:text-xl">{hero.sub}</p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <PrimaryCta where="hero" />
              <SecondaryCta where="hero" />
            </div>
            <div className="mt-4">
              <DemoSchoolLink where="hero" />
            </div>
            <ul className="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm font-medium text-brand">
              {hero.proof.map((p) => (
                <li key={p} className="flex items-center gap-2">
                  <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.2">
                    <path d="M4 10.5l4 4 8-9" />
                  </svg>
                  {p}
                </li>
              ))}
            </ul>
          </div>
          <div>
            <div className="hidden sm:block">
              <ProductShot
                src="/screens/admin-dashboard.webp"
                alt="School admin dashboard at Greenfield Academy showing today's attendance, fees collected this term and result progress"
                priority
              />
            </div>
            <div className="sm:hidden">
              <ProductShot
                src="/screens/admin-dashboard-phone.webp"
                alt="The school admin's view in the SchoolPilot app: attendance, fees and results at a glance"
                phone
                priority
              />
            </div>
          </div>
        </div>
      </section>

      {/* 3 · Proof strip */}
      <section aria-label="Commitments" className="border-y border-line-soft bg-canvas">
        <ul className="container-site flex flex-wrap justify-center gap-x-8 gap-y-3 py-5 text-sm font-semibold text-brand">
          {proofStrip.map((item) => (
            <li key={item} className="flex items-center gap-2">
              <span aria-hidden="true" className="h-2 w-2 rounded-full bg-accent" />
              {item}
            </li>
          ))}
        </ul>
      </section>

      {/* 4 · Problems we solve */}
      <Section
        id="problems"
        eyebrow="Problems we solve"
        title="Running a school shouldn't mean running on spreadsheets."
        intro="Four problems every proprietor knows, and how SchoolPilot takes each one off your desk."
      >
        <div className="grid gap-5 md:grid-cols-2">
          {problems.map((p) => (
            <article key={p.id} className="flex flex-col rounded-2xl border border-line-soft bg-surface p-6">
              <p className="font-display text-lg font-semibold text-ink">{p.pain}</p>
              <p className="mt-3 text-ink-muted">{p.fix}</p>
              <p className="mt-4 rounded-lg bg-canvas px-4 py-3 font-semibold text-brand">{p.outcome}</p>
              <Link href={p.href} className="mt-5 font-semibold text-brand underline underline-offset-4">
                {p.linkLabel} →
              </Link>
            </article>
          ))}
        </div>
      </Section>

      {/* 4b · All in one */}
      <Section
        id="all-in-one"
        tone="sunken"
        eyebrow="All-in-one"
        title="Everything your school runs, in one system."
        intro="No separate CBT tool, LMS, fees spreadsheet and payroll file. One SchoolPilot, one set of records, one login for each person."
      >
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {allInOne.map((area) => (
            <li key={area.id}>
              <Link href={area.href} className="flex h-full flex-col rounded-2xl border border-line-soft bg-surface p-5 hover:border-brand">
                <span className="font-display text-lg font-semibold text-brand">{area.title}</span>
                <span className="mt-2 text-[0.95rem] text-ink-muted">{area.items}</span>
                <span className="mt-auto pt-3 text-sm font-semibold text-brand underline underline-offset-4">Learn more →</span>
              </Link>
            </li>
          ))}
        </ul>
      </Section>

      {/* 5 · One term in SchoolPilot */}
      <Section
        id="one-term"
        tone="surface"
        eyebrow="One term in SchoolPilot"
        title="From resumption to report cards, in one system."
        intro="This is how a term already runs in your school. SchoolPilot just takes the paperwork out of each step."
      >
        <ol className="relative grid gap-6 lg:grid-cols-7 lg:gap-4">
          <span aria-hidden="true" className="absolute left-[1.15rem] top-2 h-[calc(100%-1rem)] w-0.5 bg-line-soft lg:left-0 lg:top-[1.15rem] lg:h-0.5 lg:w-full" />
          {termSteps.map((step, i) => (
            <li key={step.id} className="relative flex gap-4 lg:flex-col lg:gap-3">
              <span className="z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent font-display font-bold text-navy-deep">
                {i + 1}
              </span>
              <div>
                <h3 className="text-lg font-semibold">{step.title}</h3>
                <p className="mt-1 text-[0.95rem] text-ink-muted">{step.text}</p>
              </div>
            </li>
          ))}
        </ol>
      </Section>

      {/* 6 · Roles */}
      <Section id="roles" eyebrow="For everyone in your school" title="One school, five ways in.">
        <RoleTabs
          roles={roles}
          shots={Object.fromEntries(
            roles.map((r) => [r.id, <ProductShot key={r.id} src={r.shot.src} alt={r.shot.alt} phone={r.shot.phone} />]),
          )}
        />
      </Section>

      {/* 7 · Why us */}
      <Section id="why" tone="surface" eyebrow="Why SchoolPilot" title="Built around how Nigerian schools actually work.">
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
          {pillars.map((p) => (
            <article key={p.id} className="rounded-2xl border border-line-soft bg-canvas p-5">
              <h3 className="text-lg font-semibold">{p.title}</h3>
              <p className="mt-2 text-[0.95rem] text-ink-muted">{p.text}</p>
            </article>
          ))}
        </div>
        <p className="mt-6 text-ink-muted">Boarding, library and transport too, when you need them.</p>
      </Section>

      {/* 8 · Pricing model */}
      <Section
        id="pricing"
        eyebrow="Pricing"
        title="Per student, per term. In Naira."
        intro="Pay for the students actually enrolled each term, with no per-exam, per-teacher or per-parent fees."
      >
        <div className="grid gap-5 md:grid-cols-2">
          <div className="rounded-2xl border border-line-soft bg-surface p-6">
            <p className="eyebrow">School Management Subscription</p>
            <p className="mt-3 font-display text-3xl font-bold text-brand">₦300 <span className="text-base font-normal text-ink-muted">per student, per term</span></p>
            <p className="mt-2 text-ink">
              CBT and the computer-lab client · LMS · Student records · Staff, HR & payroll · Attendance · Timetable · Fees tracking · Admissions · Communication · AI Studio · AI Tutor · Parent and student apps
            </p>
            <p className="mt-2 text-ink-muted">30-day free trial. Your own result process.</p>
          </div>
          <div className="rounded-2xl border-2 border-brand bg-surface p-6">
            <p className="eyebrow">Result Management</p>
            <p className="mt-3 font-display text-3xl font-bold text-brand">₦500 <span className="text-base font-normal text-ink-muted">per student, per term</span></p>
            <p className="mt-2 text-ink">Everything in the subscription, plus result computation, report cards, printing and result PINs.</p>
            <p className="mt-2 text-ink-muted">Boarders ₦1,000 per term on either plan.</p>
          </div>
        </div>
        <Link href="/pricing/" className="mt-6 inline-block font-semibold text-brand underline underline-offset-4">
          See how pricing works →
        </Link>
      </Section>

      {/* 9 · FAQ */}
      <Section id="faq" tone="surface" eyebrow="Questions schools ask" title="The honest answers.">
        <div className="max-w-3xl">
          <FaqList items={objections} />
          <Link href="/faq/" className="mt-6 inline-block font-semibold text-brand underline underline-offset-4">
            All questions →
          </Link>
        </div>
      </Section>

      {/* 10 · Final CTA */}
      <CtaBand />
    </>
  );
}
