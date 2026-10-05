import type { Metadata } from "next";
import { SchoolFinder } from "@/components/SchoolFinder";

export const metadata: Metadata = {
  title: "Check a result",
  description: "Find your school's result checker on SchoolPilot.",
  alternates: { canonical: "/schools/results/" },
};

export default function ResultsLookupPage() {
  return (
    <section data-hero className="bg-canvas py-12 md:py-20">
      <div className="container-site max-w-xl">
        <p className="eyebrow">Result checker</p>
        <h1 className="mt-3 text-4xl font-bold">Check your child&apos;s result.</h1>
        <p className="mt-3 text-lg text-ink-muted">Enter your school&apos;s SchoolPilot address. Sign in with your parent account, open Results, and enter your result PIN.</p>
        <div className="mt-8">
          <SchoolFinder purpose="results" />
        </div>
      </div>
    </section>
  );
}
