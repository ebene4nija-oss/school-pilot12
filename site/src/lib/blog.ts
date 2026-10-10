/**
 * Blog posts written in the console (Super Admin > Blog), pulled at build
 * time by scripts/fetch-site-config.mjs into src/generated/blog.json.
 * Markdown is rendered with raw HTML switched off, so a post can never inject
 * markup into the page.
 */
import MarkdownIt from "markdown-it";
import generated from "@/generated/blog.json";

import type { Video } from "./video";

export { embedUrl, watchUrl, type Video } from "./video";

export type Post = {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  body: string;
  /** Site path of the cover (also the share preview), when the post has one. */
  cover: string | null;
  cover_alt: string | null;
  video: Video | null;
  /** Where else the video is shown: "home", a feature slug, or "download". Set in Super Admin > Blog. */
  video_placement?: string | null;
  meta_title: string | null;
  meta_description: string | null;
  author_name: string;
  /** Optional; older posts and builds before categories have none. */
  category?: string | null;
  tags?: string[];
  published_at: string;
  updated_at: string;
};

export const posts = generated as unknown as Post[];

export const hasBlog = posts.length > 0;

/** Published posts whose video is placed on a page, newest first. */
export function videosFor(placement: string) {
  return posts.filter((p) => p.video && p.video_placement === placement);
}

export function getPost(slug: string) {
  return posts.find((p) => p.slug === slug);
}

const md = new MarkdownIt({ html: false, linkify: true, typographer: false, breaks: false });

// Every external link opens safely.
const defaultLink = md.renderer.rules.link_open ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options));
md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
  const href = String(tokens[idx].attrGet("href") ?? "");
  if (/^https?:\/\//i.test(href)) {
    tokens[idx].attrSet("rel", "noopener noreferrer");
  }
  return defaultLink(tokens, idx, options, env, self);
};

export const renderMarkdown = (source: string) => md.render(source);


/** Rough reading time, for the post header. */
export const readingMinutes = (body: string) => Math.max(1, Math.round(body.split(/\s+/).length / 220));

export const formatDate = (iso: string) =>
  new Date(iso).toLocaleDateString("en-NG", { day: "numeric", month: "long", year: "numeric", timeZone: "Africa/Lagos" });
