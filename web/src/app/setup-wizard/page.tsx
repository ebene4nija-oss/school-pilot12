'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import { Badge, Card, ErrorNote, Loading, PageShell } from '@/components/Page';

/**
 * Go-live checklist.
 *
 * Deliberately a checklist and not a wizard. The sidebar has always called
 * this "Setup Wizard", but the API has only read endpoints for academic
 * structure — `/classes` and `/terms` are gettable, not postable — so a wizard
 * that walked an admin through creating a session, its terms and its classes
 * would be a form posting to routes that do not exist.
 *
 * What this can honestly do is check what is configured, say what is missing,
 * and link to the screen that fixes each gap.
 */

type Check = {
  label: string;
  detail: string;
  count: number | null;
  href?: string;
  hrefLabel?: string;
  failed?: boolean;
};

export default function SetupChecklistPage() {
  const [checks, setChecks] = useState<Check[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    /**
     * Each probe resolves to a count or null. `null` means the check itself
     * could not run — shown as "couldn't check", never as a passing tick.
     */
    const probe = async (path: string): Promise<number | null> => {
      try {
        const data = await fetchApi<{ data?: unknown[] } | unknown[]>(path);
        const rows = Array.isArray(data) ? data : (data.data ?? []);
        return Array.isArray(rows) ? rows.length : null;
      } catch {
        return null;
      }
    };

    (async () => {
      try {
        const [terms, classes, subjects, students, reportSetup, profilePublished] = await Promise.all([
          probe('/terms'),
          probe('/classes'),
          probe('/subjects'),
          probe('/students'),
          // Done when the marking scheme and the ratings/comments are both saved.
          fetchApi<{ configured: boolean; ca_scheme: { saved: boolean } }>('/report-settings')
            .then((r) => (r.configured && r.ca_scheme.saved ? 1 : 0))
            .catch(() => null),
          // Done once the school's public page is published.
          fetchApi<{ profile: { published: boolean } }>('/school/profile')
            .then((r) => (r.profile.published ? 1 : 0))
            .catch(() => null),
        ]);

        setChecks([
          {
            label: 'Academic terms',
            detail: 'A session with its three terms, so results and fees have somewhere to attach.',
            count: terms,
          },
          {
            label: 'Classes',
            detail: 'JSS and SS classes, with arms where the school streams them.',
            count: classes,
          },
          {
            label: 'Subjects',
            detail: 'Subjects, and which teacher takes each one.',
            count: subjects,
            href: '/subjects',
            hrefLabel: 'Subjects',
          },
          {
            label: 'Report cards & grading',
            detail: 'How marks are split (any number of CAs), your grading, what is rated and which comments print — then request your report card design.',
            count: reportSetup,
            href: '/report-card-design',
            hrefLabel: 'Set up',
          },
          {
            label: 'Students enrolled',
            detail: 'Import the roster from a CSV, or add students one at a time.',
            count: students,
            href: '/students',
            hrefLabel: 'Students directory',
          },
          {
            label: 'Fee structure',
            detail: 'What each class is billed per term, before invoices can be raised.',
            count: null,
            href: '/finance',
            hrefLabel: 'Fees & finance',
            failed: false,
          },
          {
            label: 'Timetable published',
            detail: 'Generate a draft, review the clashes, publish one version.',
            count: null,
            href: '/timetable',
            hrefLabel: 'Timetable',
          },
          {
            label: 'School profile published',
            detail: 'Crest, hero photo, about and contact details on your own address, so parents can find the school and sign in from there.',
            count: profilePublished,
            href: '/school-profile',
            hrefLabel: 'School profile',
          },
          {
            label: 'Administrator 2FA',
            detail:
              'Every admin enrols before AUTH_REQUIRE_ADMIN_2FA is switched on — turning it on first locks them all out.',
            count: null,
            href: '/security',
            hrefLabel: 'Security',
          },
        ]);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not run the setup checks.');
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  return (
    <PageShell
      title="Setup checklist"
      subtitle="What this school still needs before it can run a full term."
    >
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : (
        <div className="max-w-3xl space-y-3">
          {checks.map((check) => (
            <Card key={check.label}>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <h2 className="text-sm font-semibold text-brand">{check.label}</h2>
                    <StatusBadge count={check.count} />
                  </div>
                  <p className="mt-1 text-sm text-ink-muted">{check.detail}</p>
                </div>

                {check.href && (
                  <Link
                    href={check.href}
                    className="shrink-0 rounded-lg border border-line px-3.5 py-2 text-sm font-medium text-ink transition hover:bg-surface-sunken"
                  >
                    {check.hrefLabel ?? 'Open'}
                  </Link>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}
    </PageShell>
  );
}

function StatusBadge({ count }: { count: number | null }) {
  // Not checkable from here is its own state — reporting it as done would be
  // the one thing a go-live checklist must never do.
  if (count === null) return <Badge tone="slate">check manually</Badge>;
  if (count === 0) return <Badge tone="amber">nothing set up</Badge>;

  return <Badge tone="green">{count} configured</Badge>;
}
