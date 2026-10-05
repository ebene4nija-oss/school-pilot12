import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { Globe, Mail, MapPin, Phone } from 'lucide-react';
import { SignInForm } from '@/components/SignInForm';
import { PoweredBySchoolPilot, SchoolCrest } from '@/components/SchoolBrand';
import {
  accentOf,
  getPublicSchoolProfile,
  requestOrigin,
  type PublicSchool,
  type SocialNetwork,
} from '@/lib/school-profile';

/**
 * School public portal & branded login: the school's front door.
 *
 * Served at `/` to signed-out visitors — the proxy rewrites rather than
 * redirects, so the address a parent types and the one a search engine indexes
 * is the school's own root. Signed-in users still get their dashboard there.
 *
 * Everything on this page comes from `/public/school-profile`, which answers
 * from an allowlist of institutional fields: nothing about a student, parent,
 * staff member, result or notice can appear here. Search engines are invited
 * in only once the school admin publishes; until then it is a branded sign-in
 * page marked noindex.
 */

const SOCIAL_LABELS: Record<SocialNetwork, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  x: 'X (Twitter)',
  youtube: 'YouTube',
  linkedin: 'LinkedIn',
  tiktok: 'TikTok',
};

function locality(school: PublicSchool): string {
  return [school.lga, school.state].filter(Boolean).join(', ');
}

function description(school: PublicSchool, published: boolean): string {
  if (!published) return `Sign in to the ${school.name} portal for students, parents and staff.`;

  const where = [school.address, locality(school)].filter(Boolean).join(', ');

  return [
    `${school.name}${school.motto ? ` — ${school.motto}` : ''}.`,
    'School information, student and parent portal, and staff sign-in.',
    where ? `Located at ${where}.` : '',
  ]
    .filter(Boolean)
    .join(' ');
}

export async function generateMetadata(): Promise<Metadata> {
  const profile = await getPublicSchoolProfile();

  if (!profile) return { robots: { index: false, follow: false } };

  const { school, published } = profile;
  const title = `${school.name} | Student & Parent Portal`;
  const desc = description(school, published);

  return {
    metadataBase: await requestOrigin(),
    title: { absolute: title },
    description: desc,
    alternates: { canonical: '/' },
    robots: published ? { index: true, follow: true } : { index: false, follow: false },
    openGraph: {
      type: 'website',
      url: '/',
      siteName: school.name,
      title,
      description: desc,
      images: school.hero_image_url ? [{ url: school.hero_image_url }] : school.logo_url ? [{ url: school.logo_url }] : [],
    },
  };
}

export default async function SchoolHomePage() {
  const profile = await getPublicSchoolProfile();

  // No school on this host (local development, the apex): the plain sign-in.
  if (!profile) redirect('/login');

  const { school, published, admissions_open: admissionsOpen } = profile;
  const accent = accentOf(school);
  const socials = Object.entries(school.social_links ?? {}).filter(([, url]) => url) as [SocialNetwork, string][];
  const hasContact = published && Boolean(school.address || school.phone || school.email || school.website);
  const year = new Date().getFullYear();

  return (
    <div className="min-h-screen bg-canvas text-ink">
      {published && <SchoolJsonLd school={school} origin={(await requestOrigin()).origin} />}

      <header className="border-b border-line-soft bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
          <a href="#top" className="flex min-w-0 items-center gap-3">
            <SchoolCrest school={school} accent={accent} size={40} />
            <span className="min-w-0">
              <span className="block truncate font-semibold leading-tight" style={{ color: accent }}>
                <span className="sm:hidden">{school.short_name || school.name}</span>
                <span className="hidden sm:inline">{school.name}</span>
              </span>
              {school.motto && <span className="hidden truncate text-xs text-ink-muted sm:block">{school.motto}</span>}
            </span>
          </a>

          <nav aria-label="School" className="flex shrink-0 items-center gap-1 text-sm">
            {published && school.about && <NavLink href="#about">About</NavLink>}
            {admissionsOpen && <NavLink href="#admissions">Admissions</NavLink>}
            {hasContact && <NavLink href="#contact">Contact</NavLink>}
            <a
              href="#sign-in"
              className="ml-1 rounded-lg px-3 py-1.5 font-medium text-white"
              style={{ backgroundColor: accent }}
            >
              Sign in
            </a>
          </nav>
        </div>
      </header>

      <main id="top">
        <section className="mx-auto grid max-w-6xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] lg:py-10">
          <Hero school={school} accent={accent} />

          <div id="sign-in" className="scroll-mt-20 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-line-soft sm:p-8">
            <p className="text-sm font-medium text-ink-muted">Welcome to</p>
            <h1 className="mt-0.5 text-2xl font-semibold leading-tight" style={{ color: accent }}>
              {school.name}
            </h1>
            {school.motto && <p className="mt-1 text-sm italic text-ink-muted">{school.motto}</p>}
            <p className="mt-4 text-sm text-ink-muted">
              Secure portal for students, parents, staff and administrators.
            </p>

            <div className="mt-6">
              <SignInForm accent={school.brand_color ?? undefined} />
            </div>
          </div>
        </section>

        {published && (school.about || school.sections?.length || school.school_type) && (
          <section id="about" className="scroll-mt-20 border-t border-line-soft bg-white">
            <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
              <h2 className="text-xl font-semibold" style={{ color: accent }}>
                About {school.short_name || school.name}
              </h2>
              {school.about && (
                <p className="mt-3 max-w-3xl whitespace-pre-line leading-relaxed text-ink">{school.about}</p>
              )}

              {(school.sections?.length || school.school_type) && (
                <ul className="mt-5 flex flex-wrap gap-2" aria-label="Sections">
                  {school.school_type && <Chip>{school.school_type}</Chip>}
                  {school.sections?.map((s) => <Chip key={s}>{s}</Chip>)}
                </ul>
              )}
            </div>
          </section>
        )}

        {admissionsOpen && (
          <section id="admissions" className="scroll-mt-20 border-t border-line-soft">
            <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
              <h2 className="text-xl font-semibold" style={{ color: accent }}>
                Admissions are open
              </h2>
              <p className="mt-2 max-w-3xl text-ink-muted">
                {school.name} is accepting applications. Contact the school office to ask about places,
                entrance assessments and fees.
              </p>
              <div className="mt-4 flex flex-wrap gap-3">
                {school.phone && (
                  <a
                    href={`tel:${school.phone.replace(/[^\d+]/g, '')}`}
                    className="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    style={{ backgroundColor: accent }}
                  >
                    Call {school.phone}
                  </a>
                )}
                {school.email && (
                  <a
                    href={`mailto:${school.email}?subject=${encodeURIComponent('Admissions enquiry')}`}
                    className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-medium text-ink"
                  >
                    Email the school
                  </a>
                )}
              </div>
            </div>
          </section>
        )}

        {hasContact && (
          <section id="contact" className="scroll-mt-20 border-t border-line-soft bg-white">
            <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
              <h2 className="text-xl font-semibold" style={{ color: accent }}>
                Contact
              </h2>
              <address className="mt-4 grid gap-4 not-italic sm:grid-cols-2 lg:grid-cols-4">
                {school.address && (
                  <ContactItem icon={<MapPin className="h-4 w-4" />} label="Address">
                    {school.address}
                    {locality(school) && <span className="block text-ink-muted">{locality(school)}</span>}
                  </ContactItem>
                )}
                {school.phone && (
                  <ContactItem icon={<Phone className="h-4 w-4" />} label="School office">
                    <a href={`tel:${school.phone.replace(/[^\d+]/g, '')}`} className="hover:underline">
                      {school.phone}
                    </a>
                  </ContactItem>
                )}
                {school.email && (
                  <ContactItem icon={<Mail className="h-4 w-4" />} label="Email">
                    <a href={`mailto:${school.email}`} className="break-all hover:underline">
                      {school.email}
                    </a>
                  </ContactItem>
                )}
                {school.website && (
                  <ContactItem icon={<Globe className="h-4 w-4" />} label="Website">
                    <a href={school.website} rel="noopener" className="break-all hover:underline">
                      {school.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '')}
                    </a>
                  </ContactItem>
                )}
              </address>

              {socials.length > 0 && (
                <ul className="mt-6 flex flex-wrap gap-2" aria-label="Social media">
                  {socials.map(([network, url]) => (
                    <li key={network}>
                      <a
                        href={url}
                        rel="noopener me"
                        className="inline-block rounded-full border border-line px-3 py-1 text-sm text-ink hover:bg-surface-sunken"
                      >
                        {SOCIAL_LABELS[network] ?? network}
                      </a>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </section>
        )}
      </main>

      <footer className="border-t border-line-soft">
        <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-6 text-xs text-ink-faint sm:flex-row sm:px-6">
          <p>
            © {year} {school.name}
          </p>
          <PoweredBySchoolPilot />
        </div>
      </footer>
    </div>
  );
}

function Hero({ school, accent }: { school: PublicSchool; accent: string }) {
  if (school.hero_image_url) {
    return (
      <div className="relative h-56 overflow-hidden rounded-2xl bg-surface-raised sm:h-72 lg:h-auto lg:min-h-[28rem]">
        {/* The school's own upload; see SchoolCrest for why not next/image. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={school.hero_image_url}
          alt={`${school.name} campus`}
          className="absolute inset-0 h-full w-full object-cover"
          fetchPriority="high"
        />
      </div>
    );
  }

  // No photo yet: the school's colour and crest, not an empty grey box.
  return (
    <div
      className="flex h-56 flex-col items-center justify-center gap-4 rounded-2xl px-6 text-center sm:h-72 lg:h-auto lg:min-h-[28rem]"
      style={{ background: `linear-gradient(135deg, ${accent} 0%, color-mix(in srgb, ${accent} 70%, black) 100%)` }}
    >
      <span className="rounded-xl bg-white p-3 shadow-sm">
        <SchoolCrest school={school} accent={accent} size={72} />
      </span>
      {school.motto && <p className="max-w-md text-lg font-medium text-white/90">{school.motto}</p>}
    </div>
  );
}

function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <a href={href} className="hidden rounded-lg px-3 py-1.5 text-ink-muted hover:bg-surface-sunken hover:text-ink md:inline-block">
      {children}
    </a>
  );
}

function Chip({ children }: { children: React.ReactNode }) {
  return <li className="rounded-full bg-surface-sunken px-3 py-1 text-sm text-ink">{children}</li>;
}

function ContactItem({ icon, label, children }: { icon: React.ReactNode; label: string; children: React.ReactNode }) {
  return (
    <div className="flex gap-3 text-sm">
      <span className="mt-0.5 text-ink-faint">{icon}</span>
      <div className="min-w-0">
        <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</p>
        <div className="mt-0.5 text-ink">{children}</div>
      </div>
    </div>
  );
}

/** schema.org `School`, so a search result can show the address and phone. */
function SchoolJsonLd({ school, origin }: { school: PublicSchool; origin: string }) {
  const data = {
    '@context': 'https://schema.org',
    '@type': 'School',
    name: school.name,
    ...(school.short_name ? { alternateName: school.short_name } : {}),
    ...(school.motto ? { slogan: school.motto } : {}),
    ...(school.about ? { description: school.about } : {}),
    url: `${origin}/`,
    ...(school.logo_url ? { logo: school.logo_url } : {}),
    ...(school.hero_image_url ? { image: school.hero_image_url } : {}),
    ...(school.phone ? { telephone: school.phone } : {}),
    ...(school.email ? { email: school.email } : {}),
    address: {
      '@type': 'PostalAddress',
      ...(school.address ? { streetAddress: school.address } : {}),
      ...(school.lga ? { addressLocality: school.lga } : {}),
      ...(school.state ? { addressRegion: school.state } : {}),
      addressCountry: 'NG',
    },
    sameAs: [school.website, ...Object.values(school.social_links ?? {})].filter(Boolean),
  };

  return (
    <script
      type="application/ld+json"
      // `<` escaped so school-entered text can never close the script element.
      dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, '\\u003c') }}
    />
  );
}
