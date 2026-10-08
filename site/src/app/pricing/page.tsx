import type { Metadata } from "next";
import { CtaBand, FaqList, PageHero, Section } from "@/components/Blocks";
import type { Faq } from "@/content/home";

export const metadata: Metadata = {
  title: "Pricing",
  description:
    "Two plans, per active student per term: ₦500 with result management, or ₦300 without it after a 30-day free trial. Boarders ₦1,000. CBT included.",
  alternates: { canonical: "/pricing/" },
};

// Prices approved 2026-10-08. They match the rates in Super Admin > Billing;
// change both together.
const RESULTS = 500;
const SUBSCRIPTION = 300;
const BOARDING = 1000;

const naira = (n: number) => `₦${n.toLocaleString("en-NG")}`;

const included = [
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

const plans = [
  {
    id: "results",
    name: "Result Management",
    price: RESULTS,
    trial: null,
    featured: true,
    lead: "For schools that want SchoolPilot to handle results from CA to the printed report card.",
    points: [
      "Everything in the platform",
      "Result computation: CA, exams, broadsheets and positions",
      "Report cards, printing and result PINs for parents",
    ],
  },
  {
    id: "subscription",
    name: "School Management Subscription",
    price: SUBSCRIPTION,
    trial: "30-day free trial",
    featured: false,
    lead: "For schools that already have their own way of computing and printing results.",
    points: [
      "Everything in the platform",
      "No result computation or result printing",
      "Try it free for 30 days before you pay",
    ],
  },
];

const examples = [300, 500, 1000];

const faq: Faq[] = [
  {
    id: "student",
    q: "What counts as an active student?",
    a: "Any student enrolled in a class at some point during the term. A student who leaves mid-term counts for that term; students who left in an earlier term do not.",
  },
  {
    id: "boarding",
    q: "How are boarders charged?",
    a: `Each boarding student is ${naira(BOARDING)} per term on either plan, in place of the plan rate. Day students stay at ${naira(RESULTS)} or ${naira(SUBSCRIPTION)}.`,
  },
  {
    id: "trial",
    q: "How does the free trial work?",
    a: "The School Management Subscription starts with 30 days free. A term that ends inside the trial is free; once the trial ends, that term is billed as normal.",
  },
  { id: "cbt", q: "Is CBT charged per exam or per student?", a: "No. CBT is included in both plans, with no per-exam fees." },
  {
    id: "switch",
    q: "Which plan should we choose?",
    a: "If you want SchoolPilot to compute and print your results, choose Result Management. If you already handle results your own way, the Subscription gives you everything else for less.",
  },
  { id: "stop", q: "What happens if we don't pay?", a: "Your data stays accessible and you can export all of it." },
];

export default function PricingPage() {
  return (
    <>
      <PageHero
        eyebrow="Pricing"
        title="Per student, per term. In Naira."
        sub="Two plans. You pay for the students actually enrolled each term, with no per-exam, per-teacher or per-parent fees."
      />

      <Section id="plans" title="Choose your plan">
        <div className="grid gap-6 lg:grid-cols-2">
          {plans.map((p) => (
            <div
              key={p.id}
              className={`rounded-2xl bg-surface p-6 md:p-8 ${p.featured ? "border-2 border-brand" : "border border-line-soft"}`}
            >
              <p className="eyebrow">{p.trial ?? "Full service"}</p>
              <h3 className="mt-3 font-display text-2xl font-bold text-brand">{p.name}</h3>
              <p className="mt-4">
                <span className="font-display text-4xl font-bold text-brand">{naira(p.price)}</span>
                <span className="ml-2 text-ink-muted">per active student, per term</span>
              </p>
              <p className="mt-4 text-ink-muted">{p.lead}</p>
              <ul className="mt-4 space-y-2">
                {p.points.map((pt) => (
                  <li key={pt} className="flex gap-3">
                    <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18" className="mt-1 shrink-0 text-positive" fill="none" stroke="currentColor" strokeWidth="2.2">
                      <path d="M4 10.5l4 4 8-9" />
                    </svg>
                    {pt}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
        <p className="mt-6 max-w-3xl text-lg text-ink-muted">
          <strong className="text-brand">Boarding students: {naira(BOARDING)}</strong> per boarder per term on either plan, in place of the
          plan rate.
        </p>
      </Section>

      <Section id="examples" tone="surface" title="What a term costs" intro="Day students only. Add boarders at ₦1,000 each in place of the plan rate.">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[32rem] text-left">
            <thead>
              <tr className="border-b border-line-soft text-sm text-ink-muted">
                <th className="py-3 pr-4 font-semibold">Active students</th>
                <th className="py-3 pr-4 font-semibold">Result Management</th>
                <th className="py-3 font-semibold">Subscription</th>
              </tr>
            </thead>
            <tbody>
              {examples.map((n) => (
                <tr key={n} className="border-b border-line-soft">
                  <td className="py-3 pr-4 font-semibold text-brand">{n.toLocaleString("en-NG")}</td>
                  <td className="py-3 pr-4">{naira(n * RESULTS)} per term</td>
                  <td className="py-3">{naira(n * SUBSCRIPTION)} per term</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-4 max-w-3xl text-ink-muted">
          A school of 500 with 100 boarders on Result Management: 400 × {naira(RESULTS)} + 100 × {naira(BOARDING)} ={" "}
          {naira(400 * RESULTS + 100 * BOARDING)} per term.
        </p>
      </Section>

      <Section id="included" title="Included in both plans">
        <ul className="grid max-w-4xl gap-x-6 gap-y-2.5 sm:grid-cols-2">
          {included.map((f) => (
            <li key={f} className="flex gap-3">
              <svg aria-hidden="true" viewBox="0 0 20 20" width="18" height="18" className="mt-1 shrink-0 text-positive" fill="none" stroke="currentColor" strokeWidth="2.2">
                <path d="M4 10.5l4 4 8-9" />
              </svg>
              {f}
            </li>
          ))}
        </ul>
      </Section>

      <Section id="pins" tone="surface" title="Result PINs">
        <p className="max-w-3xl text-lg text-ink-muted">
          On Result Management, parents can check results online with a PIN on your school&apos;s result checker. Your school can issue PINs to
          parents or sell them, the way scratch cards already work.
        </p>
      </Section>

      <Section id="faq" title="Pricing questions">
        <div className="max-w-3xl">
          <FaqList items={faq} />
        </div>
      </Section>

      <CtaBand title="Get started" text="Tell us roughly how many students and boarders you have and we'll set your school up on the right plan." />
    </>
  );
}
