import type { Metadata } from "next";
import { CtaBand, FaqList, PageHero, Section } from "@/components/Blocks";
import type { Faq } from "@/content/home";
import { hasWhatsApp } from "@/lib/site";

export const metadata: Metadata = {
  title: "Pricing",
  description: "SchoolPilot is free for almost everything, CBT included. Schools pay one charge per term, by number of students, when they generate results.",
  alternates: { canonical: "/pricing/" },
};

const free = [
  "Student records and guardians",
  "Staff, HR and payroll",
  "Attendance with absence alerts",
  "Timetable and exam timetable",
  "Fees tracking and online payment",
  "CBT, including the computer-lab client",
  "LMS: materials, assignments, lesson plans, virtual classes",
  "AI Studio and the AI tutor",
  "Admissions with an online form",
  "Messaging and notifications",
  "Parent, teacher and student apps",
  "Free data migration and one-click export",
];

// Student bands match the demo form. Prices are not shown until they are
// approved in writing (Landing PRD §10): never a placeholder figure.
const bands = ["Up to 50 students", "51–200", "201–500", "501–1,000", "1,001–3,000", "More than 3,000"];

const faq: Faq[] = [
  { id: "what", q: "What exactly do we pay for?", a: "One charge per term, by student band, when you generate results. Everything else, including CBT, is free." },
  { id: "cbt", q: "Is CBT charged per exam or per student?", a: "No. CBT is included, with no per-exam fees." },
  { id: "student", q: "What counts as a student?", a: "We agree this with you, in writing, when we confirm your price." },
  { id: "session", q: "Can we pay for a whole session?", a: "Yes, and a full session costs less than three separate terms." },
  { id: "stop", q: "What happens if we don't pay?", a: "Your data stays accessible and you can export all of it." },
];

export default function PricingPage() {
  return (
    <>
      <PageHero
        eyebrow="Pricing"
        title="Free for almost everything. One charge per term."
        sub="You pay at the moment SchoolPilot saves you the most work, when your results are generated, and never for seats you don't use."
      />

      <Section id="model" title="How it works">
        <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
          <div className="rounded-2xl border border-line-soft bg-surface p-6 md:p-8">
            <p className="eyebrow">Free, forever</p>
            <ul className="mt-4 grid gap-x-6 gap-y-2.5 sm:grid-cols-2">
              {free.map((f) => (
                <li key={f} className="flex gap-3">
                  <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18" className="mt-1 shrink-0 text-positive" fill="none" stroke="currentColor" strokeWidth="2.2">
                    <path d="M4 10.5l4 4 8-9" />
                  </svg>
                  {f}
                </li>
              ))}
            </ul>
          </div>
          <div className="rounded-2xl border-2 border-brand bg-surface p-6 md:p-8">
            <p className="eyebrow">One charge, per term</p>
            <p className="mt-4 font-display text-2xl font-bold text-brand">Result generation</p>
            <p className="mt-2 text-ink-muted">
              One charge each term, set by your number of students, when you generate report cards. No per-exam, per-teacher or per-parent fees.
            </p>
            <p className="mt-4 font-semibold text-brand">Pay for a full session and save.</p>
          </div>
        </div>
      </Section>

      <Section id="bands" tone="surface" title="Priced by school size" intro="Your price depends on how many students you have.">
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {bands.map((b) => (
            <li key={b} className="flex items-center justify-between gap-4 rounded-xl border border-line-soft bg-canvas p-5">
              <span className="font-semibold text-brand">{b}</span>
              <span className="text-sm text-ink-muted">{hasWhatsApp ? "Price confirmed on WhatsApp" : "Price on request"}</span>
            </li>
          ))}
        </ul>
      </Section>

      <Section id="pins" title="Result PINs">
        <p className="max-w-3xl text-lg text-ink-muted">
          Parents can check results online with a PIN on your school&apos;s result checker. Your school can issue PINs to parents or sell them, the
          way scratch cards already work.
        </p>
      </Section>

      <Section id="faq" tone="surface" title="Pricing questions">
        <div className="max-w-3xl">
          <FaqList items={faq} />
        </div>
      </Section>

      <CtaBand title="Get your school's price" text="Tell us roughly how many students you have and we'll confirm your price per term and per session." />
    </>
  );
}
