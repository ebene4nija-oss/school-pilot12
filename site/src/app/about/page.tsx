import type { Metadata } from "next";
import { CtaBand, PageHero, Section } from "@/components/Blocks";

export const metadata: Metadata = {
  title: "About Us: School Software for Nigeria",
  description: "SchoolPilot is school management built for Nigerian private schools, Nursery to SS3.",
  alternates: { canonical: "/about/" },
};

const principles = [
  { title: "Built for Nigerian schools", text: "Sessions and terms, CA and broadsheets, arms and positions, WAEC, NECO and BECE. Not a foreign product with Nigeria pasted in." },
  { title: "Nothing to buy", text: "No fingerprint machines or card readers. Phones and the computers you already own." },
  { title: "Honest about what it does", text: "Every claim on this site describes something SchoolPilot does today." },
  { title: "Your data stays yours", text: "Free migration in, one-click export out, and children's data treated as the most sensitive we hold." },
];

/*
 * The team section (real names, photos, a Nigerian WhatsApp number and a
 * verified address, Landing PRD §8.1) goes here once those are supplied.
 * Nothing is shown until then: no stock photos, no placeholder people.
 */
export default function AboutPage() {
  return (
    <>
      <PageHero
        eyebrow="About SchoolPilot"
        title="School management that understands a Nigerian school."
        sub="We build SchoolPilot for private schools from Nursery to SS3: the results season, the fee season, the power cuts and the parents on WhatsApp."
      />
      <Section id="principles" title="What we hold ourselves to">
        <div className="grid gap-5 md:grid-cols-2">
          {principles.map((p) => (
            <article key={p.title} className="rounded-xl border border-line-soft bg-surface p-6">
              <h3 className="text-lg font-semibold">{p.title}</h3>
              <p className="mt-1 text-ink-muted">{p.text}</p>
            </article>
          ))}
        </div>
      </Section>
      <CtaBand />
    </>
  );
}
