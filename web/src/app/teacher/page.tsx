'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Loading, PageShell, StatTile, inputClass } from '@/components/Page';

/**
 * A teacher's home (doc §6): today's lessons, homework and tests coming up,
 * the classes they look after, what needs marking, and the AI report-card
 * comments waiting for them.
 *
 * Teachers used to land on the principal's dashboard, whose endpoint refuses
 * them — so a teacher's first screen after signing in was an error.
 *
 * An AI comment never reaches a report card unreviewed (CLAUDE.md): it waits
 * here in `pending_approval` until the teacher approves, edits or rejects it.
 */

interface Dashboard {
  my_subjects: string[];
  my_class_count: number;
  todays_classes: { school_class_id: number; arm_id?: number | null; subject_name: string | null; slot_name: string | null; start_time: string | null; end_time: string | null }[];
  homework_due: { id: number; title: string; class_id: number; due_date: string; subject: { name: string } | null }[];
  upcoming_exams: { id: number; title: string; class_id: number | null; opens_at: string | null; closes_at: string | null }[];
  unread_messages: number;
  weak_subjects_and_topics: { subject_name: string; average_score: number | string; entries: number }[];
  top_improving_students: { student_id: number; student_name: string | null; previous_average: number; current_average: number; change: number }[];
  marking_progress: { pending_reviews: number; approved_comments: number; cbt_attempts_awaiting_marking: number; completion_rate_percentage: number };
}
interface MyClass { assignment_id: number; class_id: number; class_name: string | null; arm_name: string | null; role: 'form_teacher' | 'assistant'; student_count: number; session: string | null }
interface Pending { score_entry_id: number; student_name: string; class: string | null; subject: string; term: string; grade: string | null; total_score: number; comment: string | null }

const hm = (t: string | null) => (t ? t.slice(0, 5) : '');
const when = (iso: string) => new Date(iso).toLocaleString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export default function TeacherHome() {
  const [me, setMe] = useState<string>('');
  const [d, setD] = useState<Dashboard | null>(null);
  const [classes, setClasses] = useState<Record<number, string>>({});
  const [armNames, setArmNames] = useState<Record<number, string>>({});
  const [mine, setMine] = useState<MyClass[]>([]);
  const [pending, setPending] = useState<Pending[]>([]);
  const [pendingTotal, setPendingTotal] = useState(0);
  const [bank, setBank] = useState<string[]>([]);
  const [editing, setEditing] = useState<number | null>(null);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const loadQueue = useCallback(async () => {
    const res = await fetchApi<{ data: Pending[]; meta: { total: number } }>('/assessment/ai-comments/pending?per_page=10');
    setPending(res.data ?? []);
    setPendingTotal(res.meta?.total ?? 0);
  }, []);

  useEffect(() => {
    fetchApi<{ name: string }>('/user').then((u) => setMe(u.name.split(' ')[0])).catch(() => undefined);
    fetchApi<Dashboard>('/analytics/teacher').then(setD).catch((err) => setError(errorText(err, 'Could not load your dashboard.')));
    fetchApi<{ data: { id: number; name: string; arms?: { id: number; name: string }[] }[] }>('/classes').then((r) => {
      setClasses(Object.fromEntries(r.data.map((c) => [c.id, c.name])));
      setArmNames(Object.fromEntries(r.data.flatMap((c) => (c.arms ?? []).map((a) => [a.id, a.name]))));
    }).catch(() => undefined);
    fetchApi<{ data: MyClass[] }>('/teacher/my-classes').then((r) => setMine(r.data)).catch(() => undefined);
    fetchApi<{ comment_bank: string[] }>('/teacher/comment-bank').then((r) => setBank(r.comment_bank)).catch(() => undefined);
    void (async () => {
      await loadQueue().catch(() => undefined);
    })();
  }, [loadQueue]);

  async function review(id: number, action: 'approve' | 'edit' | 'reject', edited?: string) {
    setBusy(id);
    setError(null);
    try {
      await fetchApi(`/assessment/score/${id}/review-comment`, { method: 'POST', body: JSON.stringify(action === 'edit' ? { action, edited_comment: edited } : { action }) });
      setEditing(null);
      setDraft('');
      await loadQueue();
    } catch (err) {
      setError(errorText(err, 'That review could not be saved.'));
    } finally {
      setBusy(null);
    }
  }

  return (
    <PageShell title={me ? `Good day, ${me}` : 'My dashboard'} subtitle={d?.my_subjects.length ? d.my_subjects.join(' · ') : undefined}>
      <ErrorNote message={error} />
      {!d ? (!error && <Loading />) : (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Lessons today" value={d.todays_classes.length} />
            <StatTile label="Homework due" value={d.homework_due.length} tone={d.homework_due.length ? 'amber' : 'slate'} hint="set by you, still open" />
            <StatTile label="Comments to review" value={pendingTotal} tone={pendingTotal ? 'amber' : 'green'} hint={`${d.marking_progress.completion_rate_percentage}% reviewed`} />
            <StatTile label="CBT scripts to mark" value={d.marking_progress.cbt_attempts_awaiting_marking} tone={d.marking_progress.cbt_attempts_awaiting_marking ? 'red' : 'slate'} hint="theory answers" />
          </div>

          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Today’s lessons">
              {d.todays_classes.length === 0 ? <Empty>No lessons on your timetable today.</Empty> : (
                <ul className="space-y-1 text-sm">
                  {d.todays_classes.map((c, i) => (
                    <li key={i}><span className="inline-block w-24 font-mono text-xs">{hm(c.start_time)}–{hm(c.end_time)}</span><span className="font-medium">{c.subject_name}</span> · {classes[c.school_class_id] ?? 'Class'}{c.arm_id ? ` ${armNames[c.arm_id] ?? ''}` : ''}</li>
                  ))}
                </ul>
              )}
              <span className="mt-3 flex gap-4 text-sm">
                <Link href="/timetable" className="text-brand underline">My timetable</Link>
                <Link href="/attendance" className="text-brand underline">Take the register</Link>
              </span>
            </Card>

            <Card title="My classes">
              {mine.length === 0 ? <p className="text-sm text-ink-muted">You are not a form teacher this session.</p> : (
                <ul className="space-y-1 text-sm">
                  {mine.map((c) => (
                    <li key={c.assignment_id}><span className="font-medium">{[c.class_name, c.arm_name].filter(Boolean).join(' ')}</span> · {c.student_count} students <Badge tone={c.role === 'form_teacher' ? 'blue' : 'slate'}>{c.role === 'form_teacher' ? 'form teacher' : 'assistant'}</Badge></li>
                  ))}
                </ul>
              )}
              {d.unread_messages > 0 && <p className="mt-3 text-sm"><Badge tone="blue">{d.unread_messages} unread</Badge> messages from parents</p>}
            </Card>

            <Card title="Homework due">
              {d.homework_due.length === 0 ? <Empty>Nothing open.</Empty> : (
                <ul className="space-y-1 text-sm">{d.homework_due.map((h) => <li key={h.id}><span className="font-medium">{h.title}</span> · {h.subject?.name} · {classes[h.class_id] ?? ''} · due {h.due_date?.slice(0, 10)}</li>)}</ul>
              )}
              <Link href="/teacher/homework" className="mt-3 inline-block text-sm text-brand underline">Set and mark homework</Link>
            </Card>

            <Card title="Tests coming up">
              {d.upcoming_exams.length === 0 ? <Empty>No published tests open.</Empty> : (
                <ul className="space-y-1 text-sm">{d.upcoming_exams.map((e) => <li key={e.id}><span className="font-medium">{e.title}</span>{e.class_id ? ` · ${classes[e.class_id] ?? ''}` : ''}{e.opens_at ? ` · opens ${when(e.opens_at)}` : ''}</li>)}</ul>
              )}
              <Link href="/cbt-setup" className="mt-3 inline-block text-sm text-brand underline">CBT exams</Link>
            </Card>

            <Card title="Subjects needing attention" description="Lowest class averages in the subjects you teach.">
              {d.weak_subjects_and_topics.length === 0 ? <p className="text-sm text-ink-muted">No scores yet.</p> : (
                <ul className="space-y-1 text-sm">{d.weak_subjects_and_topics.map((s) => <li key={s.subject_name}>{s.subject_name} <span className="text-ink-muted">· average {Number(s.average_score).toFixed(1)} over {s.entries} scores</span></li>)}</ul>
              )}
            </Card>

            <Card title="Most improved" description="Biggest rise in average between their last two terms.">
              {d.top_improving_students.length === 0 ? <p className="text-sm text-ink-muted">Needs two terms of scores.</p> : (
                <ul className="space-y-1 text-sm">{d.top_improving_students.map((s) => <li key={s.student_id}><span className="font-medium">{s.student_name}</span> <Badge tone={s.change >= 0 ? 'green' : 'red'}>{s.change >= 0 ? '+' : ''}{s.change}</Badge> <span className="text-ink-muted">{s.previous_average} → {s.current_average}</span></li>)}</ul>
              )}
            </Card>
          </div>

          <Card title={`AI comments waiting for you (${pendingTotal})`} description="Draft report-card comments. None reaches a report card until you approve or rewrite it.">
            {pending.length === 0 ? <Empty>Nothing waiting. Generate drafts from Score entry.</Empty> : (
              <ul className="divide-y divide-line-soft">
                {pending.map((p) => (
                  <li key={p.score_entry_id} className="py-3">
                    <p className="text-sm"><span className="font-medium">{p.student_name}</span> <span className="text-ink-muted">· {p.class} · {p.subject} · {p.term} · {p.total_score}{p.grade ? ` (${p.grade})` : ''}</span></p>
                    {editing === p.score_entry_id ? (
                      <div className="mt-2 space-y-2">
                        <textarea className={`${inputClass} min-h-[5rem]`} value={draft} onChange={(e) => setDraft(e.target.value)} />
                        {bank.length > 0 && (
                          <div className="flex flex-wrap gap-1">
                            {bank.map((b) => <button key={b} type="button" onClick={() => setDraft((t) => (t ? `${t} ${b}` : b))} className="rounded-full border border-line px-2 py-0.5 text-xs hover:border-brand">+ {b.slice(0, 40)}…</button>)}
                          </div>
                        )}
                        <div className="flex gap-2">
                          <Button disabled={busy === p.score_entry_id || !draft.trim()} onClick={() => review(p.score_entry_id, 'edit', draft.trim())}>Save my version</Button>
                          <Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                        </div>
                      </div>
                    ) : (
                      <>
                        <p className="mt-1 rounded-lg bg-surface-sunken px-3 py-2 text-sm italic">{p.comment ?? 'No draft text.'}</p>
                        <div className="mt-2 flex gap-2">
                          <Button disabled={busy === p.score_entry_id} onClick={() => review(p.score_entry_id, 'approve')}>Approve</Button>
                          <Button variant="secondary" disabled={busy === p.score_entry_id} onClick={() => { setEditing(p.score_entry_id); setDraft(p.comment ?? ''); }}>Edit</Button>
                          <Button variant="danger" disabled={busy === p.score_entry_id} onClick={() => review(p.score_entry_id, 'reject')}>Reject</Button>
                        </div>
                      </>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {pendingTotal > pending.length && <p className="mt-3 text-xs text-ink-muted">Showing {pending.length} of {pendingTotal}. Approve these to see more.</p>}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
