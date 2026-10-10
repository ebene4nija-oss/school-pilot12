"use client";

import { useState } from "react";
import { embedUrl, watchUrl, type Video } from "@/lib/video";

/**
 * A YouTube or Vimeo video that loads only when clicked. Until then the page
 * makes no request to either service (no tracking, no weight on mobile data),
 * and without JavaScript the poster is a plain link to the video.
 */
export function VideoEmbed({ video, title, poster }: { video: Video; title: string; poster: string | null }) {
  const [playing, setPlaying] = useState(false);

  return (
    <div className="relative aspect-video overflow-hidden rounded-2xl border border-line-soft bg-navy-deep">
      {playing ? (
        <iframe
          src={`${embedUrl(video)}${embedUrl(video).includes("?") ? "&" : "?"}autoplay=1`}
          title={title}
          className="absolute inset-0 h-full w-full"
          allow="accelerometer; autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      ) : (
        <a
          href={watchUrl(video)}
          onClick={(e) => {
            e.preventDefault();
            setPlaying(true);
          }}
          className="group absolute inset-0 flex items-center justify-center"
          aria-label={`Play video: ${title}`}
        >
          {poster && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={poster} alt="" className="absolute inset-0 h-full w-full object-cover opacity-80" loading="lazy" />
          )}
          <span className="relative flex h-16 w-16 items-center justify-center rounded-full bg-white text-brand shadow-lg transition-transform group-hover:scale-105">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
              <path d="M8 5v14l11-7z" />
            </svg>
          </span>
        </a>
      )}
    </div>
  );
}
