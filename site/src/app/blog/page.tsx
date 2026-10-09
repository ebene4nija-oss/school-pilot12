import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand, PageHero, Section } from "@/components/Blocks";
import { JsonLd, breadcrumbs } from "@/components/JsonLd";
import { formatDate, hasBlog, posts, readingMinutes } from "@/lib/blog";

const description =
  "Guides for Nigerian school owners and teachers: computing CA and exam results, running CBT without internet, collecting school fees and choosing school management software.";

export const metadata: Metadata = {
  title: "School Management Blog for Nigerian Schools",
  description,
  alternates: { canonical: "/blog/" },
  openGraph: { title: "School Management Blog | SchoolPilot", description, url: "/blog/" },
  // An empty blog is a stub, not something to index.
  robots: hasBlog ? undefined : { index: false, follow: true },
};

export default function BlogPage() {
  return (
    <>
      <JsonLd data={breadcrumbs({ name: "Blog", path: "/blog/" })} />
      <PageHero eyebrow="Blog" title="Practical guides for Nigerian schools." sub="Results, CBT, school fees and running a school with less paperwork." />
      <Section>
        {posts.length === 0 ? (
          <p className="text-lg text-ink-muted">Our first guides are on the way.</p>
        ) : (
          <ul className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            {posts.map((p) => (
              <li key={p.slug} className="flex flex-col overflow-hidden rounded-2xl border border-line-soft bg-surface">
                {p.cover && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={p.cover} alt={p.cover_alt ?? ""} width={1200} height={630} className="aspect-[1200/630] w-full object-cover" loading="lazy" />
                )}
                <div className="flex flex-1 flex-col p-5">
                  <p className="text-sm text-ink-muted">
                    {formatDate(p.published_at)} · {readingMinutes(p.body)} min read{p.video ? " · Video" : ""}
                  </p>
                  <h2 className="mt-2 text-xl font-bold">
                    <Link href={`/blog/${p.slug}/`} className="hover:underline underline-offset-4">
                      {p.title}
                    </Link>
                  </h2>
                  <p className="mt-2 text-ink-muted">{p.excerpt}</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Section>
      <CtaBand />
    </>
  );
}
