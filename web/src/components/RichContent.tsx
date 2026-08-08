'use client';

import React, { useMemo } from 'react';
import katex from 'katex';

/**
 * Renders CBT question content: prose, LaTeX maths, and images.
 *
 * The server validates LaTeX and strips image metadata before anything gets
 * here (see docs/cbt-authoring-guide.md), so this component's job is display,
 * not safety — with two exceptions it must not delegate:
 *
 *  1. Prose is rendered as React text nodes, never as HTML. A question stem
 *     is authored by a teacher and could contain markup; nothing in this
 *     product needs a teacher to be able to inject HTML into a paper.
 *  2. KaTeX runs with `trust: false` and `throwOnError: false`. Untrusted
 *     mode blocks the HTML-injecting commands even though the server already
 *     refuses them, and not throwing means a bad expression shows as red
 *     source text rather than blanking the question mid-exam.
 */

export type MediaAsset = {
  asset_id: number;
  url: string;
  thumbnail_url?: string | null;
  alt_text: string;
  caption?: string | null;
  width?: number | null;
  height?: number | null;
};

export type MediaReference = MediaAsset & {
  role: string;
  position: number;
};

type Segment =
  | { kind: 'text'; value: string }
  | { kind: 'math'; value: string; display: boolean };

/** Longest opener first, so `$$` wins over `$` and `\[` over `\(`. */
const DELIMITERS: Array<[string, string, boolean]> = [
  ['$$', '$$', true],
  ['\\[', '\\]', true],
  ['\\(', '\\)', false],
  ['$', '$', false],
];

/**
 * Split authored content into prose and maths segments.
 *
 * Mirrors MathContentService::extractExpressions on the server, including the
 * escaped-dollar rule: `\$20` is a price, not an opening delimiter.
 */
export function parseSegments(content: string): Segment[] {
  const segments: Segment[] = [];
  let buffer = '';
  let i = 0;

  const flush = () => {
    if (buffer) {
      segments.push({ kind: 'text', value: buffer });
      buffer = '';
    }
  };

  while (i < content.length) {
    if (content[i] === '\\' && content[i + 1] === '$') {
      buffer += '$';
      i += 2;
      continue;
    }

    const match = DELIMITERS.find(([open]) => content.startsWith(open, i));

    if (!match) {
      buffer += content[i];
      i += 1;
      continue;
    }

    const [open, close, display] = match;
    const from = i + open.length;
    const closeAt = content.indexOf(close, from);

    if (closeAt === -1) {
      // Unclosed delimiter. The server rejects these at authoring time, so
      // reaching here means legacy content — show it as literal text rather
      // than swallowing the rest of the question.
      buffer += content.slice(i);
      break;
    }

    flush();
    segments.push({ kind: 'math', value: content.slice(from, closeAt), display });
    i = closeAt + close.length;
  }

  flush();

  return segments;
}

function MathSpan({ tex, display }: { tex: string; display: boolean }) {
  const html = useMemo(() => {
    try {
      return katex.renderToString(tex, {
        displayMode: display,
        throwOnError: false,
        trust: false,
        strict: 'ignore',
        output: 'htmlAndMathml',
      });
    } catch {
      return null;
    }
  }, [tex, display]);

  if (html === null) {
    return <code className="text-red-600">{tex}</code>;
  }

  // KaTeX output is generated locally from a server-validated expression in
  // untrusted mode; this is the one place HTML injection is intended.
  return (
    <span
      className={display ? 'block my-2 overflow-x-auto' : 'inline-block'}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}

/**
 * Question or option text with any embedded maths rendered.
 *
 * `contentFormat` comes straight from the API. Passing `'plain'` skips the
 * parser entirely — most questions in a bank are prose, and a paper is 60 of
 * them on a lab machine that is not fast.
 */
export function RichText({
  content,
  contentFormat = 'plain',
  className,
}: {
  content: string | null | undefined;
  contentFormat?: 'plain' | 'latex';
  className?: string;
}) {
  const segments = useMemo(
    () => (contentFormat === 'latex' && content ? parseSegments(content) : null),
    [content, contentFormat]
  );

  if (!content) return null;

  if (!segments) {
    return <span className={className}>{content}</span>;
  }

  return (
    <span className={className}>
      {segments.map((segment, index) =>
        segment.kind === 'text' ? (
          <React.Fragment key={index}>{segment.value}</React.Fragment>
        ) : (
          <MathSpan key={index} tex={segment.value} display={segment.display} />
        )
      )}
    </span>
  );
}

/**
 * A question's images.
 *
 * `alt` is never optional and never invented — it is authored at upload time
 * and carried through, because a candidate using a screen reader has to be
 * able to sit the same paper as everyone else.
 *
 * `loading="eager"` is deliberate: lazy-loading a diagram a candidate is about
 * to scroll to is exactly the wrong trade in an exam hall with a slow
 * connection. The offline client pre-caches these from the media manifest.
 */
export function QuestionMedia({
  media,
  className,
}: {
  media: MediaReference[] | null | undefined;
  className?: string;
}) {
  if (!media || media.length === 0) return null;

  return (
    <div className={className ?? 'my-3 flex flex-wrap gap-3'}>
      {[...media]
        .sort((a, b) => a.position - b.position)
        .map((asset) => (
          <figure key={`${asset.role}-${asset.asset_id}`} className="max-w-full">
            {/* next/image is the wrong tool here: these are school-uploaded
                assets on arbitrary storage paths, they must load eagerly
                rather than on scroll, and the offline exam client caches the
                raw URLs from the media manifest — an optimiser endpoint it
                cannot reach would leave a blank diagram in the hall. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={asset.url}
              alt={asset.alt_text}
              width={asset.width ?? undefined}
              height={asset.height ?? undefined}
              loading="eager"
              className="max-w-full h-auto rounded border border-gray-200 bg-white"
            />
            {asset.caption && (
              <figcaption className="mt-1 text-xs text-gray-600">{asset.caption}</figcaption>
            )}
          </figure>
        ))}
    </div>
  );
}

/** Question stem: text, maths and stem/diagram images together. */
export function RichContent({
  content,
  contentFormat = 'plain',
  media,
  className,
}: {
  content: string | null | undefined;
  contentFormat?: 'plain' | 'latex';
  media?: MediaReference[] | null;
  className?: string;
}) {
  return (
    <div className={className}>
      <RichText content={content} contentFormat={contentFormat} className="leading-relaxed" />
      <QuestionMedia media={media} />
    </div>
  );
}

export default RichContent;
