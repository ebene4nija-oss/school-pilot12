import { existsSync } from "node:fs";
import path from "node:path";
import { Icon, type IconName } from "./Icon";
import { ProductShot } from "./ProductShot";

/**
 * A feature's picture: the real product screenshot when the console has one,
 * otherwise a branded panel with the feature's icon and what it delivers.
 *
 * The panel is deliberately not a drawing of the product's screens (Landing
 * PRD §16: no mock-ups). It states benefits in words, so it never shows a
 * screen that does not exist.
 */
export function FeatureVisual({
  shot,
  icon,
  name,
  points,
  priority = false,
}: {
  shot: { src: string; alt: string; phone?: boolean };
  icon: IconName;
  name: string;
  points: string[];
  priority?: boolean;
}) {
  if (existsSync(path.join(process.cwd(), "public", shot.src))) {
    return <ProductShot src={shot.src} alt={shot.alt} phone={shot.phone} priority={priority} />;
  }

  return (
    <figure
      aria-label={name}
      className="relative isolate overflow-hidden rounded-2xl bg-gradient-to-br from-brand via-brand-soft to-navy-deep p-7 text-white shadow-xl md:p-9"
      style={{ aspectRatio: "16 / 11" }}
    >
      {/* Soft shapes behind the content, purely decorative. */}
      <span aria-hidden="true" className="absolute -right-16 -top-16 -z-10 h-56 w-56 rounded-full bg-accent/25 blur-2xl" />
      <span aria-hidden="true" className="absolute -bottom-20 -left-10 -z-10 h-56 w-56 rounded-full bg-brand-tint/20 blur-2xl" />
      <span aria-hidden="true" className="absolute right-6 top-6 -z-10 text-white/[0.07]">
        <Icon name={icon} size={180} />
      </span>

      <div className="flex h-full flex-col">
        <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-accent text-navy-deep shadow-lg">
          <Icon name={icon} size={30} />
        </span>
        <p className="mt-5 font-display text-2xl font-bold md:text-3xl">{name}</p>
        <ul className="mt-auto space-y-2.5 pt-6">
          {points.slice(0, 3).map((p) => (
            <li key={p} className="flex items-center gap-3 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-medium ring-1 ring-white/15 backdrop-blur-sm md:text-base">
              <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-positive text-white">
                <Icon name="check" size={14} />
              </span>
              {p}
            </li>
          ))}
        </ul>
      </div>
    </figure>
  );
}
