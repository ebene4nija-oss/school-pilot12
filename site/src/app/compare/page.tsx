import type { Metadata } from "next";
import { CtaBand, PageHero, Section } from "@/components/Blocks";
import { JsonLd, breadcrumbs } from "@/components/JsonLd";
import { comparison, hasComparison } from "@/lib/comparison";

const description =
  "How SchoolPilot compares with other school management software in Nigeria: result computation, CBT, LMS, fees and price per student, checked against each vendor's own published terms.";

export const metadata: Metadata = {
  title: "SchoolPilot Compared: School Software in Nigeria",
  description,
  alternates: { canonical: "/compare/" },
  openGraph: { title: "SchoolPilot compared | SchoolPilot", description, url: "/compare/" },
  // Hidden from search until the console has a verified competitor to show.
  robots: hasComparison ? undefined : { index: false, follow: false },
};

const date = (iso: string) =>
  new Date(iso).toLocaleDateString("en-NG", { day: "numeric", month: "long", year: "numeric", timeZone: "Africa/Lagos" });

export default function ComparePage() {
  const data = comparison;
  if (!hasComparison || !data) {
    return (
      <>
        <PageHero eyebrow="Compare" title="Comparison coming soon." sub="We only publish comparisons we have checked against each vendor's own published terms." />
        <CtaBand />
      </>
    );
  }

  return (
    <>
      <JsonLd data={breadcrumbs({ name: "Compare", path: "/compare/" })} />
      <PageHero
        eyebrow="Compare"
        title="SchoolPilot compared with other school software in Nigeria."
        sub="Every competitor value was checked against the vendor's own published terms on the date shown. Prices and features change, so check the vendor before you decide."
      />
      <Section id="table">
        <div className="overflow-x-auto rounded-2xl border border-line-soft bg-surface">
          <table className="w-full min-w-[40rem] text-left">
            <thead>
              <tr className="border-b border-line-soft bg-surface-sunken text-sm">
                <th scope="col" className="px-4 py-3 font-semibold text-ink-muted">What you get</th>
                <th scope="col" className="px-4 py-3 font-display font-bold text-brand">SchoolPilot</th>
                {data.competitors.map((c) => (
                  <th key={c.name} scope="col" className="px-4 py-3 font-semibold">
                    {c.website ? (
                      <a href={c.website} rel="nofollow noopener noreferrer" className="underline underline-offset-4">
                        {c.name}
                      </a>
                    ) : (
                      c.name
                    )}
                    <span className="block text-xs font-normal text-ink-muted">checked {date(c.verified_on)}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row, i) => (
                <tr key={row.label} className="border-b border-line-soft last:border-0">
                  <th scope="row" className="px-4 py-3 font-semibold">{row.label}</th>
                  <td className="px-4 py-3 font-medium text-brand">{row.schoolpilot || "—"}</td>
                  {data.competitors.map((c) => (
                    <td key={c.name} className="px-4 py-3">{c.values[i] || "—"}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-4 max-w-3xl text-sm text-ink-muted">
          Something out of date? Tell us and we will correct it. A dash means we have not confirmed that detail.
        </p>
      </Section>
      <CtaBand />
    </>
  );
}
