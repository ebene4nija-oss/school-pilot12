import type { CSSProperties } from "react";
import type { Metadata } from "next";
import Link from "next/link";
import { allInOne, beforeAfter, boarding, hero, noRisk, store, objections, pillars, problems, proofStrip, roles, termSteps } from "@/content/home";
import { CtaBand, FaqList, Section } from "@/components/Blocks";
import { DemoSchoolLink, PrimaryCta, SecondaryCta } from "@/components/Cta";
import { ProductShot } from "@/components/ProductShot";
import { RoleTabs } from "@/components/RoleTabs";
import { Icon, IconTile } from "@/components/Icon";
import { VideoCards } from "@/components/VideoCards";
import { BOARDING, naira } from "@/lib/pricing";

export const metadata: Metadata = {
  title: { absolute: "School Management System in Nigeria | SchoolPilot" },
  alternates: { canonical: "/" },
};

/** Stagger delay for .hero-in / [data-reveal] (see globals.css). */
const d = (ms: number) => ({ "--d": `${ms}ms` }) as CSSProperties;

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
            <p className="eyebrow hero-in">{hero.eyebrow}</p>
            <h1 style={d(100)} className="hero-in mt-3 text-[2.25rem] font-bold leading-[1.1] md:text-[3.25rem]">{hero.headline}</h1>
            <p style={d(200)} className="hero-in mt-5 max-w-xl text-lg text-ink-muted md:text-xl">{hero.sub}</p>
            <div style={d(320)} className="hero-in mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <PrimaryCta where="hero" />
              <SecondaryCta where="hero" />
            </div>
            <div style={d(400)} className="hero-in mt-4">
              <DemoSchoolLink where="hero" />
            </div>
            <ul className="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm font-medium text-brand">
              {hero.proof.map((p, i) => (
                <li key={p} style={d(550 + i * 110)} className="hero-pop flex items-center gap-2">
                  <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.2">
                    <path d="M4 10.5l4 4 8-9" />
                  </svg>
                  {p}
                </li>
              ))}
            </ul>
          </div>
          <div style={d(250)} className="hero-in">
            <div className="float-slow hidden sm:block">
              <ProductShot
                src="/screens/admin-dashboard.webp"
                alt="School admin dashboard at Greenfield Academy showing today's attendance, fees collected this term and result progress"
                priority
              />
            </div>
            <div className="float-slow sm:hidden">
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
          {proofStrip.map((item, i) => (
            <li key={item} data-reveal="pop" style={d(i * 90)} className="flex items-center gap-2">
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
          {problems.map((p, i) => (
            <article key={p.id} data-reveal style={d(i * 110)} className="lift flex flex-col rounded-2xl border border-line-soft bg-surface p-6">
              <p className="font-display text-lg font-semibold text-ink">{p.pain}</p>
              <p className="mt-3 text-ink-muted">{p.fix}</p>
              <p className="mt-4 rounded-lg bg-canvas px-4 py-3 font-semibold text-brand">{p.outcome}</p>
              <Link href={p.href} className="mt-5 font-semibold text-brand underline underline-offset-4">
                {p.linkLabel} <span className="arrow-nudge">→</span>
              </Link>
            </article>
          ))}
        </div>
      </Section>

      {/* 4a · Before and after */}
      <Section
        id="before-after"
        tone="surface"
        eyebrow="What actually changes"
        title="The same term. Without the scramble."
        intro="Nothing about how your school runs has to change. These are the moments that get easier."
      >
        <div className="overflow-hidden rounded-2xl border border-line-soft">
          <div aria-hidden="true" className="hidden grid-cols-[11rem_1fr_1fr] bg-navy-deep text-sm font-semibold uppercase tracking-wide text-white md:grid">
            <span className="px-5 py-3">When</span>
            <span className="px-5 py-3 text-white/70">Today</span>
            <span className="px-5 py-3 text-accent-light">With SchoolPilot</span>
          </div>
          <ul className="divide-y divide-line-soft">
            {beforeAfter.map((row, i) => (
              <li key={row.id} data-reveal style={d(i * 70)} className="grid gap-2 bg-canvas p-5 md:grid-cols-[11rem_1fr_1fr] md:gap-0 md:p-0">
                <h3 className="font-display text-lg font-semibold text-brand md:px-5 md:py-4">{row.moment}</h3>
                <p className="flex gap-2 text-ink-muted md:px-5 md:py-4">
                  <span aria-hidden="true" className="mt-0.5 font-bold text-negative">✕</span>
                  <span><span className="sr-only">Today: </span>{row.before}</span>
                </p>
                <p className="flex gap-2 font-medium text-ink md:bg-positive/5 md:px-5 md:py-4">
                  <span aria-hidden="true" className="mt-0.5 text-positive"><Icon name="check" size={18} /></span>
                  <span><span className="sr-only">With SchoolPilot: </span>{row.after}</span>
                </p>
              </li>
            ))}
          </ul>
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
          {allInOne.map((area, i) => (
            <li key={area.id} data-reveal style={d((i % 3) * 100)}>
              <Link href={area.href} className="lift flex h-full flex-col rounded-2xl border border-line-soft bg-surface p-5 hover:border-brand">
                <span className="font-display text-lg font-semibold text-brand">{area.title}</span>
                <span className="mt-2 text-[0.95rem] text-ink-muted">{area.items}</span>
                <span className="mt-auto pt-3 text-sm font-semibold text-brand underline underline-offset-4">Learn more <span className="arrow-nudge">→</span></span>
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
        <ol data-reveal className="relative grid gap-6 lg:grid-cols-7 lg:gap-4">
          <span aria-hidden="true" className="term-line absolute left-[1.15rem] top-2 h-[calc(100%-1rem)] w-0.5 bg-line-soft lg:left-0 lg:top-[1.15rem] lg:h-0.5 lg:w-full" />
          {termSteps.map((step, i) => (
            <li key={step.id} data-reveal style={d(150 + i * 140)} className="relative flex gap-4 lg:flex-col lg:gap-3">
              <span className="step-dot z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent font-display font-bold text-navy-deep">
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

      {/* 5b · Walkthrough videos placed on the homepage from the blog */}
      <VideoCards placement="home" tone="sunken" title="See SchoolPilot doing the work" intro="Short walkthroughs recorded in the real product, so you can see it before you talk to anyone." />

      {/* 6 · Roles */}
      <Section id="roles" eyebrow="For everyone in your school" title="One school, five ways in.">
        <RoleTabs
          roles={roles}
          shots={Object.fromEntries(
            roles.map((r) => [r.id, <ProductShot key={r.id} src={r.shot.src} alt={r.shot.alt} phone={r.shot.phone} />]),
          )}
        />
      </Section>

      {/* 6b · Boarding */}
      <section id="boarding" aria-labelledby="boarding-title" className="relative overflow-hidden bg-navy-deep py-16 text-white md:py-24">
        <span aria-hidden="true" className="float-slow pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-accent/15 blur-3xl" />
        <span aria-hidden="true" className="pointer-events-none absolute -bottom-32 -left-20 h-80 w-80 rounded-full bg-brand-soft/60 blur-3xl" />
        <div className="container-site relative">
          <div className="grid items-end gap-8 lg:grid-cols-[1.4fr_1fr]">
            <div data-reveal>
              <p className="eyebrow !text-accent-light">For boarding schools</p>
              <h2 id="boarding-title" className="mt-2 text-3xl font-bold text-white md:text-4xl">{boarding.title}</h2>
              <p className="mt-4 max-w-2xl text-lg text-white/80">{boarding.intro}</p>
            </div>
            <div data-reveal="pop" style={d(200)} className="rounded-2xl bg-accent p-6 text-navy-deep shadow-2xl lg:justify-self-end">
              <p className="text-sm font-semibold uppercase tracking-wide">Boarding School Management</p>
              <p className="mt-1 font-display text-4xl font-bold">
                {naira(BOARDING)} <span className="text-base font-semibold">per boarder, per term</span>
              </p>
              <p className="mt-1 text-sm">On either plan, in place of the plan rate. Day students stay at their plan rate.</p>
            </div>
          </div>
          <ul className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {boarding.items.map((item, i) => (
              <li
                key={item.id}
                data-reveal
                style={d((i % 3) * 120)}
                className="lift rounded-2xl border border-white/10 bg-white/5 p-6 hover:border-accent/60 hover:bg-white/10"
              >
                <IconTile name={item.icon} tone="dark" />
                <h3 className="mt-4 text-lg font-semibold text-white">{item.title}</h3>
                <p className="mt-2 text-[0.95rem] text-white/75">{item.text}</p>
              </li>
            ))}
          </ul>
          <div className="mt-10 flex flex-col gap-3 sm:flex-row sm:items-center">
            <PrimaryCta where="page" label="Ask about boarding" message="Hello SchoolPilot, we're a boarding school and I'd like to see the hostel side." />
            <Link href="/features/school-administration/" className="btn btn-on-dark">
              See school administration <span className="arrow-nudge">→</span>
            </Link>
          </div>
        </div>
      </section>

      {/* 6c · School store */}
      <Section
        id="store"
        tone="surface"
        eyebrow="School store"
        title={store.title}
        intro={store.intro}
      >
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {store.items.map((item, i) => (
            <li key={item.id} data-reveal style={d(i * 110)} className="lift rounded-2xl border border-line-soft bg-canvas p-6">
              <IconTile name={item.icon} tone="accent" />
              <h3 className="mt-4 text-lg font-semibold">{item.title}</h3>
              <p className="mt-2 text-[0.95rem] text-ink-muted">{item.text}</p>
            </li>
          ))}
        </ul>
        <p className="mt-6 text-ink-muted">
          Optional: it stays off until your school switches it on.{" "}
          <Link href="/features/school-administration/" className="font-semibold text-brand underline underline-offset-4">
            See school administration <span className="arrow-nudge">→</span>
          </Link>
        </p>
      </Section>

      {/* 7 · Why us */}
      <Section id="why" tone="surface" eyebrow="Why SchoolPilot" title="Built around how Nigerian schools actually work.">
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
          {pillars.map((p, i) => (
            <article key={p.id} data-reveal style={d(i * 100)} className="lift rounded-2xl border border-line-soft bg-canvas p-5">
              <h3 className="text-lg font-semibold">{p.title}</h3>
              <p className="mt-2 text-[0.95rem] text-ink-muted">{p.text}</p>
            </article>
          ))}
        </div>
        <p className="mt-6 text-ink-muted">
          Library and transport too, when you need them. <a href="#boarding" className="font-semibold text-brand underline underline-offset-4">Boarding school? See the hostel side ↑</a>
        </p>
      </Section>

      {/* 7b · Risk reversal */}
      <Section
        id="no-risk"
        tone="sunken"
        eyebrow="Trying it costs you nothing"
        title="Switching is our job, not yours."
        intro="The hard part of a new system is getting started. So we do that part, and you can walk away whenever you like."
      >
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {noRisk.map((item, i) => (
            <li key={item.id} data-reveal style={d((i % 3) * 100)} className="lift flex gap-4 rounded-2xl border border-line-soft bg-surface p-6">
              <IconTile name={item.icon} tone="accent" size="sm" />
              <div>
                <h3 className="text-lg font-semibold">{item.title}</h3>
                <p className="mt-1 text-[0.95rem] text-ink-muted">{item.text}</p>
              </div>
            </li>
          ))}
        </ul>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
          <PrimaryCta where="page" label="Start with a chat" message="Hello SchoolPilot, I'd like to try SchoolPilot for my school." />
          <Link href="/migrate/" className="font-semibold text-brand underline underline-offset-4">
            How the free migration works <span className="arrow-nudge">→</span>
          </Link>
        </div>
      </Section>

      {/* 8 · Pricing model */}
      <Section
        id="pricing"
        eyebrow="Pricing"
        title="Per student, per term. In Naira."
        intro="Pay for the students actually enrolled each term, with no per-exam, per-teacher or per-parent fees."
      >
        <div className="grid gap-5 md:grid-cols-2">
          <div data-reveal className="lift rounded-2xl border border-line-soft bg-surface p-6">
            <p className="eyebrow">School Management Subscription</p>
            <p className="mt-3 font-display text-3xl font-bold text-brand">₦300 <span className="text-base font-normal text-ink-muted">per student, per term</span></p>
            <p className="mt-2 text-ink">
              CBT and the computer-lab client · LMS · Student records · Staff, HR & payroll · Attendance · Timetable · Fees tracking · Admissions · Communication · AI Studio · AI Tutor · Parent and student apps
            </p>
            <p className="mt-2 text-ink-muted">30-day free trial. Your own result process.</p>
          </div>
          <div data-reveal style={d(150)} className="lift rounded-2xl border-2 border-brand bg-surface p-6">
            <p className="eyebrow">School Management with Result Management</p>
            <p className="mt-3 font-display text-3xl font-bold text-brand">₦500 <span className="text-base font-normal text-ink-muted">per student, per term</span></p>
            <p className="mt-2 text-ink">Everything in the subscription, plus result computation, report cards, printing and result PINs.</p>
            <p className="mt-2 text-ink-muted">School Management with Boarding School Management: ₦1,000 per term on either plan.</p>
          </div>
        </div>
        <Link href="/pricing/" className="mt-6 inline-block font-semibold text-brand underline underline-offset-4">
          See how pricing works <span className="arrow-nudge">→</span>
        </Link>
      </Section>

      {/* 9 · FAQ */}
      <Section id="faq" tone="surface" eyebrow="Questions schools ask" title="The honest answers.">
        <div className="max-w-3xl">
          <FaqList items={objections} />
          <Link href="/faq/" className="mt-6 inline-block font-semibold text-brand underline underline-offset-4">
            All questions <span className="arrow-nudge">→</span>
          </Link>
        </div>
      </Section>

      {/* 10 · Final CTA */}
      <CtaBand />
    </>
  );
}
