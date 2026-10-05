'use client';

import React, { useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Empty, ErrorNote, Loading, PageShell } from '@/components/Page';

/**
 * The student's week as a calendar grid (TASK 07), printable on one A4 sheet.
 *
 * Days across, periods down — the shape of the timetable pinned to a
 * classroom wall, which is what a student and their parent expect to print.
 * Rows are the school's own time slots, so a school with a 35-minute period
 * and a long break gets exactly its own day, not an invented hourly grid.
 */

interface Period { day: string; slot_name: string | null; start_time: string | null; end_time: string | null; subject_name: string | null; is_break: boolean; teacher: string | null; venue?: string | null }

const WEEK = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const hm = (t: string | null) => (t ? t.slice(0, 5) : '');

export default function StudentTimetable() {
  const [days, setDays] = useState<Record<string, Period[]> | null>(null);
  const [heading, setHeading] = useState<{ school: string; klass: string; term: string; name: string }>({ school: '', klass: '', term: '', name: '' });
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ days: Record<string, Period[]> | Period[] }>('/me/timetable')
      // An empty collection serialises as [] rather than {}.
      .then((res) => setDays(Array.isArray(res.days) ? {} : res.days))
      .catch((err) => setError(errorText(err, 'Could not load your timetable.')));
    fetchApi<{ student: { name: string; class: string | null; arm: string | null }; term: { name: string } | null }>('/me/profile')
      .then((p) => setHeading((h) => ({ ...h, name: p.student.name, klass: [p.student.class, p.student.arm].filter(Boolean).join(' '), term: p.term?.name ?? '' })))
      .catch(() => undefined);
    fetchApi<{ school: { name: string } | null }>('/user')
      .then((u) => setHeading((h) => ({ ...h, school: u.school?.name ?? '' })))
      .catch(() => undefined);
  }, []);

  const grid = useMemo(() => {
    if (!days) return null;
    // Weekdays always show; a weekend day only if the school teaches on it.
    const cols = WEEK.filter((d, i) => i < 5 || (days[d]?.length ?? 0) > 0);
    const slots = new Map<string, { start: string; end: string; name: string | null; isBreak: boolean }>();
    for (const d of cols) {
      for (const p of days[d] ?? []) {
        const key = `${hm(p.start_time)}-${hm(p.end_time)}`;
        const seen = slots.get(key);
        slots.set(key, { start: hm(p.start_time), end: hm(p.end_time), name: seen?.name ?? p.slot_name, isBreak: (seen?.isBreak ?? true) && p.is_break });
      }
    }
    const rows = [...slots.entries()].sort(([, a], [, b]) => a.start.localeCompare(b.start));
    const cell = (d: string, key: string) => (days[d] ?? []).find((p) => `${hm(p.start_time)}-${hm(p.end_time)}` === key);

    return { cols, rows, cell };
  }, [days]);

  return (
    <PageShell
      title="My timetable"
      subtitle={[heading.klass, heading.term].filter(Boolean).join(' · ') || undefined}
      actions={grid && grid.rows.length > 0 ? <Button onClick={() => window.print()} className="print:hidden">Print timetable</Button> : undefined}
    >
      {/* One landscape A4 sheet; the screen chrome is hidden by print: classes. */}
      <style>{'@media print { @page { size: A4 landscape; margin: 10mm; } body { background: #fff !important; } }'}</style>
      <ErrorNote message={error} />

      {!grid ? (!error && <Loading />) : grid.rows.length === 0 ? (
        <Empty>Your class timetable has not been published yet. Your school will publish it before lessons start.</Empty>
      ) : (
        <div className="rounded-xl bg-surface p-4 shadow-sm ring-1 ring-line-soft print:p-0 print:shadow-none print:ring-0">
          <div className="mb-3 hidden print:block">
            <p className="text-lg font-bold">{heading.school}</p>
            <p className="text-sm">Class timetable — {heading.klass}{heading.term ? ` · ${heading.term}` : ''}{heading.name ? ` · ${heading.name}` : ''}</p>
          </div>
          <div className="overflow-x-auto print:overflow-visible">
            <table className="w-full min-w-[48rem] table-fixed border-collapse text-sm print:min-w-0 print:text-[10px]">
              <thead>
                <tr>
                  <th className="w-24 border border-line-soft bg-surface-raised px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide text-ink-muted">Time</th>
                  {grid.cols.map((d) => (
                    <th key={d} className="border border-line-soft bg-brand px-2 py-2 text-left font-headline text-sm font-semibold text-white print:bg-white print:text-black">{d}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {grid.rows.map(([key, slot]) => (
                  <tr key={key} className={slot.isBreak ? 'bg-surface-sunken' : ''}>
                    <td className="border border-line-soft px-2 py-2 align-top font-mono text-xs text-ink-muted">
                      {slot.start}–{slot.end}
                      {slot.name && <span className="block font-sans text-[11px]">{slot.name}</span>}
                    </td>
                    {grid.cols.map((d) => {
                      const p = grid.cell(d, key);
                      if (!p) return <td key={d} className="border border-line-soft" />;
                      if (p.is_break) {
                        return <td key={d} className="border border-line-soft px-2 py-2 text-center text-xs font-medium uppercase tracking-wide text-ink-muted">{p.subject_name || 'Break'}</td>;
                      }

                      return (
                        <td key={d} className="border border-line-soft px-2 py-2 align-top">
                          <span className="block font-semibold text-brand print:text-black">{p.subject_name}</span>
                          {p.teacher && <span className="block text-xs text-ink-muted">{p.teacher}</span>}
                          {p.venue && <span className="block text-xs text-ink-faint">{p.venue}</span>}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </PageShell>
  );
}
