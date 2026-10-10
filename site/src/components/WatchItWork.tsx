import Link from "next/link";
import { videosFor } from "@/lib/blog";
import { Section } from "./Blocks";
import { VideoCard } from "./VideoCards";
import { VideoEmbed } from "./VideoEmbed";

/**
 * Walkthrough videos from the blog, shown where a school is deciding. A post
 * is placed on a page in Super Admin > Blog ("Also show this video on"). With
 * nothing placed, the section is left out rather than shown empty.
 *
 * Plays the newest video in place, with up to three more as cards. The
 * homepage uses VideoCards instead, which has no player, so it ships no video
 * JavaScript against its budget (scripts/check-budget.mjs).
 */
export function WatchItWork({
  placement,
  tone = "canvas",
  title = "Watch it work",
  intro = "Short walkthroughs recorded in the real product, so you can see it before you talk to anyone.",
}: {
  placement: string;
  tone?: "canvas" | "surface" | "sunken";
  title?: string;
  intro?: string;
}) {
  const [lead, ...rest] = videosFor(placement).slice(0, 4);
  if (!lead?.video) return null;

  return (
    <Section id="watch" tone={tone} eyebrow="See it for yourself" title={title} intro={intro}>
      <div className="grid items-start gap-8 lg:grid-cols-[1.6fr_1fr]">
        <VideoEmbed video={lead.video} title={lead.title} poster={lead.cover} />
        <div>
          <h3 className="text-xl font-semibold">{lead.title}</h3>
          <p className="mt-2 text-ink-muted">{lead.excerpt}</p>
          <Link href={`/blog/${lead.slug}/`} className="mt-4 inline-block font-semibold text-brand underline underline-offset-4">
            Read the walkthrough <span className="arrow-nudge">→</span>
          </Link>
        </div>
      </div>
      {rest.length > 0 && (
        <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {rest.map((post) => (
            <li key={post.id}>
              <VideoCard post={post} />
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}
