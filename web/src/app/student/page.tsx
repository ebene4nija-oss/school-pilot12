'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Loading, PageShell, StatTile } from '@/components/Page';
import LibraryBooks from '@/components/LibraryBooks';
import { when } from '@/lib/student';

/**
 * The student's own portal: today and My Subjects (TASK 02, 06). Everything
 * here is the signed-in student's own record, and each card leads to the
 * full screen for it — the week's timetable, homework, tests, results, the
 * AI tutor and notifications all have their own pages now. A teacher comment
 * or a mark shows only where the API releases it.
 */

type Tab = 'today' | 'subjects';

interface Period { day: string; slot_name: string; start_time: string | null; end_time: string | null; subject_name: string | null; is_break: boolean; teacher: string | null; venue?: string | null; cover_teacher?: string | null }
interface Dashboard {
  student: { id: number; name: string; class: string | null; arm: string | null };
  term: { name: string } | null;
  today: { day: string; periods: Period[] };
  attendance: { marked_days: number; rate: number | null; absent: number; late: number; excused?: number };
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
interface Gamification { points: number; current_streak: number; badges: string[] | null }

const time = (t: string | null) => (t ? t.slice(0, 5) : '');
const linkClass = 'mt-3 inline-block text-sm text-brand underline';

export default function StudentHome() {
  const [tab, setTab] = useState<Tab>('today');
  const [dash, setDash] = useState<Dashboard | null>(null);
  const [subjects, setSubjects] = useState<SubjectRow[] | null>(null);
  const [detail, setDetail] = useState<SubjectDetail | null>(null);
  const [game, setGame] = useState<Gamification | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        if (tab === 'today' && !dash) setDash(await fetchApi<Dashboard>('/me/dashboard'));
        if (tab === 'subjects' && !subjects) setSubjects((await fetchApi<{ data: SubjectRow[] }>('/me/subjects')).data);
      } catch (err) {
        setError(errorText(err, 'Could not load.'));
      }
    })();
  }, [tab, dash, subjects]);

  useEffect(() => {
    fetchApi<{ data: Gamification }>('/gamification/profile').then((r) => setGame(r.data)).catch(() => undefined);
  }, []);

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
        {([['today', 'Today'], ['subjects', 'My subjects']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setDetail(null); }}>{l}</Button>
        ))}
        <Link href="/student/results" className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-sunken">My results</Link>
      </nav>
      <ErrorNote message={error} />

      {tab === 'today' && (!dash ? <Loading /> : (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Attendance this term" value={dash.attendance.rate !== null ? `${dash.attendance.rate}%` : '—'} tone="green"
              hint={[`${dash.attendance.absent} absent`, `${dash.attendance.late} late`, dash.attendance.excused ? `${dash.attendance.excused} excused` : null].filter(Boolean).join(' · ')} />
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
              <Link href="/student/timetable" className={linkClass}>Whole week (printable)</Link>
            </Card>
            <Card title="Homework due">
              {dash.assignments_due.length === 0 ? <Empty>Nothing due. Well done.</Empty> : (
                <ul className="space-y-1 text-sm">{dash.assignments_due.map((a) => <li key={a.id}><span className="font-medium">{a.title}</span> · {a.subject} · due {a.due_date}</li>)}</ul>
              )}
              <Link href="/student/homework" className={linkClass}>Hand in homework</Link>
            </Card>
            <Card title="Tests and virtual classes">
              {dash.upcoming_tests.length === 0 && dash.virtual_classes.length === 0 ? <Empty>Nothing coming up.</Empty> : (
                <ul className="space-y-1 text-sm">
                  {dash.upcoming_tests.map((t) => <li key={`t${t.id}`}>📝 {t.title} · {t.subject}{t.opens_at ? ` · opens ${when(t.opens_at)}` : ''}</li>)}
                  {dash.virtual_classes.map((c) => <li key={`c${c.id}`}>🎥 {c.title} · {when(c.scheduled_for)}{c.is_joinable && <Link href="/live-class" className="ml-2 text-brand underline">Join</Link>}</li>)}
                </ul>
              )}
              <span className="flex gap-4">
                <Link href="/student/tests" className={linkClass}>My tests</Link>
                <Link href="/learning" className={linkClass}>All my learning</Link>
              </span>
            </Card>
            <Card title="Results and help">
              <p className="text-sm">{dash.results?.released ? 'This term’s results are out.' : 'This term’s results have not been released yet.'}</p>
              {dash.results?.released && <Link href="/student/results" className="mt-2 inline-block rounded-lg border border-line px-3.5 py-2 text-sm font-medium hover:bg-surface-sunken">See my results</Link>}
              {dash.tutor_available && <p className="mt-3 text-sm text-ink-muted">Stuck on something? <Link href="/student/tutor" className="text-brand underline">Ask the AI tutor</Link> to explain it step by step. It helps you learn; your teacher still marks your work.</p>}
              <p className="mt-3 text-sm">
                <Link href="/student/notifications" className="hover:underline">
                  {dash.unread_notifications > 0 ? <><Badge tone="blue">{dash.unread_notifications} unread</Badge> notifications</> : 'Notifications'}
                </Link>
              </p>
            </Card>
          </div>
          {game && (game.points > 0 || (game.badges ?? []).length > 0) && (
            <Card title="My badges">
              <div className="flex flex-wrap items-center gap-3">
                <Badge tone="blue">{game.points} points</Badge>
                {game.current_streak > 1 && <Badge tone="amber">{game.current_streak}-day streak</Badge>}
                {(game.badges ?? []).map((b) => <Badge key={b} tone="green">🏅 {b}</Badge>)}
              </div>
            </Card>
          )}
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
              <Link href="/student/homework" className={linkClass}>Hand in homework</Link>
            </Card>
            <Card title="Tests">
              {detail.tests.length === 0 ? <Empty>None.</Empty> : (
                <ul className="space-y-1 text-sm">{detail.tests.map((t) => <li key={t.id}>{t.title} {t.submitted ? <Badge tone="green">done{t.percentage !== null ? ` · ${t.percentage}%` : ''}</Badge> : t.opens_at && <span className="text-ink-muted">· {when(t.opens_at)}</span>}</li>)}</ul>
              )}
              <Link href="/student/tests" className={linkClass}>My tests</Link>
            </Card>
            <Card title="Results by term">
              {detail.results.length === 0 ? <Empty>No released results yet.</Empty> : (
                <ul className="space-y-1 text-sm">{detail.results.map((r) => <li key={r.term}>{r.term}: <span className="font-medium">{r.total}</span> {r.grade && <Badge>{r.grade}</Badge>}</li>)}</ul>
              )}
            </Card>
          </div>
        </div>
      )}
    </PageShell>
  );
}
