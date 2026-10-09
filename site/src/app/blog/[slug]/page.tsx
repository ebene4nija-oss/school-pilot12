import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CtaBand } from "@/components/Blocks";
import { JsonLd, breadcrumbs } from "@/components/JsonLd";
import { VideoEmbed } from "@/components/VideoEmbed";
import { embedUrl, formatDate, getPost, posts, readingMinutes, renderMarkdown } from "@/lib/blog";
import { site } from "@/lib/site";

export const dynamicParams = false;

export function generateStaticParams() {
  // An empty list is rejected by the static export, so an unpublished blog
  // still builds one page that renders as a 404.
  return posts.length ? posts.map((p) => ({ slug: p.slug })) : [{ slug: "_" }];
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const post = getPost((await params).slug);
  if (!post) return {};
  const title = post.meta_title || post.title;
  const description = post.meta_description || post.excerpt;
  const image = post.cover ?? (site.ogImage || undefined);

  return {
    title,
    description,
    alternates: { canonical: `/blog/${post.slug}/` },
    openGraph: {
      type: "article",
      title,
      description,
      url: `/blog/${post.slug}/`,
      publishedTime: post.published_at,
      modifiedTime: post.updated_at,
      authors: [post.author_name],
      ...(image ? { images: [{ url: image, width: 1200, height: 630, alt: post.cover_alt ?? post.title }] } : {}),
    },
    twitter: { card: "summary_large_image", title, description, ...(image ? { images: [image] } : {}) },
  };
}

export default async function PostPage({ params }: { params: Promise<{ slug: string }> }) {
  const post = getPost((await params).slug);
  if (!post) notFound();

  const url = `${site.url}/blog/${post.slug}/`;
  const image = post.cover ?? (site.ogImage || null);

  const article = {
    "@context": "https://schema.org",
    "@type": "BlogPosting",
    headline: post.title,
    description: post.meta_description || post.excerpt,
    datePublished: post.published_at,
    dateModified: post.updated_at,
    author: { "@type": "Organization", name: post.author_name },
    publisher: { "@type": "Organization", name: "SchoolPilot", logo: { "@type": "ImageObject", url: `${site.url}/brand/logo.webp` } },
    mainEntityOfPage: url,
    inLanguage: "en-NG",
    ...(image ? { image: [`${site.url}${image}`] } : {}),
  };

  // Google only shows a video result when it has a thumbnail and a date.
  const video =
    post.video && image
      ? {
          "@context": "https://schema.org",
          "@type": "VideoObject",
          name: post.title,
          description: post.meta_description || post.excerpt,
          thumbnailUrl: [`${site.url}${image}`],
          uploadDate: post.published_at,
          embedUrl: embedUrl(post.video),
        }
      : null;

  return (
    <>
      <JsonLd data={[article, ...(video ? [video] : []), breadcrumbs({ name: "Blog", path: "/blog/" }, { name: post.title, path: `/blog/${post.slug}/` })]} />
      <article>
        <header className="bg-surface py-12 md:py-16">
          <div className="container-site max-w-3xl">
            <p className="eyebrow">
              <Link href="/blog/" className="hover:underline underline-offset-4">
                Blog
              </Link>
              {post.category && <> · {post.category}</>}
            </p>
            <h1 className="mt-3 text-3xl font-bold md:text-5xl">{post.title}</h1>
            <p className="mt-4 text-lg text-ink-muted">{post.excerpt}</p>
            <p className="mt-4 text-sm text-ink-muted">
              {post.author_name} · {formatDate(post.published_at)} · {readingMinutes(post.body)} min read
            </p>
          </div>
        </header>
        <div className="bg-canvas py-10 md:py-14">
          <div className="container-site max-w-3xl">
            {post.video ? (
              <VideoEmbed video={post.video} title={post.title} poster={post.cover} />
            ) : (
              post.cover && (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={post.cover} alt={post.cover_alt ?? ""} width={1200} height={630} className="w-full rounded-2xl" />
              )
            )}
            <div className="prose-site mt-8 text-lg" dangerouslySetInnerHTML={{ __html: renderMarkdown(post.body) }} />
            {post.tags && post.tags.length > 0 && (
              <ul aria-label="Tags" className="mt-10 flex flex-wrap gap-2">
                {post.tags.map((t) => (
                  <li key={t} className="rounded-full bg-surface px-3 py-1 text-sm text-ink-muted ring-1 ring-line-soft">#{t}</li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </article>
      <CtaBand />
    </>
  );
}
