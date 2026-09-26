'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Assisted migrations: schools that asked SchoolPilot to bring their old
 * records across (doc §14 — free, within 24 hours).
 *
 * The queue existed only as an API, so a request landed in a table nobody
 * looked at and the 24-hour promise was kept by luck. Overdue requests come
 * first because the one that has already broken its promise matters most.
 */

type Status = 'submitted' | 'in_progress' | 'completed' | 'cancelled';

interface Migration {
  id: number;
  previous_system: string;
  source_format: string;
  notes: string | null;
  approximate_records: number | null;
  status: Status;
  requested_by: string | null;
  submitted_at: string | null;
  due_at: string | null;
  operator_notes: string | null;
  is_overdue: boolean;
  school: { id: number | null; name: string | null; subdomain: string | null };
}

const FORMAT_LABEL: Record<string, string> = {
  excel: 'Excel / spreadsheet',
  sql_dump: 'Database dump',
  competitor_portal: 'Another school portal',
  paper: 'Paper records',
  other: 'Other',
};

function when(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/** "in 5h" / "3h overdue", to the hour — the promise is counted in hours. */
function dueIn(iso: string | null): string {
  if (!iso) return 'No due time';
  const hours = Math.round((new Date(iso).getTime() - Date.now()) / 3_600_000);
  if (hours < 0) return `${-hours}h overdue`;
  return hours === 0 ? 'Due within the hour' : `Due in ${hours}h`;
}

export default function MigrationQueuePage() {
  const [rows, setRows] = useState<Migration[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [notes, setNotes] = useState<Record<number, string>>({});

  const load = useCallback(async () => {
    const res = await fetchApi<{ data: Migration[] }>('/platform/school-migrations');
    setRows(res.data);
    setNotes(Object.fromEntries(res.data.map((row) => [row.id, row.operator_notes ?? ''])));
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load the migration queue.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function update(row: Migration, status: Status) {
    if (status === 'cancelled' && !window.confirm(`Cancel ${row.school.name ?? 'this school'}'s migration request?`)) return;

    setBusyId(row.id);
    setError(null);
    setNotice(null);

    try {
      await fetchApi(`/platform/school-migrations/${row.id}`, {
        method: 'PUT',
        body: JSON.stringify({ status, operator_notes: notes[row.id]?.trim() || null }),
      });
      setNotice(
        status === 'completed'
          ? `${row.school.name ?? 'The school'}'s migration is marked complete.`
          : status === 'cancelled'
            ? 'Request cancelled.'
            : 'Saved.',
      );
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusyId(null);
    }
  }

  const overdue = rows.filter((row) => row.is_overdue).length;

  return (
    <PageShell
      title="Onboarding & migrations"
      subtitle="Schools waiting for SchoolPilot to import their old records. The promise is 24 hours from the request."
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>No open migration requests. New schools can request one from their setup checklist.</Empty>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-ink-muted">
            {rows.length} open request(s){overdue > 0 && <span className="font-semibold text-negative"> · {overdue} overdue</span>}
          </p>

          {rows.map((row) => (
            <Card key={row.id} className={row.is_overdue ? 'ring-2 ring-negative/40' : ''}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="font-headline text-base font-semibold text-brand">
                    {row.school.id ? (
                      <Link href={`/super-admin/schools/${row.school.id}`} className="hover:underline">
                        {row.school.name}
                      </Link>
                    ) : (
                      'Unknown school'
                    )}
                  </h2>
                  <p className="text-xs text-ink-muted">
                    From {row.previous_system} · {FORMAT_LABEL[row.source_format] ?? row.source_format}
                    {row.approximate_records ? ` · about ${row.approximate_records.toLocaleString('en-NG')} records` : ''}
                  </p>
                  <p className="mt-1 text-xs text-ink-faint">
                    Requested by {row.requested_by ?? 'a school user'} on {when(row.submitted_at)}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={row.status === 'in_progress' ? 'blue' : 'slate'}>
                    {row.status === 'in_progress' ? 'In progress' : 'Waiting'}
                  </Badge>
                  <Badge tone={row.is_overdue ? 'red' : 'amber'}>{dueIn(row.due_at)}</Badge>
                </div>
              </div>

              {row.notes && (
                <p className="mt-3 whitespace-pre-wrap rounded-lg bg-surface-sunken px-3 py-2 text-sm text-ink">
                  {row.notes}
                </p>
              )}

              <label className="mt-4 block">
                <span className="mb-1 block text-xs font-medium text-ink-muted">
                  Notes to the school (they see these)
                </span>
                <textarea
                  rows={2}
                  maxLength={2000}
                  value={notes[row.id] ?? ''}
                  onChange={(e) => setNotes((n) => ({ ...n, [row.id]: e.target.value }))}
                  className={inputClass}
                  placeholder="e.g. Received your spreadsheet; classes JSS1–SS3 imported, awaiting fee history."
                />
              </label>

              <div className="mt-3 flex flex-wrap gap-2">
                {row.status === 'submitted' && (
                  <Button variant="secondary" disabled={busyId === row.id} onClick={() => update(row, 'in_progress')}>
                    Start
                  </Button>
                )}
                {row.status === 'in_progress' && (
                  <Button variant="secondary" disabled={busyId === row.id} onClick={() => update(row, 'in_progress')}>
                    Save notes
                  </Button>
                )}
                <Button disabled={busyId === row.id} onClick={() => update(row, 'completed')}>
                  Mark complete
                </Button>
                <Button variant="secondary" disabled={busyId === row.id} onClick={() => update(row, 'cancelled')}>
                  Cancel request
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </PageShell>
  );
}
