/**
 * Line icons for the marketing site, inline so they cost no request and take
 * the colour of their text. 24×24, drawn on the same stroke so they read as a
 * set.
 */
const paths = {
  results: "M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1zM8 6H6a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1h-2M9 14l2 2 4-4",
  cbt: "M4 4h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zM9 20h6M12 16v4M9 10l2 2 4-4",
  fees: "M7 19V5l10 14V5M4 10h16M4 14h16",
  students: "M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 20a6 6 0 0 1 12 0M16 5.2a3 3 0 0 1 0 5.6M17.5 14.3A6 6 0 0 1 21 20",
  lms: "M3 5h6a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H3zM21 5h-6a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h7z",
  admin: "M3 21h18M5 21V10l7-5 7 5v11M10 21v-5h4v5M12 11h.01",
  pin: "M4 7h16v3a2 2 0 0 0 0 4v3H4v-3a2 2 0 0 0 0-4zM14 7v2M14 11v2M14 15v2",
  star: "M12 3l2.7 5.5 6 .9-4.35 4.2 1 6L12 16.8l-5.35 2.8 1-6L3.3 9.4l6-.9z",
  transfer: "M4 8h14M14 4l4 4-4 4M20 16H6M10 12l-4 4 4 4",
  rollover: "M20 11a8 8 0 0 0-14.6-4.4M4 4v4h4M4 13a8 8 0 0 0 14.6 4.4M20 20v-4h-4",
  insights: "M3 17l6-6 4 4 8-8M15 7h6v6",
  export: "M12 4v11M7 10l5 5 5-5M5 20h14",
  apps: "M8 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM11 18h2",
  check: "M5 12.5l4.5 4.5L19 7.5",
  shield: "M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z",
} as const;

export type IconName = keyof typeof paths;

export function Icon({ name, size = 24, className = "" }: { name: IconName; size?: number; className?: string }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      <path d={paths[name]} />
    </svg>
  );
}

/** The icon in a soft rounded tile, the way feature cards show it. */
export function IconTile({ name, size = "md", tone = "brand" }: { name: IconName; size?: "sm" | "md" | "lg"; tone?: "brand" | "accent" | "dark" }) {
  const box = { sm: "h-10 w-10 rounded-lg", md: "h-12 w-12 rounded-xl", lg: "h-16 w-16 rounded-2xl" }[size];
  const px = { sm: 20, md: 24, lg: 32 }[size];
  const colours = {
    brand: "bg-brand-tint/40 text-brand",
    accent: "bg-accent/20 text-accent-ink",
    dark: "bg-white/10 text-accent-light ring-1 ring-white/15",
  }[tone];

  return (
    <span className={`inline-flex shrink-0 items-center justify-center ${box} ${colours}`}>
      <Icon name={name} size={px} />
    </span>
  );
}
