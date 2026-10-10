import Link from "next/link";
import { videosFor, type Post } from "@/lib/blog";
import { Section } from "./Blocks";

/**
 * Walkthrough videos as cards that open their blog post. No player here, so
 * a page using only this (the homepage) ships no video JavaScript. Left out
 * entirely when nothing is placed on the page.
 */
export function VideoCards({
  placement,
  tone = "canvas",
  title,
  intro,
}: {
  placement: string;
  tone?: "canvas" | "surface" | "sunken";
  title: string;
  intro?: string;
}) {
  const videos = videosFor(placement).slice(0, 3);
  if (videos.length === 0) return null;

  return (
    <Section id="watch" tone={tone} eyebrow="See it for yourself" title={title} intro={intro}>
      <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {videos.map((post) => (
          <li key={post.id}>
            <VideoCard post={post} />
          </li>
        ))}
      </ul>
    </Section>
  );
}

/** A video post as a card: its cover with a play mark, or a branded panel when it has none. */
export function VideoCard({ post }: { post: Post }) {
  return (
    <Link href={`/blog/${post.slug}/`} className="lift group flex h-full flex-col overflow-hidden rounded-2xl border border-line-soft bg-surface hover:border-brand">
      <span className="relative block aspect-video bg-gradient-to-br from-brand via-brand-soft to-navy-deep">
        {post.cover && (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={post.cover} alt="" loading="lazy" decoding="async" className="absolute inset-0 h-full w-full object-cover opacity-85" />
        )}
        <span className="absolute inset-0 flex items-center justify-center">
          <span className="flex h-14 w-14 items-center justify-center rounded-full bg-white text-brand shadow-lg transition-transform group-hover:scale-105">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
              <path d="M8 5v14l11-7z" />
            </svg>
          </span>
        </span>
      </span>
      <span className="flex flex-1 flex-col p-5">
        <span className="font-display text-lg font-semibold text-brand">{post.title}</span>
        <span className="mt-2 line-clamp-3 text-[0.95rem] text-ink-muted">{post.excerpt}</span>
        <span className="mt-auto pt-3 text-sm font-semibold text-brand">
          Watch <span className="arrow-nudge">→</span>
        </span>
      </span>
    </Link>
  );
}
