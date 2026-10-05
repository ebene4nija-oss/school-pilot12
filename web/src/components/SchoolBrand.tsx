import Image from 'next/image';
import type { PublicSchool } from '@/lib/school-profile';

/**
 * The school's crest, or its initials on its own colour when it has not
 * uploaded one — never a blank box.
 */
export function SchoolCrest({ school, accent, size = 48 }: { school: PublicSchool; accent: string; size?: number }) {
  if (school.logo_url) {
    return (
      // A school's own upload on the API origin; next/image would need every
      // tenant's storage host whitelisted for no real gain on a crest.
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={school.logo_url}
        alt={`${school.name} crest`}
        width={size}
        height={size}
        className="shrink-0 rounded-md object-contain"
        style={{ width: size, height: size }}
      />
    );
  }

  const initials = school.name
    .split(/\s+/)
    .filter((w) => /^[A-Za-z]/.test(w) && !['of', 'and', 'the'].includes(w.toLowerCase()))
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('');

  return (
    <span
      aria-hidden="true"
      className="flex shrink-0 items-center justify-center rounded-md font-semibold text-white"
      style={{ width: size, height: size, backgroundColor: accent, fontSize: size * 0.38 }}
    >
      {initials || 'S'}
    </span>
  );
}

/** The platform's credit: present, small, and never louder than the school. */
export function PoweredBySchoolPilot({ className = '' }: { className?: string }) {
  return (
    <a
      href="https://schoolpilot.org.ng"
      className={`inline-flex items-center gap-1.5 text-xs text-ink-faint hover:text-ink-muted ${className}`}
    >
      Powered by
      <Image src="/brand/logo.png" alt="SchoolPilot" width={1024} height={210} className="h-3.5 w-auto opacity-70" />
    </a>
  );
}
