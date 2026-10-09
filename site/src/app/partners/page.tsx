import type { Metadata } from "next";
import Link from "next/link";
import { FaqList, PageHero, Section, Steps } from "@/components/Blocks";
import { PartnerForm } from "@/components/PartnerForm";
import type { Faq } from "@/content/home";
import { RESULTS, naira } from "@/lib/pricing";
import { site } from "@/lib/site";

export const metadata: Metadata = {
  title: "Partner Programme: Earn 30% on Every School You Introduce",
  description:
    "Introduce Nigerian private schools to SchoolPilot and earn 30% of what each school pays, every term, for as long as it stays. Apply to become a partner.",
  alternates: { canonical: "/partners/" },
};

// The published rate. Keep in step with PARTNER_DEFAULT_RATE_BPS on the backend.
const COMMISSION_PERCENT = 30;

// An illustration, not a promise: a day school on Result Management.
const EXAMPLE_STUDENTS = 300;
const TERMS_PER_YEAR = 3;
const perTerm = EXAMPLE_STUDENTS * RESULTS;
const earnedPerTerm = (perTerm * COMMISSION_PERCENT) / 100;
const earnedPerYear = earnedPerTerm * TERMS_PER_YEAR;

const whoFor = [
  { title: "Consultants and trainers", text: "You already advise school owners on running better. SchoolPilot is something you can recommend and be paid for." },
  { title: "Suppliers and service providers", text: "Uniforms, books, ICT, exam bodies. You are in the proprietor's office every term." },
  { title: "Association and network leaders", text: "Heads of school associations and proprietors' groups, with schools that trust your word." },
  { title: "Teachers and administrators", text: "You know first-hand what a bad results season costs a school, and what fixes it." },
];

const steps = [
  { title: "Apply", text: "Tell us who you are and how you would introduce schools. We review every application." },
  { title: "Get your link", text: "Once approved you get a personal link and a partner page that shows your schools and earnings." },
  { title: "Introduce schools", text: "Share your link. A school that asks for a demo through it is credited to you." },
  { title: "Earn every term", text: `Each time one of your schools pays its SchoolPilot bill, you earn ${COMMISSION_PERCENT}% of it. Term after term, for as long as it stays.` },
];

const faqs: Faq[] = [
  {
    id: "how-much",
    q: "How much do I earn?",
    a: `${COMMISSION_PERCENT}% of every SchoolPilot bill a school you introduced pays, for as long as that school stays and keeps paying. There is no cap and no end date.`,
  },
  {
    id: "when",
    q: "When does a school start earning for me?",
    a: "When it pays a term bill. Free trials and waived bills do not earn commission, because no money has been paid. Each payment appears on your partner page.",
  },
  {
    id: "credited",
    q: "How is a school credited to me?",
    a: "When it asks for a demo or to start through your link. If a school you introduced contacts us another way, give us your partner code and we will credit you once we have confirmed it was your introduction.",
  },
  {
    id: "paid",
    q: "How am I paid?",
    a: "By bank transfer to the account you save on your partner page. Every payment we make to you is listed there with its reference.",
  },
  {
    id: "see",
    q: "What can I see about the schools I introduce?",
    a: "Their name, whether they are active, and what you have earned from them. You never see their students, staff, results or fees. Those belong to the school.",
  },
  {
    id: "sign-in",
    q: "Where do I sign in?",
    a: `At ${site.partnerLoginUrl.replace(/^https?:\/\//, "")} with the email you applied with. When you are approved we email you a link to set your password; if it expires, use "Forgot password" on the sign-in page.`,
  },
  {
    id: "cost",
    q: "Does it cost anything to join?",
    a: "No. There is no fee, no target and no exclusivity. You can introduce schools alongside whatever else you do.",
  },
  {
    id: "stops",
    q: "What if a school leaves SchoolPilot?",
    a: "Commission follows payments. If a school stops paying, no new commission is earned on it. What you have already earned is yours.",
  },
];

export default function PartnersPage() {
  return (
    <>
      <PageHero
        eyebrow="Partner programme"
        title={`Earn ${COMMISSION_PERCENT}% for life on every school you introduce.`}
        sub={`You know schools that need SchoolPilot. Introduce them, and earn ${COMMISSION_PERCENT}% of what each one pays us, every term, for as long as it stays. No fee to join, no targets.`}
      >
        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
          <a href="#apply" className="btn btn-primary">Apply to become a partner</a>
          <a href="#how" className="btn btn-secondary">See how it works</a>
        </div>
        <p id="sign-in" className="mt-5 scroll-mt-24 text-ink-muted">
          Already a partner?{" "}
          <a href={site.partnerLoginUrl} className="font-semibold text-brand underline underline-offset-4">
            Sign in to your partner page →
          </a>
        </p>
      </PageHero>

      <Section id="who" title="Who it is for" intro="If school owners listen to you, you can be a partner.">
        <div className="grid gap-5 md:grid-cols-2">
          {whoFor.map((w) => (
            <article key={w.title} className="rounded-xl border border-line-soft bg-surface p-5">
              <h3 className="text-lg font-semibold">{w.title}</h3>
              <p className="mt-1 text-ink-muted">{w.text}</p>
            </article>
          ))}
        </div>
      </Section>

      <Section id="how" tone="surface" title="How it works">
        <Steps steps={steps} />
      </Section>

      <Section
        id="earnings"
        title="What it can add up to"
        intro={
          <>
            An example, not a promise: a school of {EXAMPLE_STUDENTS} students on Result Management at {naira(RESULTS)} per student per term. See{" "}
            <Link href="/pricing/" className="font-semibold text-brand underline underline-offset-4">pricing</Link>.
          </>
        }
      >
        <dl className="grid gap-5 sm:grid-cols-3">
          <div className="rounded-xl bg-surface p-5">
            <dt className="text-ink-muted">The school pays, per term</dt>
            <dd className="mt-1 font-display text-2xl font-bold text-brand">{naira(perTerm)}</dd>
          </div>
          <div className="rounded-xl bg-surface p-5">
            <dt className="text-ink-muted">You earn, per term</dt>
            <dd className="mt-1 font-display text-2xl font-bold text-brand">{naira(earnedPerTerm)}</dd>
          </div>
          <div className="rounded-xl bg-surface p-5">
            <dt className="text-ink-muted">You earn, per year</dt>
            <dd className="mt-1 font-display text-2xl font-bold text-brand">{naira(earnedPerYear)}</dd>
          </div>
        </dl>
        <p className="mt-6 max-w-3xl text-lg text-ink-muted">
          That is from one school. Every school you introduce adds its own line, and the line keeps coming each term it pays.
        </p>
      </Section>

      <Section id="clear" tone="surface" title="Clear from the start">
        <ul className="grid gap-4 md:grid-cols-3">
          <li className="rounded-xl border border-line-soft bg-canvas p-5">
            <h3 className="text-lg font-semibold">You see every kobo</h3>
            <p className="mt-1 text-ink-muted">Your partner page lists each school, each commission and each payment we make to you.</p>
          </li>
          <li className="rounded-xl border border-line-soft bg-canvas p-5">
            <h3 className="text-lg font-semibold">Schools&apos; data stays private</h3>
            <p className="mt-1 text-ink-muted">You never see students, staff or results. A partner learns only that a school is theirs.</p>
          </li>
          <li className="rounded-xl border border-line-soft bg-canvas p-5">
            <h3 className="text-lg font-semibold">Paid by bank transfer</h3>
            <p className="mt-1 text-ink-muted">To the account you choose, in naira, with a reference for every payment.</p>
          </li>
        </ul>
      </Section>

      <Section id="faq" title="Questions partners ask">
        <div className="max-w-3xl">
          <FaqList items={faqs} />
        </div>
      </Section>

      <section id="apply" aria-labelledby="apply-title" className="bg-canvas py-14 md:py-20">
        <div className="container-site grid gap-10 lg:grid-cols-[1fr_1.2fr] [&>*]:min-w-0">
          <div>
            <p className="eyebrow">Apply</p>
            <h2 id="apply-title" className="mt-2 text-3xl font-bold md:text-4xl">Become a partner</h2>
            <p className="mt-4 text-lg text-ink-muted">
              It takes a minute. We review each application and contact you by phone or email when it is approved, with your link and your partner page.
            </p>
            <p className="mt-6 text-ink-muted">
              Questions first? <Link href="/contact/" className="font-semibold text-brand underline underline-offset-4">Talk to us</Link>.
            </p>
          </div>
          <PartnerForm />
        </div>
      </section>
    </>
  );
}
