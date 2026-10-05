import type { ReactNode } from "react";
import type { Faq } from "@/content/home";
import { DemoSchoolLink, PrimaryCta, SecondaryCta } from "./Cta";

/** A page section with an optional eyebrow, heading and intro. */
export function Section({
  id,
  eyebrow,
  title,
  intro,
  children,
  tone = "canvas",
}: {
  id?: string;
  eyebrow?: string;
  title?: string;
  intro?: ReactNode;
  children?: ReactNode;
  tone?: "canvas" | "surface" | "sunken";
}) {
  const bg = tone === "surface" ? "bg-surface" : tone === "sunken" ? "bg-surface-sunken" : "bg-canvas";

  return (
    <section id={id} aria-labelledby={id && title ? `${id}-title` : undefined} className={`${bg} py-14 md:py-20`}>
      <div className="container-site">
        {(eyebrow || title || intro) && (
          <div className="max-w-3xl">
            {eyebrow && <p className="eyebrow">{eyebrow}</p>}
            {title && (
              <h2 id={id ? `${id}-title` : undefined} className="mt-2 text-3xl font-bold md:text-4xl">
                {title}
              </h2>
            )}
            {intro && <div className="mt-4 text-lg text-ink-muted">{intro}</div>}
          </div>
        )}
        {children && <div className={title || intro ? "mt-10" : ""}>{children}</div>}
      </div>
    </section>
  );
}

/** Hero for inner pages: outcome headline first, the module name second. */
export function PageHero({ eyebrow, title, sub, children }: { eyebrow: string; title: string; sub: ReactNode; children?: ReactNode }) {
  return (
    <section data-hero className="bg-surface py-14 md:py-20">
      <div className="container-site max-w-4xl">
        <p className="eyebrow">{eyebrow}</p>
        <h1 className="mt-3 text-4xl font-bold md:text-5xl">{title}</h1>
        <div className="mt-5 text-lg text-ink-muted md:text-xl">{sub}</div>
        {children ?? (
          <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
            <PrimaryCta where="hero" />
            <SecondaryCta where="hero" />
          </div>
        )}
      </div>
    </section>
  );
}

/** Collapsible questions. Native <details>: works with no JavaScript. */
export function FaqList({ items }: { items: Faq[] }) {
  return (
    <div className="divide-y divide-line-soft rounded-xl border border-line-soft bg-surface">
      {items.map((item) => (
        <details key={item.id} className="group px-5 py-1 md:px-6">
          <summary className="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 py-3 font-semibold text-brand [&::-webkit-details-marker]:hidden">
            {item.q}
            <span aria-hidden="true" className="text-2xl leading-none text-ink-muted transition-transform group-open:rotate-45">
              +
            </span>
          </summary>
          <p className="pb-5 text-ink-muted">{item.a}</p>
        </details>
      ))}
    </div>
  );
}

/** The closing band on every page: the promise again, then the CTAs. */
export function CtaBand({
  title = "Ready to see SchoolPilot run your school?",
  text = "Talk to a person, not a form. We'll show you how it handles your results, fees and parents, and move your records in for free.",
}: {
  title?: string;
  text?: string;
}) {
  return (
    <section className="bg-navy-deep py-16 text-white md:py-20">
      <div className="container-site max-w-3xl text-center">
        <h2 className="text-3xl font-bold text-white md:text-4xl">{title}</h2>
        <p className="mt-4 text-lg text-white/80">{text}</p>
        <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
          <PrimaryCta where="band" />
          <SecondaryCta where="band" onDark />
        </div>
        <div className="mt-5 text-white [&_a]:text-white">
          <DemoSchoolLink where="band" />
        </div>
      </div>
    </section>
  );
}

/** Numbered "How it works" steps on feature pages. */
export function Steps({ steps }: { steps: { title: string; text: string }[] }) {
  return (
    <ol className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      {steps.map((step, i) => (
        <li key={step.title} className="rounded-xl border border-line-soft bg-surface p-5">
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-accent font-display font-bold text-navy-deep">{i + 1}</span>
          <h3 className="mt-3 text-lg font-semibold">{step.title}</h3>
          <p className="mt-1 text-ink-muted">{step.text}</p>
        </li>
      ))}
    </ol>
  );
}

/** "Nigerian detail" callout on feature pages. */
export function Callout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <aside className="rounded-xl border-l-4 border-accent bg-surface p-5 md:p-6">
      <p className="font-display font-semibold text-brand">{title}</p>
      <div className="mt-2 text-ink-muted">{children}</div>
    </aside>
  );
}
