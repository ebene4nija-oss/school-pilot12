'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * Staff attendance for the admin: who clocked in, who was late, who missed
 * days. Every row comes from a phone GPS clock-in checked against the school's
 * clock-in location (doc §3 — no biometric reader).
 */

interface StaffRow {
  staff_id: number;
  name: string;
  designation: string | null;
  days_in: number;
  days_late: number;
  days_absent: number;
  days_on_leave: number;
  last_clock_in: string | null;
}

interface Report {
  from: string;
  to: string;
  resume_time: string;
  location_configured: boolean;
  school_days: number;
  summary: { staff: number; days_in: number; days_late: number; days_absent: number };
  staff: StaffRow[];
}

interface Day { date: string; status: string; clocked_in_at: string | null }

const DAY_LABEL: Record<string, [string, 'green' | 'amber' | 'red' | 'slate' | 'blue']> = {
  present: ['On time', 'green'],
  late: ['Late', 'amber'],
  absent: ['Missed', 'red'],
  on_leave: ['On leave', 'blue'],
  holiday: ['Holiday', 'slate'],
  not_employed: ['Before start date', 'slate'],
  not_yet: ['Not in yet', 'slate'],
};

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function preset(kind: 'today' | 'week' | 'month'): [string, string] {
  const today = new Date();
  if (kind === 'today') return [iso(today), iso(today)];
  if (kind === 'month') return [iso(new Date(today.getFullYear(), today.getMonth(), 1)), iso(today)];
  const monday = new Date(today);
  monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
  return [iso(monday), iso(today)];
}

const time = (value: string | null) =>
  value ? new Date(value).toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Lagos' }) : '—';

const dateLabel = (value: string) =>
  new Date(`${value}T12:00:00`).toLocaleDateString('en-NG', { weekday: 'short', day: 'numeric', month: 'short' });

export default function StaffAttendancePage() {
  const [[from, to], setRange] = useState<[string, string]>(() => preset('week'));
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const [days, setDays] = useState<Day[] | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setReport(await fetchApi<Report>(`/attendance/staff?from=${from}&to=${to}`));
    } catch (err) {
      setError(errorText(err, 'Could not load staff attendance.'));
    }
  }, [from, to]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function toggle(staffId: number) {
    if (open === staffId) {
      setOpen(null);
      return;
    }
    setOpen(staffId);
    setDays(null);
    try {
      setDays((await fetchApi<{ days: Day[] }>(`/attendance/staff/${staffId}?from=${from}&to=${to}`)).days);
    } catch (err) {
      setError(errorText(err, 'Could not load that person’s days.'));
    }
  }

  function exportCsv() {
    if (!report) return;
    const quote = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [
      ['Name', 'Designation', 'Days in', 'Late', 'Missed', 'On leave', 'Last clock-in'].map(quote).join(','),
      ...report.staff.map((s) =>
        [s.name, s.designation, s.days_in, s.days_late, s.days_absent, s.days_on_leave, s.last_clock_in ? `${s.last_clock_in.slice(0, 10)} ${time(s.last_clock_in)}` : '']
          .map(quote)
          .join(','),
      ),
    ];
    const url = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `staff-attendance-${report.from}-to-${report.to}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  const single = from === to;
  const sorted = [...(report?.staff ?? [])].sort((a, b) => b.days_absent - a.days_absent || b.days_late - a.days_late || a.name.localeCompare(b.name));

  return (
    <PageShell
      title="Staff attendance"
      subtitle="From staff phone clock-ins, checked against the school's location."
      actions={
        <div className="flex gap-2">
          <Link href="/attendance/clock-in-location" className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-sunken">
            Clock-in location
          </Link>
          <Button variant="secondary" onClick={exportCsv} disabled={!report?.staff.length}>Export CSV</Button>
        </div>
      }
    >
      <ErrorNote message={error} />

      {report && !report.location_configured && (
        <p role="alert" className="mb-6 rounded-lg border border-negative/20 bg-negative-soft px-4 py-3 text-sm text-negative">
          The school&apos;s clock-in location is not set, so staff cannot clock in.{' '}
          <Link href="/attendance/clock-in-location" className="font-semibold underline">Set it now</Link>.
        </p>
      )}

      <Card className="mb-6">
        <div className="flex flex-wrap items-end gap-3">
          {(['today', 'week', 'month'] as const).map((k) => (
            <Button key={k} variant="secondary" onClick={() => setRange(preset(k))}>
              {k === 'today' ? 'Today' : k === 'week' ? 'This week' : 'This month'}
            </Button>
          ))}
          <div className="w-40"><Field label="From"><input type="date" className={inputClass} value={from} max={to} onChange={(e) => setRange([e.target.value, to])} /></Field></div>
          <div className="w-40"><Field label="To"><input type="date" className={inputClass} value={to} min={from} onChange={(e) => setRange([from, e.target.value])} /></Field></div>
          {report && <p className="pb-2 text-xs text-ink-muted">Resume time {report.resume_time} · {report.school_days} school day{report.school_days === 1 ? '' : 's'} (weekends and holidays not counted)</p>}
        </div>
      </Card>

      {!report ? (
        !error && <Loading />
      ) : (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-3">
            <StatTile
              label={single ? 'In today' : 'Days in'}
              value={single ? `${report.staff.filter((s) => s.days_in > 0).length}/${report.staff.length}` : report.summary.days_in}
              tone="green"
            />
            <StatTile label="Late" value={report.summary.days_late} tone="amber" />
            <StatTile label="Missed" value={report.summary.days_absent} tone="red" />
          </div>

          <Card title="Staff" description="Click a name for their day-by-day record.">
            {sorted.length === 0 ? (
              <Empty>No staff yet.</Empty>
            ) : (
              <Table columns={single ? ['Name', 'Designation', 'Clocked in', 'Status'] : ['Name', 'Designation', 'In', 'Late', 'Missed', 'Leave', 'Last clock-in']}>
                {sorted.map((s) => (
                  <React.Fragment key={s.staff_id}>
                    <tr className="cursor-pointer hover:bg-surface-sunken" onClick={() => toggle(s.staff_id)}>
                      <Cell className="font-medium text-brand">{open === s.staff_id ? '▾' : '▸'} {s.name}</Cell>
                      <Cell className="text-ink-muted">{s.designation ?? '—'}</Cell>
                      {single ? (
                        <>
                          <Cell>{time(s.last_clock_in)}</Cell>
                          <Cell>
                            {s.days_late > 0 ? <Badge tone="amber">Late</Badge>
                              : s.days_in > 0 ? <Badge tone="green">On time</Badge>
                              : s.days_on_leave > 0 ? <Badge tone="blue">On leave</Badge>
                              : s.days_absent > 0 ? <Badge tone="red">Did not clock in</Badge>
                              : <Badge>Not in yet</Badge>}
                          </Cell>
                        </>
                      ) : (
                        <>
                          <Cell>{s.days_in}</Cell>
                          <Cell className={s.days_late ? 'text-caution font-semibold' : ''}>{s.days_late}</Cell>
                          <Cell className={s.days_absent ? 'text-negative font-semibold' : ''}>{s.days_absent}</Cell>
                          <Cell>{s.days_on_leave}</Cell>
                          <Cell className="text-xs text-ink-muted">{s.last_clock_in ? `${dateLabel(s.last_clock_in.slice(0, 10))}, ${time(s.last_clock_in)}` : '—'}</Cell>
                        </>
                      )}
                    </tr>
                    {open === s.staff_id && (
                      <tr>
                        <td colSpan={single ? 4 : 7} className="bg-surface-sunken px-6 py-3">
                          {days === null ? <Loading /> : days.length === 0 ? (
                            <p className="text-sm text-ink-muted">No school days in this range.</p>
                          ) : (
                            <ul className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                              {days.map((d) => {
                                const [label, tone] = DAY_LABEL[d.status] ?? [d.status, 'slate'];
                                return (
                                  <li key={d.date} className="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2 text-sm ring-1 ring-line-soft">
                                    <span>{dateLabel(d.date)}</span>
                                    <span className="flex items-center gap-2">
                                      {d.clocked_in_at && <span className="text-xs text-ink-muted">{time(d.clocked_in_at)}</span>}
                                      <Badge tone={tone}>{label}</Badge>
                                    </span>
                                  </li>
                                );
                              })}
                            </ul>
                          )}
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </Table>
            )}
          </Card>
        </>
      )}
    </PageShell>
  );
}
