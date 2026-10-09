'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { fetchApi } from '@/lib/api';
import OpenSchoolButton from '@/components/OpenSchoolButton';
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
 * One school, from the operator's side: enough to answer a support call
 * without opening the school and wandering its screens.
 *
 * What the school has paid SchoolPilot is its result-PIN batches — the §13
 * "charge at result generation" — so that is the billing history shown here.
 * Term subscriptions are not recorded yet (see docs/super-admin-menu.md).
 */

interface Detail {
  school: {
    id: number;
    name: string;
    subdomain: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    status: 'active' | 'trial' | 'suspended';
    ai_enabled: boolean;
    result_checker_enabled: boolean;
    plan_band: string;
    created_at: string | null;
  };
  students: { active: number; total: number };
  staff_by_role: Record<string, number>;
  fee_flow: { collected: number; invoiced: number; outstanding: number };
  ai_spend_naira_this_month: number;
}

interface Note {
  id: number;
  body: string;
  author: string | null;
  created_at: string | null;
}

interface Batch {
  id: number;
  reference: string;
  quantity: number;
  unit_price: string;
  total_amount: string;
  source: string;
  gateway: string | null;
  status: string;
  created_at: string;
}

/** The school's module switches, and the AI features it chose for itself (read-only here). */
interface Features {
  modules: { key: string; label: string; group: string; enabled: boolean }[];
  access: {
    status: 'active' | 'trial' | 'suspended';
    billing_plan: string | null;
    plans: Record<string, string>;
    email_enabled: boolean;
    email_daily_limit: number | null;
  } | null;
  ai: { available: boolean; features: { key: string; label: string; chosen: boolean; active: boolean }[] };
}

interface AccessRow {
  id: number;
  operator: { name: string } | null;
  reason: string;
  opened_at: string | null;
  ended_at: string | null;
  is_live: boolean;
}

interface AuditRow {
  id: number;
  at: string | null;
  action: string;
  actor: { name: string } | null;
  changed_fields: string[];
}

const STATUS_TONE = { active: 'green', trial: 'blue', suspended: 'red' } as const;

// Stored as `trial`, shown as onboarding: there is no trial clock (doc §13).
const STATUS_LABEL = { active: 'active', trial: 'onboarding', suspended: 'suspended' } as const;

const ROLE_LABEL: Record<string, string> = {
  school_admin: 'Administrators',
  teacher: 'Teachers',
  student: 'Student accounts',
  parent: 'Parent accounts',
};

function when(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

export default function SchoolDetailPage() {
  const params = useParams<{ id: string }>();
  const schoolId = Number(params.id);

  const [detail, setDetail] = useState<Detail | null>(null);
  const [notes, setNotes] = useState<Note[]>([]);
  const [batches, setBatches] = useState<Batch[]>([]);
  const [activity, setActivity] = useState<AuditRow[]>([]);
  const [access, setAccess] = useState<AccessRow[]>([]);
  const [features, setFeatures] = useState<Features | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState('');
  const [posting, setPosting] = useState(false);

  const load = useCallback(async () => {
    const [d, n, b, a, g, f] = await Promise.all([
      fetchApi<Detail>(`/platform/schools/${schoolId}`),
      fetchApi<{ data: Note[] }>(`/platform/schools/${schoolId}/notes`),
      fetchApi<{ batches: Batch[] }>(`/platform/result-pins/batches?school_id=${schoolId}`).catch(() => ({ batches: [] })),
      fetchApi<{ data: AuditRow[] }>(`/platform/audit-log?school_id=${schoolId}`).catch(() => ({ data: [] })),
      fetchApi<{ data: AccessRow[] }>(`/platform/access-log?school_id=${schoolId}`).catch(() => ({ data: [] })),
      fetchApi<Features>(`/platform/schools/${schoolId}/features`).catch(() => null),
    ]);

    setDetail(d);
    setNotes(n.data);
    setBatches(b.batches);
    setActivity(a.data.slice(0, 10));
    setAccess(g.data.slice(0, 10));
    setFeatures(f);
  }, [schoolId]);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        await load();
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Could not load this school.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [load]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const res = (await action()) as { message?: string } | undefined;
      if (res?.message) setNotice(res.message);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusy(false);
    }
  }

  async function postNote(e: React.FormEvent) {
    e.preventDefault();
    if (!draft.trim()) return;

    setPosting(true);
    setError(null);

    try {
      const res = await fetchApi<{ data: Note }>(`/platform/schools/${schoolId}/notes`, {
        method: 'POST',
        body: JSON.stringify({ body: draft }),
      });
      setNotes((prev) => [res.data, ...prev]);
      setDraft('');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That note could not be saved.');
    } finally {
      setPosting(false);
    }
  }

  const school = detail?.school;

  return (
    <PageShell
      title={school?.name ?? 'School'}
      subtitle={school ? `${school.subdomain}.schoolpilot.org.ng · ${school.plan_band}` : undefined}
      actions={
        school && (
          <div className="flex flex-wrap gap-2">
            <Link href="/super-admin">
              <Button variant="secondary">All schools</Button>
            </Link>
            <OpenSchoolButton schoolId={schoolId} schoolName={school.name} label="Open school" />
            {school.status === 'trial' && (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  run(() =>
                    fetchApi(`/platform/schools/${schoolId}/status`, {
                      method: 'POST',
                      body: JSON.stringify({ status: 'active' }),
                    }),
                  )
                }
              >
                Mark as active
              </Button>
            )}
            {school.status === 'suspended' ? (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  run(() =>
                    fetchApi(`/platform/schools/${schoolId}/status`, {
                      method: 'POST',
                      body: JSON.stringify({ status: 'active' }),
                    }),
                  )
                }
              >
                Reinstate
              </Button>
            ) : (
              <Button
                variant="danger"
                disabled={busy}
                onClick={() => {
                  if (!window.confirm(`Suspend ${school.name}? Nobody at the school can sign in until it is reinstated. Nothing is deleted.`)) return;
                  run(() =>
                    fetchApi(`/platform/schools/${schoolId}/status`, {
                      method: 'POST',
                      body: JSON.stringify({ status: 'suspended' }),
                    }),
                  );
                }}
              >
                Suspend
              </Button>
            )}
          </div>
        )
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
      ) : !detail || !school ? (
        <Empty>This school could not be found.</Empty>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile
              label="Active students"
              value={detail.students.active.toLocaleString('en-NG')}
              hint={`${detail.students.total.toLocaleString('en-NG')} records in total`}
            />
            <StatTile
              label="School fees collected"
              value={nairaAmount(detail.fee_flow.collected)}
              tone="green"
              hint={`${nairaAmount(detail.fee_flow.outstanding)} outstanding from parents`}
            />
            <StatTile
              label="AI spend this month"
              value={school.ai_enabled ? nairaAmount(detail.ai_spend_naira_this_month) : 'AI off'}
              tone="amber"
            />
            <StatTile
              label="Status"
              value={<Badge tone={STATUS_TONE[school.status]}>{STATUS_LABEL[school.status]}</Badge>}
              hint={school.created_at ? `On SchoolPilot since ${new Date(school.created_at).toLocaleDateString('en-NG', { dateStyle: 'medium' })}` : undefined}
            />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card title="School">
              <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                  <dt className="text-ink-faint">Email</dt>
                  <dd>{school.email ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-ink-faint">Phone</dt>
                  <dd>{school.phone ?? '—'}</dd>
                </div>
                <div className="sm:col-span-2">
                  <dt className="text-ink-faint">Address</dt>
                  <dd>{school.address ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-ink-faint">AI features</dt>
                  <dd className="flex items-center gap-2">
                    {school.ai_enabled ? 'On' : 'Off'}
                    <button
                      disabled={busy}
                      onClick={() =>
                        run(() =>
                          fetchApi(`/platform/schools/${schoolId}/ai`, {
                            method: 'POST',
                            body: JSON.stringify({ enabled: !school.ai_enabled }),
                          }),
                        )
                      }
                      className="text-xs font-medium text-info underline hover:text-brand disabled:opacity-60"
                    >
                      {school.ai_enabled ? 'Turn off' : 'Turn on'}
                    </button>
                  </dd>
                </div>
                <div>
                  <dt className="text-ink-faint">Result checker</dt>
                  <dd>{school.result_checker_enabled ? 'On' : 'Off'}</dd>
                </div>
              </dl>
            </Card>

            <Card title="Accounts">
              {Object.keys(detail.staff_by_role).length === 0 ? (
                <Empty>No accounts yet.</Empty>
              ) : (
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                  {Object.entries(detail.staff_by_role).map(([role, count]) => (
                    <div key={role}>
                      <dt className="text-ink-faint">{ROLE_LABEL[role] ?? role}</dt>
                      <dd className="font-headline text-lg font-semibold text-brand">
                        {Number(count).toLocaleString('en-NG')}
                      </dd>
                    </div>
                  ))}
                </dl>
              )}
              <p className="mt-4 text-xs text-ink-muted">
                <Link href={`/super-admin/security?school_id=${schoolId}`} className="underline hover:text-brand">
                  Administrator 2FA and sessions
                </Link>
              </p>
            </Card>
          </div>

          {features && (
            <Card
              title="Access & features"
              description="Everything this school can use, in one place. Change any of it at any time, not only at onboarding. A module switched off disappears from the school's menu and its screens refuse; nothing is deleted, and switching it back on restores everything."
            >
              {features.access && (
                <div className="grid gap-4 border-b border-line-soft pb-5 sm:grid-cols-2 xl:grid-cols-4">
                  <label className="block text-sm">
                    <span className="mb-1 block font-medium text-ink">Account</span>
                    <select
                      className="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm"
                      value={features.access.status}
                      disabled={busy}
                      onChange={(e) => {
                        const status = e.target.value;
                        if (status === 'suspended' && !window.confirm(`Suspend ${school.name}? Nobody at the school can sign in until you reactivate it.`)) return;
                        run(() => fetchApi(`/platform/schools/${schoolId}/status`, { method: 'POST', body: JSON.stringify({ status }) }));
                      }}
                    >
                      <option value="trial">Onboarding</option>
                      <option value="active">Active</option>
                      <option value="suspended">Suspended</option>
                    </select>
                  </label>
                  <label className="block text-sm">
                    <span className="mb-1 block font-medium text-ink">Plan</span>
                    <select
                      className="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm"
                      value={features.access.billing_plan ?? ''}
                      disabled={busy}
                      onChange={(e) => run(() => fetchApi(`/platform/schools/${schoolId}/billing-plan`, { method: 'PUT', body: JSON.stringify({ plan: e.target.value || null }) }))}
                    >
                      <option value="">No plan yet</option>
                      {Object.entries(features.access.plans).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                    </select>
                  </label>
                  <div className="text-sm">
                    <span className="mb-1 block font-medium text-ink">AI</span>
                    <Switch
                      on={features.ai.available}
                      label={`AI for ${school.name}`}
                      disabled={busy}
                      onToggle={() => run(() => fetchApi(`/platform/schools/${schoolId}/ai`, { method: 'POST', body: JSON.stringify({ enabled: !features.ai.available }) }))}
                    />
                    {features.ai.available && (
                      <span className="mt-1 block text-xs text-ink-muted">
                        School chose: {features.ai.features.filter((ai) => ai.chosen).map((ai) => ai.label).join(', ') || 'none yet'}
                      </span>
                    )}
                  </div>
                  <div className="text-sm">
                    <span className="mb-1 block font-medium text-ink">Email sending</span>
                    <div className="flex items-center gap-2">
                      <Switch
                        on={features.access.email_enabled}
                        label={`Email for ${school.name}`}
                        disabled={busy}
                        onToggle={() => run(() => fetchApi(`/platform/schools/${schoolId}/messaging`, { method: 'POST', body: JSON.stringify({ email_enabled: !features.access?.email_enabled }) }))}
                      />
                      {features.access.email_enabled && (
                        <input
                          type="number"
                          min={0}
                          aria-label="Daily email limit"
                          placeholder="Default"
                          defaultValue={features.access.email_daily_limit ?? ''}
                          disabled={busy}
                          onBlur={(e) => {
                            const next = e.target.value === '' ? null : Number(e.target.value);
                            if (next === (features.access?.email_daily_limit ?? null)) return;
                            run(() => fetchApi(`/platform/schools/${schoolId}/messaging`, { method: 'POST', body: JSON.stringify({ email_daily_limit: next }) }));
                          }}
                          className="w-28 rounded-lg border border-line bg-surface px-2 py-1 text-xs"
                        />
                      )}
                    </div>
                    <span className="mt-1 block text-xs text-ink-muted">Emails per day; empty uses the platform default.</span>
                  </div>
                </div>
              )}

              <div className="mt-5 space-y-5">
                {Array.from(new Set(features.modules.map((m) => m.group))).map((group) => (
                  <div key={group}>
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-faint">{group}</p>
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                      {features.modules.filter((m) => m.group === group).map((module) => (
                        <li key={module.key} className="flex items-center justify-between gap-3 rounded-lg border border-line-soft px-3 py-2">
                          <span className="text-sm text-ink">{module.label}</span>
                          <Switch
                            on={module.enabled}
                            label={`${module.label} for ${school.name}`}
                            disabled={busy}
                            onToggle={() => {
                              if (module.enabled && !window.confirm(`Switch ${module.label} off for ${school.name}? Its screens stop working for the school until you switch it back on.`)) return;
                              run(() =>
                                fetchApi(`/platform/schools/${schoolId}/features`, {
                                  method: 'PUT',
                                  body: JSON.stringify({ modules: { [module.key]: !module.enabled } }),
                                }),
                              );
                            }}
                          />
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
              <p className="mt-4 text-xs text-ink-muted">
                Result PINs off: families open report cards free, and nobody can buy or sell a PIN. The school store also has the school&apos;s own on/off; this switch decides whether it may use it at all.
              </p>
            </Card>
          )}

          <Card
            title="Operator access"
            description="Every time someone at SchoolPilot opened this school, with the reason they gave. Latest ten."
          >
            {access.length === 0 ? (
              <Empty>No operator has opened this school.</Empty>
            ) : (
              <Table columns={['Opened', 'By', 'Reason', 'Ended']}>
                {access.map((row) => (
                  <tr key={row.id}>
                    <Cell className="whitespace-nowrap text-xs">{when(row.opened_at)}</Cell>
                    <Cell>{row.operator?.name ?? '—'}</Cell>
                    <Cell className="text-sm">{row.reason}</Cell>
                    <Cell className="whitespace-nowrap text-xs">
                      {row.is_live ? <Badge tone="amber">Open now</Badge> : when(row.ended_at)}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          <Card
            title="Internal support notes"
            description="Calls, promises and special arrangements. Only SchoolPilot operators see these; the school never does."
          >
            <form onSubmit={postNote} className="space-y-2">
              <textarea
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                rows={3}
                maxLength={5000}
                placeholder="e.g. Proprietor called about term 2 PINs; promised a callback Monday."
                className={inputClass}
                aria-label="New note"
              />
              <Button type="submit" disabled={posting || !draft.trim()}>
                {posting ? 'Saving…' : 'Add note'}
              </Button>
            </form>

            {notes.length > 0 && (
              <ul className="mt-5 divide-y divide-line-soft">
                {notes.map((note) => (
                  <li key={note.id} className="py-3">
                    <p className="whitespace-pre-wrap text-sm text-ink">{note.body}</p>
                    <p className="mt-1 text-xs text-ink-faint">
                      {note.author ?? 'Former operator'} · {when(note.created_at)}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card
            title="Result PIN purchases"
            description="What this school has paid SchoolPilot. Term subscriptions are not recorded yet."
          >
            {batches.length === 0 ? (
              <Empty>No PIN batches yet.</Empty>
            ) : (
              <Table columns={['Date', 'Reference', 'PINs', 'Unit price', 'Total', 'How paid', 'Status']}>
                {batches.map((batch) => (
                  <tr key={batch.id}>
                    <Cell>{new Date(batch.created_at).toLocaleDateString('en-NG', { dateStyle: 'medium' })}</Cell>
                    <Cell className="font-mono text-xs">{batch.reference}</Cell>
                    <Cell>{batch.quantity.toLocaleString('en-NG')}</Cell>
                    <Cell>{nairaAmount(Number(batch.unit_price))}</Cell>
                    <Cell className="font-medium">{nairaAmount(Number(batch.total_amount))}</Cell>
                    <Cell className="text-xs">
                      {batch.source === 'manual_grant' ? `Offline (${batch.gateway ?? 'transfer'})` : (batch.gateway ?? 'Online')}
                    </Cell>
                    <Cell>
                      <Badge tone={batch.status === 'active' ? 'green' : 'slate'}>{batch.status}</Badge>
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          <Card title="Recent activity" description="The last ten audited actions in or on this school.">
            {activity.length === 0 ? (
              <Empty>Nothing audited yet.</Empty>
            ) : (
              <Table columns={['When', 'Action', 'By', 'Fields']}>
                {activity.map((row) => (
                  <tr key={row.id}>
                    <Cell className="whitespace-nowrap text-xs">{when(row.at)}</Cell>
                    <Cell className="font-mono text-xs">{row.action}</Cell>
                    <Cell>{row.actor?.name ?? '—'}</Cell>
                    <Cell className="text-xs text-ink-muted">{row.changed_fields.join(', ') || '—'}</Cell>
                  </tr>
                ))}
              </Table>
            )}
            <p className="mt-3 text-xs text-ink-muted">
              <Link href={`/super-admin/audit-log?school_id=${schoolId}`} className="underline hover:text-brand">
                Full audit log for this school
              </Link>
            </p>
          </Card>
        </div>
      )}
    </PageShell>
  );
}

/** An on/off pill with switch semantics. */
function Switch({ on, label, disabled, onToggle }: { on: boolean; label: string; disabled?: boolean; onToggle: () => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      disabled={disabled}
      onClick={onToggle}
      className={`shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition disabled:opacity-60 ${on ? 'bg-positive-soft text-positive' : 'bg-surface-raised text-ink-muted'}`}
    >
      {on ? 'On' : 'Off'}
    </button>
  );
}
