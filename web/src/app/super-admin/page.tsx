'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import OpenSchoolButton from '@/components/OpenSchoolButton';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
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

interface Provisioned {
  message: string;
  school: { id: number; name: string; subdomain: string; status: string; sign_in_url: string };
  administrator: {
    name: string;
    email: string;
    temporary_password: string;
    setup_email_sent: boolean;
  };
}

const EMPTY_PROVISION = {
  name: '',
  subdomain: '',
  email: '',
  phone: '',
  status: 'trial',
  admin_name: '',
  admin_email: '',
};

/** A school's name as a hostname label: "St. Mary's College" -> "st-marys-college". */
function suggestSubdomain(name: string): string {
  return name
    .toLowerCase()
    .replace(/['\u2019]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);
}

const STATUS_TONE = {
  active: 'green',
  trial: 'blue',
  suspended: 'red',
} as const;

/**
 * `trial` is stored as-is (installed mobile apps read the value) but shown as
 * onboarding: there is no trial clock, because the core is free for good and
 * schools pay per use at result time (doc §13).
 */
const STATUS_LABEL = {
  active: 'active',
  trial: 'onboarding',
  suspended: 'suspended',
} as const;

export default function SuperAdminConsole() {
  const [overview, setOverview] = useState<Overview | null>(null);
  const [schools, setSchools] = useState<TenantRow[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const [showProvision, setShowProvision] = useState(false);
  const [provision, setProvision] = useState(EMPTY_PROVISION);
  // Whether the operator has typed their own subdomain; until then it follows
  // the school's name.
  const [subdomainEdited, setSubdomainEdited] = useState(false);
  const [provisioning, setProvisioning] = useState(false);
  const [provisionErrors, setProvisionErrors] = useState<Record<string, string[]>>({});
  const [provisioned, setProvisioned] = useState<Provisioned | null>(null);
  const [copied, setCopied] = useState(false);

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

  async function refresh() {
    const [stats] = await Promise.all([fetchApi<Overview>('/platform/overview'), loadSchools(search)]);
    setOverview(stats);
  }

  async function submitProvision(e: React.FormEvent) {
    e.preventDefault();
    setProvisioning(true);
    setProvisionErrors({});
    setError(null);

    try {
      const res = await fetchApi<Provisioned>('/platform/schools', {
        method: 'POST',
        body: JSON.stringify({ ...provision, phone: provision.phone.trim() || null }),
      });

      setProvisioned(res);
      setShowProvision(false);
      setProvision(EMPTY_PROVISION);
      setSubdomainEdited(false);
      await refresh();
    } catch (err) {
      // Field errors go beside their fields; anything else goes on top.
      const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;

      if (payload?.errors) {
        setProvisionErrors(payload.errors);
      } else {
        setError(err instanceof Error ? err.message : 'That school could not be created.');
      }
    } finally {
      setProvisioning(false);
    }
  }

  async function copyCredentials() {
    if (!provisioned) return;

    const text = [
      provisioned.school.name,
      `Sign in: ${provisioned.school.sign_in_url}`,
      `School code: ${provisioned.school.subdomain}`,
      `Email: ${provisioned.administrator.email}`,
      `Temporary password: ${provisioned.administrator.temporary_password}`,
    ].join('\n');

    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard can be blocked; the details are on screen to copy by hand.
    }
  }

  async function toggleAi(school: TenantRow) {
    setBusyId(school.id);
    setError(null);

    try {
      await fetchApi(`/platform/schools/${school.id}/ai`, {
        method: 'POST',
        body: JSON.stringify({ enabled: !school.ai_enabled }),
      });

      setSchools((prev) =>
        prev.map((row) => (row.id === school.id ? { ...row, ai_enabled: !school.ai_enabled } : row)),
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusyId(null);
    }
  }

  const fieldError = (name: string) =>
    provisionErrors[name]?.[0] ? (
      <span className="mt-1 block text-xs text-negative">{provisionErrors[name][0]}</span>
    ) : null;

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
      title="Schools"
      subtitle="Every school on the platform, platform AI spend, and tenant status."
      actions={
        <div className="flex gap-2">
          <Button
            variant="accent"
            onClick={() => {
              setShowProvision((v) => !v);
              setProvisioned(null);
            }}
          >
            {showProvision ? 'Cancel' : 'New school'}
          </Button>
        </div>
      }
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
                hint={`${overview.schools.active} active · ${overview.schools.trial} onboarding · ${overview.schools.suspended} suspended`}
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

          {provisioned && (
            <Card
              title={`${provisioned.school.name} is ready`}
              description="Send these to the school's administrator. The temporary password is shown only now and cannot be retrieved later."
              className="ring-2 ring-accent"
            >
              <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                  <dt className="text-ink-faint">Sign-in address</dt>
                  <dd className="font-medium text-brand">{provisioned.school.sign_in_url}</dd>
                </div>
                <div>
                  <dt className="text-ink-faint">School code</dt>
                  <dd className="font-mono">{provisioned.school.subdomain}</dd>
                </div>
                <div>
                  <dt className="text-ink-faint">Administrator</dt>
                  <dd>
                    {provisioned.administrator.name} · {provisioned.administrator.email}
                  </dd>
                </div>
                <div>
                  <dt className="text-ink-faint">Temporary password</dt>
                  <dd className="font-mono text-base">{provisioned.administrator.temporary_password}</dd>
                </div>
              </dl>

              <p className="mt-4 text-xs text-ink-muted">
                {provisioned.administrator.setup_email_sent
                  ? 'A setup link was also emailed to the administrator.'
                  : 'No setup email was sent because mail is not configured yet. Send these details yourself.'}
              </p>

              <div className="mt-4 flex gap-2">
                <Button onClick={copyCredentials}>{copied ? 'Copied' : 'Copy details'}</Button>
                <Button variant="secondary" onClick={() => setProvisioned(null)}>
                  Done
                </Button>
              </div>
            </Card>
          )}

          {showProvision && (
            <Card
              title="New school"
              description="Creates the school and its first administrator together. It starts as onboarding unless you say otherwise."
            >
              <form onSubmit={submitProvision} className="grid gap-4 sm:grid-cols-2">
                <Field label="School name">
                  <input
                    required
                    value={provision.name}
                    onChange={(e) => {
                      const name = e.target.value;
                      setProvision((p) => ({
                        ...p,
                        name,
                        subdomain: subdomainEdited ? p.subdomain : suggestSubdomain(name),
                      }));
                    }}
                    className={inputClass}
                    placeholder="e.g. Sunrise Academy"
                  />
                  {fieldError('name')}
                </Field>

                <Field label="School code (subdomain)">
                  <div className="flex items-center">
                    <input
                      required
                      value={provision.subdomain}
                      onChange={(e) => {
                        setSubdomainEdited(true);
                        setProvision((p) => ({ ...p, subdomain: e.target.value.toLowerCase() }));
                      }}
                      className={`${inputClass} rounded-r-none`}
                      placeholder="sunrise"
                    />
                    <span className="rounded-r-lg border border-l-0 border-line bg-surface-sunken px-3 py-2 text-sm text-ink-muted">
                      .schoolpilot.org.ng
                    </span>
                  </div>
                  {fieldError('subdomain')}
                </Field>

                <Field label="School email">
                  <input
                    required
                    type="email"
                    value={provision.email}
                    onChange={(e) => setProvision((p) => ({ ...p, email: e.target.value }))}
                    className={inputClass}
                  />
                  {fieldError('email')}
                </Field>

                <Field label="Phone (optional)">
                  <input
                    value={provision.phone}
                    onChange={(e) => setProvision((p) => ({ ...p, phone: e.target.value }))}
                    className={inputClass}
                  />
                </Field>

                <Field label="Administrator's name">
                  <input
                    required
                    value={provision.admin_name}
                    onChange={(e) => setProvision((p) => ({ ...p, admin_name: e.target.value }))}
                    className={inputClass}
                    placeholder="The proprietor or head of school"
                  />
                  {fieldError('admin_name')}
                </Field>

                <Field label="Administrator's email">
                  <input
                    required
                    type="email"
                    value={provision.admin_email}
                    onChange={(e) => setProvision((p) => ({ ...p, admin_email: e.target.value }))}
                    className={inputClass}
                  />
                  {fieldError('admin_email')}
                </Field>

                <Field label="Status">
                  <select
                    value={provision.status}
                    onChange={(e) => setProvision((p) => ({ ...p, status: e.target.value }))}
                    className={inputClass}
                  >
                    <option value="trial">Onboarding</option>
                    <option value="active">Active</option>
                  </select>
                </Field>

                <div className="flex items-end sm:col-span-2">
                  <Button type="submit" variant="accent" disabled={provisioning}>
                    {provisioning ? 'Creating…' : 'Create school'}
                  </Button>
                </div>
              </form>
            </Card>
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
                    <Cell className="font-medium text-brand">
                      <Link href={`/super-admin/schools/${school.id}`} className="hover:underline">
                        {school.name}
                      </Link>
                      {school.email && (
                        <span className="block text-xs text-ink-muted">{school.email}</span>
                      )}
                    </Cell>
                    <Cell className="font-mono text-xs">{school.subdomain}</Cell>
                    <Cell>{school.active_students.toLocaleString('en-NG')}</Cell>
                    <Cell className="text-xs">{school.plan_band}</Cell>
                    <Cell>{nairaAmount(school.fees_collected)}</Cell>
                    <Cell>
                      <div className="flex items-center gap-2">
                        <span>{school.ai_enabled ? nairaAmount(school.ai_spend_naira_this_month) : 'Off'}</span>
                        <button
                          onClick={() => toggleAi(school)}
                          disabled={busyId === school.id}
                          className="text-xs font-medium text-info underline hover:text-brand disabled:opacity-60"
                          aria-label={`${school.ai_enabled ? 'Turn AI off' : 'Turn AI on'} for ${school.name}`}
                        >
                          {school.ai_enabled ? 'Turn off' : 'Turn on'}
                        </button>
                      </div>
                    </Cell>
                    <Cell>
                      <Badge tone={STATUS_TONE[school.status]}>{STATUS_LABEL[school.status]}</Badge>
                    </Cell>
                    <Cell>
                      <div className="flex justify-end gap-2">
                        <OpenSchoolButton schoolId={school.id} schoolName={school.name} />
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

            <p className="mt-3 text-xs text-ink-muted">
              Suspending a school blocks access without deleting anything: the roll, results and
              payment history all stay as they are.
            </p>
          </Card>
        </div>
      )}
    </PageShell>
  );
}
