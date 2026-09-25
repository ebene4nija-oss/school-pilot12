'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { viewSchool } from '@/app/actions/auth';
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
  nairaAmount,
} from '@/components/Page';

/**
 * The SchoolPilot operator console.
 *
 * Previously a static page claiming 42 schools, 14,850 students and
 * "₦8,400,000 Monthly Platform MRR" — a figure that was, in the code behind it,
 * nothing at all. Two invented tenant rows sat under it.
 *
 * This is a platform operator's screen and nothing else. It does not show a
 * school's own dashboard, because a super admin has no school: the school-side
 * endpoints scope to their null `school_id` and would report zeroes everywhere.
 * To look inside one school they pick it, which scopes their session to that
 * tenant and lets the ordinary school pages answer for it.
 */

interface Overview {
  schools: { total: number; active: number; trial: number; suspended: number };
  students: { total: number; active: number };
  staff: { total: number };
  school_fee_flow: {
    collected: number;
    invoiced: number;
    recovery_rate_percentage: number;
  };
  ai_spend_this_month: { cost_naira: number; calls: number; since: string };
  cbt: { attempts_all_time: number };
}

interface TenantRow {
  id: number;
  name: string;
  subdomain: string;
  email: string | null;
  status: 'active' | 'trial' | 'suspended';
  active_students: number;
  plan_band: string;
  ai_enabled: boolean;
  result_checker_enabled: boolean;
  fees_collected: number;
  ai_spend_naira_this_month: number;
}

const STATUS_TONE = {
  active: 'green',
  trial: 'blue',
  suspended: 'red',
} as const;

export default function SuperAdminConsole() {
  const router = useRouter();
  const [overview, setOverview] = useState<Overview | null>(null);
  const [schools, setSchools] = useState<TenantRow[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const loadSchools = useCallback(async (term: string) => {
    const query = term.trim() ? `?search=${encodeURIComponent(term.trim())}` : '';
    const res = await fetchApi<{ data: TenantRow[] }>(`/platform/schools${query}`);
    setSchools(res.data ?? []);
  }, []);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const [stats] = await Promise.all([
          fetchApi<Overview>('/platform/overview'),
          loadSchools(''),
        ]);

        if (!cancelled) setOverview(stats);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the operator console.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [loadSchools]);

  async function onSearch(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    try {
      await loadSchools(search);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That search could not be run.');
    }
  }

  /**
   * Enter one school. The session is scoped to it server-side, so every
   * school-side page then answers for that tenant.
   */
  async function inspect(school: TenantRow) {
    setBusyId(school.id);

    try {
      await viewSchool(school.id);
      router.push('/');
    } finally {
      setBusyId(null);
    }
  }

  async function setStatus(school: TenantRow, status: TenantRow['status']) {
    setBusyId(school.id);
    setError(null);

    try {
      await fetchApi(`/platform/schools/${school.id}/status`, {
        method: 'POST',
        body: JSON.stringify({ status }),
      });

      setSchools((prev) => prev.map((s) => (s.id === school.id ? { ...s, status } : s)));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <PageShell
      title="Operator console"
      subtitle="Every school on the platform, platform AI spend, and tenant status."
    >
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          {overview && (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <StatTile
                label="Schools"
                value={overview.schools.total.toLocaleString('en-NG')}
                hint={`${overview.schools.active} active · ${overview.schools.trial} trial · ${overview.schools.suspended} suspended`}
              />
              <StatTile
                label="Students across all schools"
                value={overview.students.active.toLocaleString('en-NG')}
                hint={`${overview.students.total.toLocaleString('en-NG')} records in total`}
              />
              {/*
                Named for what it is. This is money schools collect from
                parents, flowing through the platform — not SchoolPilot's
                revenue. The old page labelled it "Platform MRR".
              */}
              <StatTile
                label="School fees collected (all schools)"
                value={nairaAmount(overview.school_fee_flow.collected)}
                tone="green"
                hint={
                  overview.school_fee_flow.invoiced > 0
                    ? `${overview.school_fee_flow.recovery_rate_percentage}% of ${nairaAmount(overview.school_fee_flow.invoiced)} billed`
                    : 'Nothing billed yet'
                }
              />
              <StatTile
                label="AI spend this month"
                value={nairaAmount(overview.ai_spend_this_month.cost_naira)}
                tone="amber"
                hint={`${overview.ai_spend_this_month.calls.toLocaleString('en-NG')} call(s) since ${overview.ai_spend_this_month.since}`}
              />
            </div>
          )}

          <Card
            title="Schools"
            description="Open a school to work inside it; your session is scoped to that school until you leave it."
          >
            <form onSubmit={onSearch} className="mb-4 flex flex-wrap gap-2">
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search by name, subdomain or email"
                aria-label="Search schools"
                className={`${inputClass} max-w-sm`}
              />
              <Button type="submit" variant="secondary">
                Search
              </Button>
              {search && (
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => {
                    setSearch('');
                    loadSchools('').catch(() => undefined);
                  }}
                >
                  Clear
                </Button>
              )}
            </form>

            {schools.length === 0 ? (
              <Empty>
                {search
                  ? 'No school matches that search.'
                  : 'No schools yet. The first tenant appears here once it is created.'}
              </Empty>
            ) : (
              <Table
                columns={[
                  'School',
                  'Subdomain',
                  'Students',
                  'Plan band',
                  'Fees collected',
                  'AI spend',
                  'Status',
                  '',
                ]}
              >
                {schools.map((school) => (
                  <tr key={school.id}>
                    <Cell className="font-medium text-slate-900">
                      {school.name}
                      {school.email && (
                        <span className="block text-xs text-slate-500">{school.email}</span>
                      )}
                    </Cell>
                    <Cell className="font-mono text-xs">{school.subdomain}</Cell>
                    <Cell>{school.active_students.toLocaleString('en-NG')}</Cell>
                    <Cell className="text-xs">{school.plan_band}</Cell>
                    <Cell>{nairaAmount(school.fees_collected)}</Cell>
                    <Cell>
                      {school.ai_enabled ? nairaAmount(school.ai_spend_naira_this_month) : '—'}
                    </Cell>
                    <Cell>
                      <Badge tone={STATUS_TONE[school.status]}>{school.status}</Badge>
                    </Cell>
                    <Cell>
                      <div className="flex justify-end gap-2">
                        <Button
                          variant="secondary"
                          disabled={busyId === school.id}
                          onClick={() => inspect(school)}
                        >
                          Open
                        </Button>
                        {school.status === 'suspended' ? (
                          <Button
                            variant="secondary"
                            disabled={busyId === school.id}
                            onClick={() => setStatus(school, 'active')}
                          >
                            Reinstate
                          </Button>
                        ) : (
                          <Button
                            variant="danger"
                            disabled={busyId === school.id}
                            onClick={() => setStatus(school, 'suspended')}
                          >
                            Suspend
                          </Button>
                        )}
                      </div>
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}

            <p className="mt-3 text-xs text-slate-500">
              Suspending a school blocks access without deleting anything: the roll, results and
              payment history all stay as they are.
            </p>
          </Card>
        </div>
      )}
    </PageShell>
  );
}
