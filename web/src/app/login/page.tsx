import type { Metadata } from 'next';
import Image from 'next/image';
import { SignInForm } from '@/components/SignInForm';
import { PoweredBySchoolPilot, SchoolCrest } from '@/components/SchoolBrand';
import { accentOf, getPublicSchoolProfile } from '@/lib/school-profile';

/**
 * The sign-in screen.
 *
 * On a school's subdomain it is that school's door — its crest, name and
 * colour, with SchoolPilot as a small credit. Where the host names no school
 * (local development, the operator's own address) it is SchoolPilot's.
 *
 * Never indexed: the school's public home at `/` is the page for search.
 */
export async function generateMetadata(): Promise<Metadata> {
  const profile = await getPublicSchoolProfile();

  return {
    title: profile ? `Sign in | ${profile.school.name}` : 'Sign in | SchoolPilot',
    robots: { index: false, follow: false },
  };
}

export default async function LoginPage() {
  const profile = await getPublicSchoolProfile();
  const school = profile?.school;
  const accent = accentOf(school);

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-surface-sunken px-4 py-10">
      <div className="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm ring-1 ring-line-soft">
        {school ? (
          <div className="mb-6 flex items-center gap-3">
            <SchoolCrest school={school} accent={accent} size={44} />
            <div className="min-w-0">
              <p className="truncate font-semibold leading-tight" style={{ color: accent }}>
                {school.name}
              </p>
              {school.motto && <p className="truncate text-xs text-ink-muted">{school.motto}</p>}
            </div>
          </div>
        ) : (
          <Image
            src="/brand/logo.png"
            alt="SchoolPilot"
            width={1024}
            height={210}
            className="mb-6 h-8 w-auto"
            priority
          />
        )}

        <h1 className="text-xl font-semibold" style={{ color: accent }}>
          {school ? 'Sign in to the school portal' : 'Sign in to SchoolPilot'}
        </h1>
        <p className="mt-1 text-sm text-ink-muted">
          {school
            ? 'For students, parents, staff and administrators.'
            : 'Use the details your school gave you.'}
        </p>

        <div className="mt-6">
          <SignInForm accent={school?.brand_color ?? undefined} />
        </div>
      </div>

      {school && <PoweredBySchoolPilot className="mt-6" />}
    </main>
  );
}
