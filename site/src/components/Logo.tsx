import Link from "next/link";

/** Logo from web/public/brand, resized by scripts/sync-brand.mjs. */
export function Logo({ white = false }: { white?: boolean }) {
  return (
    <Link href="/" className="inline-flex shrink-0 items-center" aria-label="SchoolPilot home">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={white ? "/brand/logo-white.webp" : "/brand/logo.webp"}
        alt="SchoolPilot"
        width={176}
        height={36}
        className="h-8 w-auto md:h-9"
      />
    </Link>
  );
}
