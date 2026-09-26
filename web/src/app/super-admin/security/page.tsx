'use client';

import React, { Suspense, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * Every administrator account on the platform — school admins and operators —
 * with its 2FA and session state (doc §5, platform security).
 *
 * An admin account reaches a whole school's records, an operator's reaches
 * every school's. The question this answers is which of those accounts is one
 * leaked password away from that, and the one action it offers is the first
 * response to a suspected compromise: sign the account out everywhere.
 */

interface Admin {
  id: number;
  name: string;
  email: string;
  role: 'school_admin' | 'super_admin';
  school: { id: number; name: string; subdomain: string } | null;
  two_factor_enabled: boolean;
  active_sessions: number;
  last_login_at: string | null;
  locked_until: string | null;
  status: string | null;
}

interface Summary {
  admins: number;
  with_two_factor: number;
  without_two_factor: number;
  locked: number;
  two_factor_required: boolean;
}

function when(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : 'Never';
}

function SecurityScreen() {
  const searchParams = useSearchParams();
  const [admins, setAdmins] = useState<Admin[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const [search, setSearch] = useState('');
  const [twoFactor, setTwoFactor] = useState('');
  // Set when arriving from a school's page.
  const [schoolId, setSchoolId] = useState<string | null>(searchParams.get('school_id'));

  const load = useCallback(async () => {
    const params = new URLSearchParams();
    if (search.trim()) params.set('search', search.trim());
    if (twoFactor) params.set('two_factor', twoFactor);
    if (schoolId) params.set('school_id', schoolId);

    const res = await fetchApi<{ summary: Summary; data: Admin[] }>(`/platform/security/admins?${params}`);
    setAdmins(res.data);
    setSummary(res.summary);
  }, [search, twoFactor, schoolId]);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load administrator accounts.');
      } finally {
        setLoading(false);
      }
    })();
    // Search is submitted with the form, not on every keystroke.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [twoFactor, schoolId]);

  async function revoke(admin: Admin) {
    if (!window.confirm(`Sign ${admin.name} out of all ${admin.active_sessions} session(s)? They will need to sign in again on every device.`)) return;

    setBusyId(admin.id);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ message: string }>(`/platform/security/admins/${admin.id}/revoke-sessions`, {
        method: 'POST',
      });
      setNotice(res.message);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Those sessions could not be revoked.');
    } finally {
      setBusyId(null);
    }
  }

  const filteredSchool = schoolId ? admins.find((a) => String(a.school?.id) === schoolId)?.school : null;

  return (
    <PageShell
      title="Security"
      subtitle="Administrator accounts across every school: two-factor sign-in, sessions and lockouts."
      actions={
        <Link href="/super-admin/audit-log">
          <Button variant="secondary">Audit log</Button>
        </Link>
      }
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          {summary && (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <StatTile label="Administrator accounts" value={summary.admins.toLocaleString('en-NG')} />
              <StatTile label="With 2FA" value={summary.with_two_factor.toLocaleString('en-NG')} tone="green" />
              <StatTile
                label="Without 2FA"
                value={summary.without_two_factor.toLocaleString('en-NG')}
                tone={summary.without_two_factor > 0 ? 'red' : 'slate'}
                hint={
                  summary.two_factor_required
                    ? 'Sign-in requires 2FA; these accounts must set it up at next sign-in.'
                    : '2FA is not enforced on this server (AUTH_REQUIRE_ADMIN_2FA is off).'
                }
              />
              <StatTile label="Locked out now" value={summary.locked.toLocaleString('en-NG')} tone="amber" />
            </div>
          )}

          <Card>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                load().catch((err) => setError(err instanceof Error ? err.message : 'That search could not be run.'));
              }}
              className="mb-4 flex flex-wrap gap-2"
            >
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search by name or email"
                aria-label="Search administrators"
                className={`${inputClass} max-w-xs`}
              />
              <select
                value={twoFactor}
                onChange={(e) => setTwoFactor(e.target.value)}
                aria-label="Filter by 2FA"
                className={`${inputClass} max-w-[12rem]`}
              >
                <option value="">Any 2FA state</option>
                <option value="off">Without 2FA</option>
                <option value="on">With 2FA</option>
              </select>
              <Button type="submit" variant="secondary">
                Search
              </Button>
              {schoolId && (
                <Button type="button" variant="secondary" onClick={() => setSchoolId(null)}>
                  {filteredSchool ? `${filteredSchool.name} only — show all` : 'Show all schools'}
                </Button>
              )}
            </form>

            {admins.length === 0 ? (
              <Empty>No administrator matches.</Empty>
            ) : (
              <Table columns={['Administrator', 'School', '2FA', 'Sessions', 'Last sign-in', '']}>
                {admins.map((admin) => (
                  <tr key={admin.id}>
                    <Cell>
                      <span className="font-medium text-brand">{admin.name}</span>
                      <span className="block text-xs text-ink-muted">{admin.email}</span>
                    </Cell>
                    <Cell>
                      {admin.role === 'super_admin' ? (
                        <Badge tone="blue">SchoolPilot operator</Badge>
                      ) : admin.school ? (
                        <Link href={`/super-admin/schools/${admin.school.id}`} className="text-brand hover:underline">
                          {admin.school.name}
                        </Link>
                      ) : (
                        '—'
                      )}
                    </Cell>
                    <Cell>
                      <Badge tone={admin.two_factor_enabled ? 'green' : 'red'}>{admin.two_factor_enabled ? 'On' : 'Off'}</Badge>
                      {admin.locked_until && (
                        <span className="mt-1 block text-xs text-caution">Locked until {when(admin.locked_until)}</span>
                      )}
                    </Cell>
                    <Cell>{admin.active_sessions}</Cell>
                    <Cell className="whitespace-nowrap text-xs">{when(admin.last_login_at)}</Cell>
                    <Cell className="text-right">
                      {admin.active_sessions > 0 && (
                        <Button variant="secondary" disabled={busyId === admin.id} onClick={() => revoke(admin)}>
                          Sign out everywhere
                        </Button>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}

// useSearchParams needs a Suspense boundary for the page to prerender.
export default function PlatformSecurityPage() {
  return (
    <Suspense fallback={null}>
      <SecurityScreen />
    </Suspense>
  );
}
