import type { Metadata } from "next";
import { LeadForm } from "@/components/LeadForm";

export const metadata: Metadata = {
  title: "Get started",
  description: "Start SchoolPilot for your school. We set up your school, move your records in free within 24 hours, and send your login.",
  alternates: { canonical: "/start/" },
};

const steps = [
  { title: "You tell us about your school", text: "This form: the basics, the address you'd like, and what to move in." },
  { title: "We set up your school", text: "We create your school's SchoolPilot and migrate your records, free, within 24 hours." },
  { title: "Your login arrives", text: "On WhatsApp and email. You'll turn on two-factor sign-in the first time you log in." },
  { title: "The setup wizard takes over", text: "It walks your admin through sections, classes, subjects and staff." },
];

export default function StartPage() {
  return (
    <section data-hero className="bg-canvas py-12 md:py-16">
      <div className="container-site grid gap-10 lg:grid-cols-[1fr_1.2fr] [&>*]:min-w-0">
        <div>
          <p className="eyebrow">Get started</p>
          <h1 className="mt-3 text-4xl font-bold md:text-5xl">Start SchoolPilot. We do the setup.</h1>
          <p className="mt-4 text-lg text-ink-muted">No self-service wizard to fight with. A person sets up your school and moves your data in.</p>
          <ol className="mt-8 space-y-5">
            {steps.map((s, i) => (
              <li key={s.title} className="flex gap-4">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent font-display font-bold text-navy-deep">{i + 1}</span>
                <div>
                  <p className="font-semibold text-brand">{s.title}</p>
                  <p className="text-ink-muted">{s.text}</p>
                </div>
              </li>
            ))}
          </ol>
        </div>
        <LeadForm intent="start" submitLabel="Start my school" />
      </div>
    </section>
  );
}
