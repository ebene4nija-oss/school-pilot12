import { existsSync } from "node:fs";
import path from "node:path";

/**
 * A real product screenshot from the Greenfield Academy demo tenant
 * (Landing PRD §16), captured by scripts/capture-screens.mjs into
 * public/screens.
 *
 * Runs at build time. If the screenshot has not been captured yet, it renders
 * a plain labelled frame instead — never a mock-up of the UI, which would be
 * a picture of a product that does not exist.
 */
export function ProductShot({
  src,
  alt,
  phone = false,
  priority = false,
  className = "",
}: {
  src: string;
  alt: string;
  phone?: boolean;
  priority?: boolean;
  className?: string;
}) {
  const width = phone ? 390 : 1280;
  const height = phone ? 844 : 800;
  const exists = existsSync(path.join(process.cwd(), "public", src));

  const frame = phone
    ? "mx-auto w-full max-w-[17rem] rounded-[2rem] border-[10px] border-navy-deep bg-navy-deep shadow-xl"
    : "w-full overflow-hidden rounded-xl border border-line bg-surface shadow-xl";

  return (
    <figure className={`${frame} ${className}`}>
      {exists ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={src}
          alt={alt}
          width={width}
          height={height}
          loading={priority ? "eager" : "lazy"}
          fetchPriority={priority ? "high" : "auto"}
          decoding="async"
          className={`h-auto w-full ${phone ? "rounded-[1.4rem]" : ""}`}
        />
      ) : (
        <div
          role="img"
          aria-label={alt}
          style={{ aspectRatio: `${width} / ${height}` }}
          className={`flex w-full items-center justify-center bg-surface-sunken p-6 text-center text-sm text-ink-muted ${phone ? "rounded-[1.4rem]" : ""}`}
        >
          <span>
            <span className="block font-semibold text-brand">Screenshot to capture</span>
            {alt}
          </span>
        </div>
      )}
    </figure>
  );
}
