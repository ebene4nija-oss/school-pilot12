import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { PoweredBySchoolPilot, SchoolCrest } from '@/components/SchoolBrand';
import { accentOf, getPublicSchoolProfile } from '@/lib/school-profile';
import { resolveSubdomain } from '@/lib/session';
import ApplyForm, { type AdmissionForm } from './ApplyForm';

/**
 * A school's online admission form, at <school>.schoolpilot.org.ng/apply.
 *
 * Only there when the school chose the online form and admissions are open
 * (School Settings → Admissions). Otherwise the page says so and points back
 * to whatever ways the school did choose, on its home page.
 *
 * What is submitted goes into the school's Admissions dashboard as an online
 * application: review, assessment, offer and enrolment happen there.
 */

const API_BASE_URL =
  process.env.BACKEND_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1';

async function getForm(subdomain: string): Promise<AdmissionForm | null> {
  try {
    const res = await fetch(`${API_BASE_URL}/public/admissions/form?school=${encodeURIComponent(subdomain)}`, {
      headers: { Accept: 'application/json', 'X-School-Subdomain': subdomain },
      cache: 'no-store',
    });

    return res.ok ? ((await res.json()) as AdmissionForm) : null;
  } catch {
    return null;
  }
}

export async function generateMetadata(): Promise<Metadata> {
  const profile = await getPublicSchoolProfile();

  return {
    title: { absolute: profile ? `Apply for admission | ${profile.school.name}` : 'Apply for admission' },
    robots: { index: false, follow: false },
  };
}

export default async function ApplyPage() {
  const [profile, subdomain] = await Promise.all([getPublicSchoolProfile(), resolveSubdomain()]);

  if (!profile || !subdomain) redirect('/login');

  const { school } = profile;
  const accent = accentOf(school);
  const form = await getForm(subdomain);

  return (
    <div className="min-h-screen bg-canvas text-ink">
      <header className="border-b border-line-soft bg-white">
        <div className="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3 sm:px-6">
          <Link href="/" className="flex min-w-0 items-center gap-3">
            <SchoolCrest school={school} accent={accent} size={40} />
            <span className="truncate font-semibold" style={{ color: accent }}>
              {school.name}
            </span>
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        {form ? (
          <>
            <h1 className="text-2xl font-semibold" style={{ color: accent }}>
              Apply for admission{form.session ? ` · ${form.session}` : ''}
            </h1>
            {form.instructions && <p className="mt-2 whitespace-pre-line text-ink-muted">{form.instructions}</p>}
            <ApplyForm form={form} subdomain={subdomain} accent={accent} />
          </>
        ) : (
          <div className="rounded-xl border border-line-soft bg-white p-6">
            <h1 className="text-xl font-semibold" style={{ color: accent }}>
              Online applications aren&apos;t open
            </h1>
            <p className="mt-2 text-ink-muted">
              {school.name} isn&apos;t taking applications online at the moment. See how to contact the school about admission on its home page.
            </p>
            <Link href="/#admissions" className="mt-4 inline-block font-semibold underline" style={{ color: accent }}>
              Admissions at {school.name} →
            </Link>
          </div>
        )}
      </main>

      <footer className="mx-auto max-w-3xl px-4 pb-10 sm:px-6">
        <PoweredBySchoolPilot />
      </footer>
    </div>
  );
}
