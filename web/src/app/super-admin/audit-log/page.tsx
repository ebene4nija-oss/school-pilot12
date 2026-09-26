'use client';

import React, { Suspense, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { fetchApi } from '@/lib/api';
import { Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * The audit trail across every school.
 *
 * Platform actions (`platform.*`) show what changed from and to. School-side
 * rows show only which fields changed: their values are a child's personal
 * data, and reviewing "who did what" does not need them (NDPA, doc §12). The
 * record itself is reached by opening the school.
 */

interface Row {
  id: number;
  at: string | null;
  action: string;
  school: { id: number; name: string; subdomain: string } | null;
  actor: { id: number; name: string; email: string } | null;
  record: string;
  changed_fields: string[];
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
}

interface Meta {
  current_page: number;
  last_page: number;
  total: number;
}

interface SchoolOption {
  id: number;
  name: string;
}

const EMPTY_FILTERS = { scope: '', school_id: '', action: '', from: '', to: '' };

function show(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function AuditLogScreen() {
  const searchParams = useSearchParams();
  const [rows, setRows] = useState<Row[]>([]);
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 });
  const [schools, setSchools] = useState<SchoolOption[]>([]);
  // Arriving from a school's page pre-filters to it.
  const [filters, setFilters] = useState(() => ({ ...EMPTY_FILTERS, school_id: searchParams.get('school_id') ?? '' }));
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);

  useEffect(() => {
    fetchApi<{ data: SchoolOption[] }>('/platform/schools')
      .then((res) => setSchools(res.data))
      .catch(() => undefined);
  }, []);

  // Nothing here sets state before the first await (react-hooks/set-state-in-effect).
  // The loading flag is raised by the callers that change the query instead.
  const load = useCallback(async (applied: typeof EMPTY_FILTERS, pageNo: number) => {
    const params = new URLSearchParams({ page: String(pageNo) });
    for (const [key, value] of Object.entries(applied)) {
      if (value) params.set(key, value);
    }

    try {
      const res = await fetchApi<{ data: Row[]; meta: Meta }>(`/platform/audit-log?${params}`);
      setRows(res.data);
      setMeta(res.meta);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the audit log.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load(filters, page);
    })();
    // Filters apply on submit; page changes apply immediately.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, load]);

  function requery(applied: typeof EMPTY_FILTERS, pageNo: number) {
    setLoading(true);
    if (pageNo === page) void load(applied, pageNo);
    else setPage(pageNo);
  }

  function apply(e: React.FormEvent) {
    e.preventDefault();
    requery(filters, 1);
  }

  return (
    <PageShell
      title="Audit log"
      subtitle="Every audited action across SchoolPilot, newest first. Values are shown for platform actions only."
    >
      <ErrorNote message={error} />

      <div className="space-y-6">
        <Card>
          <form onSubmit={apply} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <Field label="Kind">
              <select
                value={filters.scope}
                onChange={(e) => setFilters((f) => ({ ...f, scope: e.target.value }))}
                className={inputClass}
              >
                <option value="">Everything</option>
                <option value="platform">Operator actions</option>
                <option value="school">School activity</option>
              </select>
            </Field>
            <Field label="School">
              <select
                value={filters.school_id}
                onChange={(e) => setFilters((f) => ({ ...f, school_id: e.target.value }))}
                className={inputClass}
              >
                <option value="">All schools</option>
                {schools.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Action starts with">
              <input
                value={filters.action}
                onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))}
                placeholder="e.g. platform.settings"
                className={inputClass}
              />
            </Field>
            <Field label="From">
              <input
                type="date"
                value={filters.from}
                onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))}
                className={inputClass}
              />
            </Field>
            <Field label="To">
              <input
                type="date"
                value={filters.to}
                onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))}
                className={inputClass}
              />
            </Field>
            <div className="flex items-end gap-2">
              <Button type="submit">Apply</Button>
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setFilters(EMPTY_FILTERS);
                  requery(EMPTY_FILTERS, 1);
                }}
              >
                Clear
              </Button>
            </div>
          </form>
        </Card>

        <Card>
          {loading ? (
            <Loading />
          ) : rows.length === 0 ? (
            <Empty>Nothing matches those filters.</Empty>
          ) : (
            <>
              <p className="mb-3 text-xs text-ink-muted">{meta.total.toLocaleString('en-NG')} entries</p>
              <Table columns={['When', 'Action', 'School', 'By', 'Changed', '']}>
                {rows.map((row) => (
                  <React.Fragment key={row.id}>
                    <tr>
                      <Cell className="whitespace-nowrap text-xs">
                        {row.at ? new Date(row.at).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}
                      </Cell>
                      <Cell className="font-mono text-xs">{row.action}</Cell>
                      <Cell>
                        {row.school ? (
                          <Link href={`/super-admin/schools/${row.school.id}`} className="text-brand hover:underline">
                            {row.school.name}
                          </Link>
                        ) : (
                          <span className="text-ink-faint">Platform</span>
                        )}
                      </Cell>
                      <Cell>
                        {row.actor?.name ?? '—'}
                        {row.ip_address && <span className="block text-xs text-ink-faint">{row.ip_address}</span>}
                      </Cell>
                      <Cell className="text-xs text-ink-muted">{row.changed_fields.join(', ') || '—'}</Cell>
                      <Cell className="text-right">
                        {(row.old_values || row.new_values) && (
                          <button
                            onClick={() => setExpanded((id) => (id === row.id ? null : row.id))}
                            className="text-xs font-medium text-info underline hover:text-brand"
                          >
                            {expanded === row.id ? 'Hide' : 'Details'}
                          </button>
                        )}
                      </Cell>
                    </tr>
                    {expanded === row.id && (
                      <tr>
                        <td colSpan={6} className="bg-surface-sunken px-3 py-3">
                          <p className="mb-2 text-xs text-ink-faint">{row.record}</p>
                          <table className="text-xs">
                            <tbody>
                              {row.changed_fields.map((field) => (
                                <tr key={field}>
                                  <td className="pr-4 font-mono text-ink-muted">{field}</td>
                                  <td className="pr-4 text-ink-faint">{show(row.old_values?.[field])}</td>
                                  <td className="pr-2 text-ink-faint">→</td>
                                  <td className="text-ink">{show(row.new_values?.[field])}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </Table>

              {meta.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm">
                  <Button variant="secondary" disabled={page <= 1} onClick={() => requery(filters, page - 1)}>
                    Newer
                  </Button>
                  <span className="text-ink-muted">
                    Page {meta.current_page} of {meta.last_page}
                  </span>
                  <Button variant="secondary" disabled={page >= meta.last_page} onClick={() => requery(filters, page + 1)}>
                    Older
                  </Button>
                </div>
              )}
            </>
          )}
        </Card>
      </div>
    </PageShell>
  );
}

// useSearchParams needs a Suspense boundary for the page to prerender.
export default function AuditLogPage() {
  return (
    <Suspense fallback={null}>
      <AuditLogScreen />
    </Suspense>
  );
}
