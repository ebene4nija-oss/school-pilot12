'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, StatTile, inputClass } from '@/components/Page';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * A student's file, for a teacher who teaches them (TASK 03, 05): the
 * snapshot, conduct notes (read and record), class history, enrolments,
 * topic strengths, and the teacher's own remarks.
 *
 * Every endpoint behind this checks the teacher teaches the child, so a
 * student from another class simply returns "not permitted". A conduct note
 * tells the child's guardians that one was recorded, never what it says.
 */

interface Quick { id: number; name: string; admission_number: string | null; gender: string | null; class: string | null; arm: string | null; status: string; admission_date: string | null; guardian_name: string | null; guardian_phone: string | null; attendance_rate: number | null; latest_average: number | null; siblings: number }
interface Conduct { id: number; type: 'positive' | 'warning' | 'incident'; title: string; description: string; incident_date: string; recorder: { name: string } | null }
interface History { id: number; action: string; created_at: string; from_class: { name: string } | null; to_class: { name: string } | null; from_arm: { name: string } | null; to_arm: { name: string } | null; session: { name: string } | null; remarks: string | null }
interface Enrolment { id: number; session: string | null; class: string | null; arm: string | null; status: string; outcome: string | null }
interface Topic { subject: string; topic: string; questions: number; percentage: number | null; category: string }
interface Remark { id: number; teacher: string | null; teacher_id: number; subject: string | null; body: string; visibility: string | null; created_at: string | null }

const TONE: Record<string, 'green' | 'amber' | 'red'> = { positive: 'green', warning: 'amber', incident: 'red' };
const today = () => new Date().toISOString().slice(0, 10);

export default function TeacherStudentFile() {
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [me, setMe] = useState<number | null>(null);
  const [quick, setQuick] = useState<Quick | null>(null);
  const [conduct, setConduct] = useState<Conduct[]>([]);
  const [history, setHistory] = useState<History[]>([]);
  const [enrolments, setEnrolments] = useState<Enrolment[]>([]);
  const [topics, setTopics] = useState<Topic[]>([]);
  const [remarks, setRemarks] = useState<Remark[]>([]);
  const [form, setForm] = useState({ type: 'positive', title: '', description: '', incident_date: today(), notify_parent: true });
  const [editing, setEditing] = useState<{ id: number; body: string } | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { fetchApi<{ id: number }>('/user').then((u) => setMe(u.id)).catch(() => undefined); }, []);

  const load = useCallback(async (id: number) => {
    setLoading(true);
    setError(null);
    try {
      const q = await fetchApi<{ student: Quick | null }>(`/student-directory/${id}/quick-view`);
      setQuick(q.student);
      const [c, h, e, t, p] = await Promise.all([
        fetchApi<{ data: Conduct[] }>(`/students/${id}/behavior-reports?per_page=50`).catch(() => ({ data: [] })),
        fetchApi<{ history: History[] }>(`/students/${id}/class-history`).catch(() => ({ history: [] })),
        fetchApi<{ enrollments: Enrolment[] }>(`/students/${id}/enrollments`).catch(() => ({ enrollments: [] as Enrolment[] })),
        fetchApi<{ data: Topic[] }>(`/students/${id}/progress/topics`).catch(() => ({ data: [] })),
        fetchApi<{ remarks: Remark[] }>(`/students/${id}/progress`).catch(() => ({ remarks: [] })),
      ]);
      setConduct(c.data);
      setHistory(h.history);
      setEnrolments(e.enrollments ?? []);
      setTopics(t.data);
      setRemarks(p.remarks ?? []);
    } catch (err) {
      setQuick(null);
      setError(errorText(err, 'You can open the file only of a student you teach.'));
    } finally {
      setLoading(false);
    }
  }, []);

  function pick(s: PickedStudent | null) {
    setStudent(s);
    setNotice(null);
    if (s) void load(s.id);
    else setQuick(null);
  }

  async function act(fn: () => Promise<{ message?: string }>, fallback: string) {
    if (!student) return;
    setBusy(true);
    setError(null);
    try {
      const r = await fn();
      setNotice(r.message ?? 'Saved.');
      await load(student.id);
    } catch (err) {
      setError(errorText(err, fallback));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell title="Student file" subtitle="For the students you teach: conduct, history, strengths and your notes.">
      <div className="mb-5 max-w-lg"><Field label="Student"><StudentPicker value={student} onChange={pick} /></Field></div>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {!student ? <Empty>Find a student by name or admission number.</Empty> : loading ? <Loading /> : quick && (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Class" value={[quick.class, quick.arm].filter(Boolean).join(' ') || '—'} hint={`${quick.admission_number ?? ''} · ${quick.status}`} />
            <StatTile label="Attendance this session" value={quick.attendance_rate != null ? `${quick.attendance_rate}%` : '—'} tone="green" />
            <StatTile label="Latest average" value={quick.latest_average ?? '—'} tone="blue" hint="this term, not yet released" />
            <StatTile label="Parent / guardian" value={<span className="text-base">{quick.guardian_name ?? '—'}</span>} hint={quick.guardian_phone ?? undefined} />
          </div>
          <p className="text-sm"><Link href={`/progress?student_id=${quick.id}`} className="text-brand underline">Full progress and analysis</Link></p>

          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Conduct">
              {conduct.length === 0 ? <p className="text-sm text-ink-muted">Nothing recorded.</p> : (
                <ul className="space-y-3 text-sm">
                  {conduct.map((c) => (
                    <li key={c.id}>
                      <p><Badge tone={TONE[c.type]}>{c.type}</Badge> <span className="font-medium">{c.title}</span> <span className="text-xs text-ink-muted">{c.incident_date?.slice(0, 10)} · {c.recorder?.name}</span></p>
                      <p className="mt-1 whitespace-pre-wrap text-ink-muted">{c.description}</p>
                    </li>
                  ))}
                </ul>
              )}
              <form className="mt-4 space-y-2 border-t border-line-soft pt-4" onSubmit={(e) => { e.preventDefault(); void act(() => fetchApi('/students/behavior-report', { method: 'POST', body: JSON.stringify({ ...form, student_id: student.id }) }).then((r) => { setForm({ ...form, title: '', description: '' }); return r as { message?: string }; }), 'Could not record it.'); }}>
                <div className="grid gap-2 sm:grid-cols-3">
                  <Field label="Kind"><select className={inputClass} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}><option value="positive">Commendation</option><option value="warning">Warning</option><option value="incident">Incident</option></select></Field>
                  <Field label="Date"><input className={inputClass} type="date" required value={form.incident_date} onChange={(e) => setForm({ ...form, incident_date: e.target.value })} /></Field>
                  <Field label="Title"><input className={inputClass} required maxLength={255} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                </div>
                <Field label="What happened"><textarea className={`${inputClass} min-h-[4rem]`} required value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.notify_parent} onChange={(e) => setForm({ ...form, notify_parent: e.target.checked })} /> Tell the parent a note was recorded (they read it in the app)</label>
                <Button type="submit" disabled={busy}>Record</Button>
              </form>
            </Card>

            <Card title="Your notes on this student" description="Remarks from the progress page. You can change or remove your own.">
              {remarks.length === 0 ? <p className="text-sm text-ink-muted">No notes yet. Add one on the progress page.</p> : (
                <ul className="space-y-3 text-sm">
                  {remarks.map((r) => (
                    <li key={r.id}>
                      {editing?.id === r.id ? (
                        <div className="space-y-2">
                          <textarea className={`${inputClass} min-h-[4rem]`} value={editing.body} onChange={(e) => setEditing({ ...editing, body: e.target.value })} />
                          <div className="flex gap-2">
                            <Button disabled={busy || editing.body.trim().length < 3} onClick={() => act(() => fetchApi(`/student-remarks/${r.id}`, { method: 'PUT', body: JSON.stringify({ body: editing.body.trim() }) }).then((x) => { setEditing(null); return x as { message?: string }; }), 'Could not save it.')}>Save</Button>
                            <Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                          </div>
                        </div>
                      ) : (
                        <>
                          <p className="whitespace-pre-wrap">{r.body}</p>
                          <p className="text-xs text-ink-muted">{[r.teacher, r.subject, r.created_at, r.visibility === 'student_visible' ? 'student can see' : 'staff only'].filter(Boolean).join(' · ')}
                            {r.teacher_id === me && <>
                              {' '}<button type="button" className="underline" onClick={() => setEditing({ id: r.id, body: r.body })}>Edit</button>
                              {' '}<button type="button" className="text-negative underline" onClick={() => { if (window.confirm('Remove this note?')) void act(() => fetchApi(`/student-remarks/${r.id}`, { method: 'DELETE' }), 'Could not remove it.'); }}>Remove</button>
                            </>}
                          </p>
                        </>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card title="Topics" description="From marked work and tests.">
              {topics.length === 0 ? <p className="text-sm text-ink-muted">Not enough marked work yet.</p> : (
                <ul className="space-y-1 text-sm">
                  {topics.map((t) => <li key={`${t.subject}-${t.topic}`}>{t.topic} <span className="text-ink-muted">· {t.subject} · {t.questions} q</span> <Badge tone={t.category === 'strong' ? 'green' : t.category === 'developing' ? 'amber' : t.category === 'needs_attention' ? 'red' : 'slate'}>{t.percentage != null ? `${t.percentage}%` : t.category.replaceAll('_', ' ')}</Badge></li>)}
                </ul>
              )}
            </Card>

            <Card title="Class history and enrolment">
              {history.length === 0 && enrolments.length === 0 ? <p className="text-sm text-ink-muted">Nothing recorded.</p> : (
                <ul className="space-y-1 text-sm">
                  {enrolments.map((e) => <li key={`e${e.id}`}><span className="font-medium">{e.session}</span> · {[e.class, e.arm].filter(Boolean).join(' ')} <Badge tone={e.status === 'active' ? 'green' : 'slate'}>{e.outcome ?? e.status}</Badge></li>)}
                  {history.map((h) => <li key={`h${h.id}`} className="text-ink-muted">{h.created_at?.slice(0, 10)} · {h.action.replaceAll('_', ' ')}: {[h.from_class?.name, h.from_arm?.name].filter(Boolean).join(' ') || '—'} → {[h.to_class?.name, h.to_arm?.name].filter(Boolean).join(' ') || '—'}{h.remarks ? ` (${h.remarks})` : ''}</li>)}
                </ul>
              )}
            </Card>
          </div>
        </div>
      )}
    </PageShell>
  );
}
