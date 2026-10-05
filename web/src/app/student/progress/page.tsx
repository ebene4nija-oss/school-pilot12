'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Empty, ErrorNote, Loading, Meter, PageShell, StatTile, Table } from '@/components/Page';
import { day } from '@/lib/student';

/**
 * My progress (TASK 05, the student's own view), from `GET /me/progress`.
 *
 * Released terms only, no class averages, and a position only where the
 * school allows students to see it — all decided by the API. Remarks are the
 * ones a teacher marked as visible to the student; support plans appear only
 * where the school shares them.
 */

interface Mine {
  empty: boolean;
  period: { label: string; class: string | null } | null;
  overview: { average: number | null; previous_average: number | null; change_points: number | null; subjects_passed: number; subjects_counted: number; pass_mark: number; attendance_rate: number | null; position: number | null; class_size: number | null } | null;
  subjects: { subject: string; state: string; ca: number | null; exam: number | null; total: number | null; grade: string | null; previous_total: number | null; change: number | null; position: number | null }[];
  trend: { term_id: number; label: string; average: number | null }[];
  completion: { assigned: number; completed: number; missed: number; pending: number; awaiting_grading: number } & Record<string, unknown>;
  attendance: { totals: { days: number; present: number; absent: number; late: number; excused: number; rate: number | null }; monthly: { month: string; rate: number | null }[]; note?: string };
  remarks: { id: number; teacher: string | null; subject: string | null; body?: string; created_at?: string }[];
  interventions: { subject: string | null; summary: string; review_date: string | null; status: string }[];
}
interface Mastery { subject: string | null; topic: string; mastery_percentage: number }

const tone = (v: number) => (v >= 70 ? 'green' : v >= 50 ? 'amber' : 'red') as 'green' | 'amber' | 'red';

export default function StudentProgress() {
  const [data, setData] = useState<Mine | null>(null);
  const [mastery, setMastery] = useState<{ mastered: Mastery[]; developing: Mastery[]; needs_work: Mastery[] } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<Mine>('/me/progress').then(setData).catch((err) => setError(errorText(err, 'Could not load your progress.')));
    fetchApi<{ mastered: Mastery[]; developing: Mastery[]; needs_work: Mastery[] }>('/ai/tutor/mastery').then(setMastery).catch(() => undefined);
  }, []);

  if (!data) return <PageShell title="My progress"><ErrorNote message={error} />{!error && <Loading />}</PageShell>;

  const o = data.overview;
  const strong = data.subjects.filter((s) => (s.total ?? 0) >= 70).map((s) => s.subject);
  const support = o ? data.subjects.filter((s) => s.total !== null && s.total < o.pass_mark).map((s) => s.subject) : [];
  const att = data.attendance?.totals;

  return (
    <PageShell title="My progress" subtitle={data.period ? `Latest released term: ${data.period.label}` : 'How your results are moving, term by term.'}>
      <ErrorNote message={error} />
      {data.empty || !o ? (
        <Empty>Your progress appears here once your school releases a term’s results.</Empty>
      ) : (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Average" value={o.average != null ? `${o.average}%` : '—'} tone={o.average != null ? tone(o.average) : 'slate'} />
            <StatTile label="Since last term" value={o.change_points == null ? '—' : `${o.change_points > 0 ? '+' : ''}${o.change_points}`} tone={o.change_points == null ? 'slate' : o.change_points >= 0 ? 'green' : 'red'} hint={o.previous_average != null ? `was ${o.previous_average}%` : 'first released term'} />
            <StatTile label="Subjects passed" value={`${o.subjects_passed} of ${o.subjects_counted}`} tone="blue" hint={`pass mark ${o.pass_mark}`} />
            {o.position ? <StatTile label="Position" value={`${o.position} of ${o.class_size}`} /> : <StatTile label="Attendance" value={o.attendance_rate != null ? `${o.attendance_rate}%` : '—'} tone="green" />}
          </div>

          {data.trend.length > 1 && (
            <Card title="Average by term">
              <ul className="space-y-3">
                {data.trend.map((t) => (
                  <li key={t.term_id} className="text-sm">
                    <div className="mb-1 flex justify-between"><span>{t.label}</span><span className="font-medium">{t.average ?? '—'}%</span></div>
                    <Meter value={t.average ?? 0} tone={tone(t.average ?? 0)} />
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <Card title="Subject by subject">
            <Table columns={['Subject', 'CA', 'Exam', 'Total', 'Grade', 'Change']}>
              {data.subjects.map((s) => (
                <tr key={s.subject}>
                  <Cell className="font-medium">{s.subject}</Cell>
                  <Cell>{s.ca ?? '—'}</Cell>
                  <Cell>{s.exam ?? '—'}</Cell>
                  <Cell>{s.state !== 'scored' ? <span className="text-xs text-ink-muted">{s.state}</span> : s.total ?? '—'}</Cell>
                  <Cell>{s.grade && <Badge tone="blue">{s.grade}</Badge>}</Cell>
                  <Cell>{s.change == null ? '—' : <Badge tone={s.change > 0 ? 'green' : s.change < 0 ? 'red' : 'slate'}>{s.change > 0 ? '▲' : s.change < 0 ? '▼' : '='} {Math.abs(s.change)}</Badge>}</Cell>
                </tr>
              ))}
            </Table>
          </Card>

          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="What to work on">
              {support.length === 0 ? <p className="text-sm text-ink-muted">Every subject is at or above the pass mark. Keep it up.</p> : (
                <p className="text-sm">{support.join(', ')}. Ask your subject teacher what to revise, or practise with the <a href="/student/tutor" className="text-brand underline">AI tutor</a>.</p>
              )}
              {strong.length > 0 && <p className="mt-2 text-sm text-ink-muted">Strongest: {strong.join(', ')}.</p>}
              {data.completion && (
                <p className="mt-3 text-sm">Work this term: {data.completion.completed} of {data.completion.assigned} done{data.completion.missed ? `, ${data.completion.missed} missed` : ''}{data.completion.awaiting_grading ? `, ${data.completion.awaiting_grading} waiting to be marked` : ''}.</p>
              )}
            </Card>
            <Card title="Attendance this term" description={data.attendance?.note}>
              {!att || att.days === 0 ? <p className="text-sm text-ink-muted">Not marked yet.</p> : (
                <>
                  <p className="text-sm">{att.rate ?? '—'}% · {att.present} present, {att.late} late, {att.absent} absent, {att.excused} excused</p>
                  <ul className="mt-3 space-y-2">
                    {data.attendance.monthly.map((m) => (
                      <li key={m.month} className="text-sm">
                        <div className="mb-1 flex justify-between"><span>{m.month}</span><span>{m.rate ?? '—'}%</span></div>
                        <Meter value={m.rate ?? 0} tone={(m.rate ?? 0) >= 90 ? 'green' : (m.rate ?? 0) >= 75 ? 'amber' : 'red'} />
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </Card>
          </div>

          {data.remarks.length > 0 && (
            <Card title="From your teachers">
              <ul className="space-y-3 text-sm">
                {data.remarks.map((r) => (
                  <li key={r.id}>
                    <p>{r.body}</p>
                    <p className="text-xs text-ink-muted">{[r.teacher, r.subject, r.created_at && day(r.created_at)].filter(Boolean).join(' · ')}</p>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {data.interventions.length > 0 && (
            <Card title="Support plans">
              <ul className="space-y-2 text-sm">
                {data.interventions.map((i, n) => (
                  <li key={n}>{i.subject && <span className="font-medium">{i.subject}: </span>}{i.summary} <Badge tone={i.status === 'completed' ? 'green' : 'blue'}>{i.status}</Badge>{i.review_date && <span className="text-xs text-ink-muted"> · review {day(i.review_date)}</span>}</li>
                ))}
              </ul>
            </Card>
          )}
        </div>
      )}

      {mastery && mastery.needs_work.length + mastery.developing.length + mastery.mastered.length > 0 && (
        <Card title="Topics" description="From marked work, tests and tutor practice." className="mt-5">
          <div className="grid gap-4 text-sm sm:grid-cols-3">
            {([['Needs work', mastery.needs_work, 'red'], ['Developing', mastery.developing, 'amber'], ['Mastered', mastery.mastered, 'green']] as const).map(([title, rows, t]) => (
              <div key={title}>
                <p className="mb-2"><Badge tone={t}>{title}</Badge></p>
                {rows.length === 0 ? <p className="text-ink-muted">—</p> : <ul className="space-y-1">{rows.slice(0, 8).map((m) => <li key={`${m.subject}-${m.topic}`}>{m.topic} <span className="text-ink-muted">· {Math.round(m.mastery_percentage)}%</span></li>)}</ul>}
              </div>
            ))}
          </div>
        </Card>
      )}
    </PageShell>
  );
}
