import type { Metadata } from "next";
import { faqGroups } from "@/content/faq";
import { CtaBand, FaqList, PageHero } from "@/components/Blocks";

export const metadata: Metadata = {
  title: "Frequently asked questions",
  description: "Honest answers about SchoolPilot: cost, offline CBT, switching mid-session, parents without smartphones, data protection.",
  alternates: { canonical: "/faq/" },
};

export default function FaqPage() {
  const ld = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: faqGroups.flatMap((g) => g.items).map((i) => ({
      "@type": "Question",
      name: i.q,
      acceptedAnswer: { "@type": "Answer", text: i.a },
    })),
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld) }} />
      <PageHero eyebrow="FAQ" title="Questions schools ask us." sub="If yours isn't here, ask us directly. A person will answer." />
      <section className="bg-canvas py-12 md:py-16">
        <div className="container-site max-w-3xl space-y-12">
          {faqGroups.map((g) => (
            <div key={g.title}>
              <h2 className="mb-4 text-2xl font-bold">{g.title}</h2>
              <FaqList items={g.items} />
            </div>
          ))}
        </div>
      </section>
      <CtaBand />
    </>
  );
}
