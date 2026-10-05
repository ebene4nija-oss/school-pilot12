import type { Metadata } from "next";
import Link from "next/link";
import { SchoolFinder } from "@/components/SchoolFinder";

export const metadata: Metadata = {
  title: "Log in to your school",
  description: "Find your school's SchoolPilot login.",
  alternates: { canonical: "/schools/" },
};

export default function SchoolsPage() {
  return (
    <section data-hero className="bg-canvas py-12 md:py-20">
      <div className="container-site max-w-xl">
        <p className="eyebrow">School login</p>
        <h1 className="mt-3 text-4xl font-bold">Log in to your school.</h1>
        <p className="mt-3 text-lg text-ink-muted">Every school on SchoolPilot has its own address. Enter yours to go to its login.</p>
        <div className="mt-8">
          <SchoolFinder purpose="login" />
        </div>
        <p className="mt-8 rounded-xl bg-surface p-5">
          <span className="font-semibold text-brand">Checking a result?</span>{" "}
          <Link href="/schools/results/" className="font-semibold text-brand underline underline-offset-4">
            Use your school&apos;s result checker →
          </Link>
        </p>
      </div>
    </section>
  );
}
