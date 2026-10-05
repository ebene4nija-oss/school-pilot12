import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { features, getFeature } from "@/content/features";
import { Callout, CtaBand, FaqList, PageHero, Section, Steps } from "@/components/Blocks";
import { ProductShot } from "@/components/ProductShot";

export const dynamicParams = false;

export function generateStaticParams() {
  return features.map((f) => ({ slug: f.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const feature = getFeature((await params).slug);
  if (!feature) return {};
  return {
    title: feature.seoTitle,
    description: feature.seoDescription,
    alternates: { canonical: `/features/${feature.slug}/` },
    openGraph: { title: feature.title, description: feature.seoDescription, url: `/features/${feature.slug}/` },
  };
}

/** Feature page template (Landing PRD §7.1): problem → solution → proof → next step. */
export default async function FeaturePage({ params }: { params: Promise<{ slug: string }> }) {
  const feature = getFeature((await params).slug);
  if (!feature) notFound();

  const related = feature.related.map((slug) => getFeature(slug)).filter((f) => f !== undefined);

  return (
    <>
      <PageHero eyebrow={feature.name} title={feature.title} sub={feature.sub} />

      <Section id="problem" eyebrow="The problem today" title="Sound familiar?">
        <ul className="grid gap-4 md:grid-cols-3">
          {feature.problems.map((p) => (
            <li key={p} className="rounded-xl border border-line-soft bg-surface p-5 font-display text-lg font-semibold text-ink">
              “{p}”
            </li>
          ))}
        </ul>
      </Section>

      <Section id="how" tone="surface" eyebrow="How it works" title={`How SchoolPilot solves it`}>
        <div className="grid items-start gap-10 lg:grid-cols-[1.1fr_1fr]">
          <Steps steps={feature.steps} />
          <ProductShot src={feature.shot.src} alt={feature.shot.alt} phone={feature.shot.phone} />
        </div>
      </Section>

      <Section id="benefits" eyebrow="What changes" title="What your school gets">
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {feature.benefits.map((b) => (
            <article key={b.title} className="rounded-xl border border-line-soft bg-surface p-5">
              <h3 className="text-lg font-semibold">{b.title}</h3>
              <p className="mt-1 text-ink-muted">{b.text}</p>
            </article>
          ))}
        </div>
        <div className="mt-8 grid gap-6 md:grid-cols-2">
          <Callout title={feature.callout.title}>{feature.callout.text}</Callout>
          <div className="rounded-xl bg-surface p-5 md:p-6">
            <p className="font-display font-semibold text-brand">Who uses it</p>
            <ul className="mt-3 flex flex-wrap gap-2">
              {feature.roles.map((r) => (
                <li key={r} className="rounded-full border border-line px-3 py-1 text-sm font-medium text-brand">
                  {r}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </Section>

      <Section id="faq" tone="surface" eyebrow="Questions" title={`${feature.name}: common questions`}>
        <div className="max-w-3xl">
          <FaqList items={feature.faq} />
        </div>
      </Section>

      <Section id="related" eyebrow="Related" title="Works together with">
        <ul className="grid gap-4 md:grid-cols-3">
          {related.map((r) => (
            <li key={r.slug}>
              <Link href={`/features/${r.slug}/`} className="block h-full rounded-xl border border-line-soft bg-surface p-5 hover:border-brand">
                <span className="eyebrow">{r.name}</span>
                <span className="mt-2 block font-display text-lg font-semibold text-brand">{r.title}</span>
              </Link>
            </li>
          ))}
        </ul>
      </Section>

      <CtaBand />
    </>
  );
}
