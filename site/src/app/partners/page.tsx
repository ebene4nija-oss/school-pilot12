import type { Metadata } from "next";
import { PartnerForm } from "@/components/PartnerForm";

export const metadata: Metadata = {
  title: "Partner Programme",
  description: "Introduce Nigerian private schools to SchoolPilot and earn a 30% commission for as long as each school stays.",
  alternates: { canonical: "/partners/" },
};

// The published rate. Keep in step with PARTNER_DEFAULT_RATE_BPS on the backend.
const COMMISSION_PERCENT = 30;

const STEPS = [
  ["Apply", "Tell us who you are. We review every application and contact you when it is approved."],
  ["Share your link", "You get a personal link. A school that asks for a demo through it is credited to you."],
  ["Earn every term", `Each time a school you introduced pays its SchoolPilot bill, you earn ${COMMISSION_PERCENT}% of it. For as long as the school keeps paying.`],
] as const;

export default function PartnersPage() {
  return (
    <section data-hero className="bg-canvas py-12 md:py-16">
      <div className="container-site grid gap-10 lg:grid-cols-[1fr_1.2fr] [&>*]:min-w-0">
        <div>
          <p className="eyebrow">Partner programme</p>
          <h1 className="mt-3 text-4xl font-bold md:text-5xl">{COMMISSION_PERCENT}% for life on every school you bring.</h1>
          <p className="mt-4 text-lg text-ink-muted">
            If you advise, supply or know private schools, introduce them to SchoolPilot. You earn {COMMISSION_PERCENT}% of what each school pays us, every term, for as long as it stays.
          </p>

          <ol className="mt-8 space-y-5">
            {STEPS.map(([title, body], i) => (
              <li key={title} className="flex gap-4">
                <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand font-bold text-white">
                  {i + 1}
                </span>
                <div>
                  <h2 className="text-lg font-semibold text-brand">{title}</h2>
                  <p className="text-ink-muted">{body}</p>
                </div>
              </li>
            ))}
          </ol>

          <ul className="mt-8 list-disc space-y-1.5 pl-5 text-ink-muted">
            <li>Commission is on bills the school has actually paid. Trials and waived bills do not earn it.</li>
            <li>You are paid by bank transfer, and you can see every school and every payment in your partner page.</li>
            <li>You never see a school&apos;s students or records, only that it is yours.</li>
          </ul>
        </div>
        <PartnerForm />
      </div>
    </section>
  );
}
