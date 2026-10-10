/**
 * YouTube and Vimeo helpers, kept apart from lib/blog.ts so the click-to-play
 * player (a client component) never pulls markdown-it into a page's bundle.
 */
export type Video = { provider: "youtube" | "vimeo"; id: string };

export const watchUrl = (v: Video) => (v.provider === "youtube" ? `https://www.youtube.com/watch?v=${v.id}` : `https://vimeo.com/${v.id}`);

export const embedUrl = (v: Video) =>
  v.provider === "youtube"
    ? `https://www.youtube-nocookie.com/embed/${v.id}?rel=0`
    : `https://player.vimeo.com/video/${v.id}?dnt=1`;
