'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';
import LibraryBooks from '@/components/LibraryBooks';

/**
 * The student's own portal: today, My Subjects and My Results (TASK 02, 06,
 * 10). Everything here is the signed-in student's own record. Results show
 * only for a released term, and only once unlocked where the school uses
 * result PINs; a teacher comment shows only once a teacher has written or
 * approved it.
 */

type Tab = 'today' | 'subjects' | 'results';

interface Period { day: string; slot_name: string; start_time: string | null; end_time: string | null; subject_name: string | null; is_break: boolean; teacher: string | null; venue?: string | null; cover_teacher?: string | null }
interface Dashboard {
  student: { name: string; class: string | null; arm: string | null };
  term: { name: string } | null;
  today: { day: string; periods: Period[] };
  attendance: { marked_days: number; rate: number | null; absent: number; late: number };
  subjects_count: number;
  assignments_due: { id: number; title: string; subject: string | null; due_date: string }[];
  upcoming_tests: { id: number; title: string; subject: string | null; opens_at: string | null }[];
  virtual_classes: { id: number; title: string; scheduled_for: string; is_joinable: boolean }[];
  new_materials: number;
  results: { released: boolean } | null;
  tutor_available: boolean;
  unread_notifications: number;
}
interface SubjectRow { id: number; name: string; elective: boolean; teachers: string[]; periods_per_week: number; materials: number; assignments_due: number; upcoming_tests: number }
interface SubjectDetail {
  subject: { name: string };
  teachers: string[];
  timetable: Period[];
  materials: { id: number; title: string; type: string; topic: string | null }[];
  assignments: { id: number; title: string; due_date: string; status: string; marks_awarded: number | null }[];
  tests: { id: number; title: string; opens_at: string | null; submitted: boolean; percentage: number | null }[];
  results: { term: string; total: number; grade: string | null }[];
}
interface ResultSheet {
  status: 'released' | 'not_released' | 'no_scores' | 'locked';
  message?: string;
  average?: number;
  subjects?: { subject: string; state: string; components: Record<string, number>; total: number | null; grade: string | null; position: number | null; comment: string | null }[];
}

const time = (t: string | null) => (t ? t.slice(0, 5) : '');
const when = (iso: string) => new Date(iso).toLocaleString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const label = (k: string) => k.replaceAll('_', ' ').replace(/\bca(\d)?\b/i, (m) => m.toUpperCase());

export default function StudentHome() {
  const [tab, setTab] = useState<Tab>('today');
  const [dash, setDash] = useState<Dashboard | null>(null);
  const [subjects, setSubjects] = useState<SubjectRow[] | null>(null);
  const [detail, setDetail] = useState<SubjectDetail | null>(null);
  const [terms, setTerms] = useState<{ id: number; name: string; released: boolean }[]>([]);
  const [termId, setTermId] = useState('');
  const [sheet, setSheet] = useState<ResultSheet | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        if (tab === 'today' && !dash) setDash(await fetchApi<Dashboard>('/me/dashboard'));
        if (tab === 'subjects' && !subjects) setSubjects((await fetchApi<{ data: SubjectRow[] }>('/me/subjects')).data);
        if (tab === 'results' && terms.length === 0) {
          const res = await fetchApi<{ terms: { id: number; name: string; released: boolean }[] }>('/me/results');
          setTerms(res.terms);
          const first = res.terms.find((t) => t.released);
          if (first) setTermId(String(first.id));
        }
      } catch (err) {
        setError(errorText(err, 'Could not load.'));
      }
    })();
  }, [tab, dash, subjects, terms.length]);

  useEffect(() => {
    if (!termId) return;
    fetchApi<ResultSheet>(`/me/results?term_id=${termId}`).then(setSheet).catch((err) => setError(errorText(err, 'Could not load results.')));
  }, [termId]);

  async function openSubject(id: number) {
    try {
      setDetail(await fetchApi<SubjectDetail>(`/me/subjects/${id}`));
    } catch (err) {
      setError(errorText(err, 'Could not open the subject.'));
    }
  }

  return (
    <PageShell title={dash ? `Hello, ${dash.student.name.split(' ')[0]}` : 'My school day'} subtitle={dash ? [dash.student.class, dash.student.arm, dash.term?.name].filter(Boolean).join(' · ') : undefined}>
      <nav className="mb-5 flex flex-wrap gap-2" aria-label="Portal sections">
        {([['today', 'Today'], ['subjects', 'My subjects'], ['results', 'My results']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setDetail(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />

      {tab === 'today' && (!dash ? <Loading /> : (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Attendance this term" value={dash.attendance.rate !== null ? `${dash.attendance.rate}%` : '—'} tone="green" hint={`${dash.attendance.absent} absent · ${dash.attendance.late} late`} />
            <StatTile label="Homework due" value={dash.assignments_due.length} tone={dash.assignments_due.length ? 'amber' : 'slate'} />
            <StatTile label="Subjects" value={dash.subjects_count} />
            <StatTile label="New materials" value={dash.new_materials} tone="blue" hint="last two weeks" />
          </div>
          <div className="grid gap-5 lg:grid-cols-2">
            <Card title={`${dash.today.day}'s timetable`}>
              {dash.today.periods.length === 0 ? <Empty>No lessons on the timetable today.</Empty> : (
                <ul className="space-y-1 text-sm">
                  {dash.today.periods.map((p, i) => (
                    <li key={i} className={p.is_break ? 'text-ink-muted' : ''}>
                      <span className="inline-block w-24 font-mono text-xs">{time(p.start_time)}–{time(p.end_time)}</span>
                      {p.is_break ? 'Break' : p.subject_name}{p.teacher && <span className="text-ink-muted"> · {p.cover_teacher ? `${p.cover_teacher} (cover)` : p.teacher}</span>}{!p.is_break && p.venue && <span className="text-ink-muted"> · {p.venue}</span>}
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            <Card title="Homework due">
              {dash.assignments_due.length === 0 ? <Empty>Nothing due. Well done.</Empty> : (
                <ul className="space-y-1 text-sm">{dash.assignments_due.map((a) => <li key={a.id}><span className="font-medium">{a.title}</span> · {a.subject} · due {a.due_date}</li>)}</ul>
              )}
              <a href="/learning" className="mt-3 inline-block text-sm text-brand underline">All my learning</a>
            </Card>
            <Card title="Tests and virtual classes">
              {dash.upcoming_tests.length === 0 && dash.virtual_classes.length === 0 ? <Empty>Nothing coming up.</Empty> : (
                <ul className="space-y-1 text-sm">
                  {dash.upcoming_tests.map((t) => <li key={`t${t.id}`}>📝 {t.title} · {t.subject}{t.opens_at ? ` · opens ${when(t.opens_at)}` : ''}</li>)}
                  {dash.virtual_classes.map((c) => <li key={`c${c.id}`}>🎥 {c.title} · {when(c.scheduled_for)}{c.is_joinable && <a href="/live-class" className="ml-2 text-brand underline">Join</a>}</li>)}
                </ul>
              )}
            </Card>
            <Card title="Results and help">
              <p className="text-sm">{dash.results?.released ? 'This term’s results are out.' : 'This term’s results have not been released yet.'}</p>
              {dash.results?.released && <Button className="mt-2" variant="secondary" onClick={() => setTab('results')}>See my results</Button>}
              {dash.tutor_available && <p className="mt-3 text-sm text-ink-muted">Stuck on something? The AI tutor can explain it, step by step, in the SchoolPilot app. It helps you learn; your teacher still marks your work.</p>}
              {dash.unread_notifications > 0 && <p className="mt-3 text-sm"><Badge tone="blue">{dash.unread_notifications} unread</Badge> notifications</p>}
            </Card>
          </div>
          <LibraryBooks />
        </div>
      ))}

      {tab === 'subjects' && !detail && (!subjects ? <Loading /> : subjects.length === 0 ? <Empty>No subjects yet. Ask your class teacher.</Empty> : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {subjects.map((s) => (
            <button key={s.id} type="button" onClick={() => openSubject(s.id)} className="rounded-xl bg-surface p-5 text-left shadow-sm ring-1 ring-line-soft hover:ring-brand">
              <p className="font-headline font-semibold text-brand">{s.name} {s.elective && <Badge>elective</Badge>}</p>
              <p className="text-sm text-ink-muted">{s.teachers.join(', ') || 'Teacher not assigned'}</p>
              <p className="mt-2 text-xs text-ink-muted">{s.periods_per_week} periods a week · {s.materials} materials · {s.assignments_due} due · {s.upcoming_tests} tests</p>
            </button>
          ))}
        </div>
      ))}

      {tab === 'subjects' && detail && (
        <div className="space-y-5">
          <Button variant="secondary" onClick={() => setDetail(null)}>← All subjects</Button>
          <h2 className="font-headline text-xl font-bold text-brand">{detail.subject.name}</h2>
          <p className="text-sm text-ink-muted">{detail.teachers.join(', ')}{detail.timetable.length ? ` · ${detail.timetable.map((p) => `${p.day.slice(0, 3)} ${time(p.start_time)}`).join(', ')}` : ''}</p>
          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Materials">
              {detail.materials.length === 0 ? <Empty>None yet.</Empty> : (
                <ul className="space-y-1 text-sm">{detail.materials.map((m) => <li key={m.id}><a className="text-brand hover:underline" href={`/api/backend/learning-materials/${m.id}/download`} target="_blank" rel="noreferrer">{m.title}</a>{m.topic && <span className="text-ink-muted"> · {m.topic}</span>}</li>)}</ul>
              )}
            </Card>
            <Card title="Assignments">
              {detail.assignments.length === 0 ? <Empty>None.</Empty> : (
                <ul className="space-y-1 text-sm">{detail.assignments.map((a) => <li key={a.id}>{a.title} · due {a.due_date} <Badge tone={a.status === 'graded' ? 'green' : a.status === 'missed' ? 'red' : a.status === 'not_submitted' ? 'amber' : 'blue'}>{a.status.replaceAll('_', ' ')}</Badge>{a.marks_awarded !== null && ` ${a.marks_awarded}`}</li>)}</ul>
              )}
            </Card>
            <Card title="Tests">
              {detail.tests.length === 0 ? <Empty>None.</Empty> : (
                <ul className="space-y-1 text-sm">{detail.tests.map((t) => <li key={t.id}>{t.title} {t.submitted ? <Badge tone="green">done{t.percentage !== null ? ` · ${t.percentage}%` : ''}</Badge> : t.opens_at && <span className="text-ink-muted">· {when(t.opens_at)}</span>}</li>)}</ul>
              )}
            </Card>
            <Card title="Results by term">
              {detail.results.length === 0 ? <Empty>No released results yet.</Empty> : (
                <ul className="space-y-1 text-sm">{detail.results.map((r) => <li key={r.term}>{r.term}: <span className="font-medium">{r.total}</span> {r.grade && <Badge>{r.grade}</Badge>}</li>)}</ul>
              )}
            </Card>
          </div>
        </div>
      )}

      {tab === 'results' && (
        <div className="space-y-5">
          <select className={`${inputClass} max-w-xs`} value={termId} onChange={(e) => setTermId(e.target.value)} aria-label="Term">
            <option value="">Choose a term…</option>
            {terms.map((t) => <option key={t.id} value={t.id} disabled={!t.released}>{t.name}{t.released ? '' : ' (not released)'}</option>)}
          </select>
          {sheet && sheet.status !== 'released' && <Empty>{sheet.message}</Empty>}
          {sheet?.status === 'released' && sheet.subjects && (
            <Card title={`Average: ${sheet.average}`}>
              <Table columns={['Subject', 'Scores', 'Total', 'Grade', 'Comment']}>
                {sheet.subjects.map((s) => (
                  <tr key={s.subject}>
                    <Cell className="font-medium">{s.subject}</Cell>
                    <Cell className="text-xs text-ink-muted">{s.state !== 'scored' ? s.state : Object.entries(s.components).map(([k, v]) => `${label(k)} ${v}`).join(' · ')}</Cell>
                    <Cell>{s.total ?? '—'}</Cell>
                    <Cell>{s.grade && <Badge tone="blue">{s.grade}</Badge>}{s.position ? <span className="ml-1 text-xs text-ink-muted">pos. {s.position}</span> : null}</Cell>
                    <Cell className="text-sm">{s.comment ?? ''}</Cell>
                  </tr>
                ))}
              </Table>
            </Card>
          )}
        </div>
      )}
    </PageShell>
  );
}
