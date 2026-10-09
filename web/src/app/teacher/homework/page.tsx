'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { useClassSubjects } from '@/lib/useClassSubjects';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * Homework, the teacher's half (TASK 08): set it (or save a draft), publish,
 * see who has and has not handed in, mark one by one or the whole class at
 * once, and send work back for correction.
 *
 * The marking list is every child the work was set for, handed in or not —
 * "who owes me this" is the question on the morning it is due.
 */

interface Assignment { subject_id: number; subject_name: string; class_id: number | null; class_name: string | null }
interface ClassRow { id: number; name: string; arms: { id: number; name: string }[] }
interface Homework { id: number; title: string; description: string | null; due_date: string | null; class_id: number; class_name: string | null; subject: string | null; max_score: number | null; submission_type: string; publish_status: string }
interface Row { student_id: number; student_name: string | null; admission_number: string | null; submitted: boolean; submitted_at: string | null; is_late: boolean; status: string; marks_awarded: number | null; submission_id: number | null; body: string | null; parent_assisted: boolean; resubmissions: number; files: { id: number; name: string; size_bytes: number | null }[] }
interface Sheet { summary: { class_size: number; submitted: number; outstanding: number; late: number; marked: number }; submissions: Row[] }

const blank = { class_id: '', arm_id: '', subject_id: '', title: '', description: '', due_date: '', max_score: '10', submission_type: 'text', allow_late: true, allow_parent_assist: false };

export default function TeacherHomework() {
  const [list, setList] = useState<Homework[] | null>(null);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [classes, setClasses] = useState<ClassRow[]>([]);
  const [form, setForm] = useState(blank);
  const subjects = useClassSubjects(form.class_id, form.arm_id);
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<Homework | null>(null);
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [marks, setMarks] = useState<Record<number, { marks: string; feedback: string }>>({});
  const [viewing, setViewing] = useState<Row | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setList((await fetchApi<{ data: Homework[] }>('/academics/homework?include_past=1&per_page=100')).data);
    } catch (err) {
      setError(errorText(err, 'Could not load your homework.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: ClassRow[] }>('/classes').then((r) => setClasses(r.data)).catch(() => undefined);
    fetchApi<{ id: number }>('/user')
      .then((u) => fetchApi<{ data: Assignment[] }>(`/teachers/${u.id}/subjects`))
      .then((r) => setAssignments(r.data))
      .catch(() => undefined);
  }, [load]);

  // A teacher picks from what they teach; with no assignments (or as an admin), from everything.
  const classOptions = useMemo(() => {
    const ids = new Set(assignments.map((a) => a.class_id).filter(Boolean));
    return ids.size ? classes.filter((c) => ids.has(c.id)) : classes;
  }, [assignments, classes]);
  const subjectOptions = useMemo(() => {
    const mine = assignments.filter((a) => !form.class_id || a.class_id === null || String(a.class_id) === form.class_id);
    return mine.length ? [...new Map(mine.map((a) => [a.subject_id, { id: a.subject_id, name: a.subject_name }])).values()] : subjects;
  }, [assignments, subjects, form.class_id]);
  const arms = classes.find((c) => String(c.id) === form.class_id)?.arms ?? [];

  async function create(e: React.FormEvent, draft: boolean) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ message: string }>('/academics/homework', {
        method: 'POST',
        body: JSON.stringify({
          ...form,
          class_id: Number(form.class_id), subject_id: Number(form.subject_id), arm_id: form.arm_id ? Number(form.arm_id) : null,
          max_score: form.max_score ? Number(form.max_score) : null, draft,
        }),
      });
      setNotice(res.message);
      setForm(blank);
      setCreating(false);
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not set this homework.'));
    } finally {
      setBusy(false);
    }
  }

  async function select(h: Homework) {
    setOpen(h);
    setSheet(null);
    setViewing(null);
    setNotice(null);
    try {
      const s = await fetchApi<Sheet>(`/academics/homework/${h.id}/submissions`);
      setSheet(s);
      setMarks(Object.fromEntries(s.submissions.filter((r) => r.submission_id).map((r) => [r.submission_id as number, { marks: r.marks_awarded !== null ? String(r.marks_awarded) : '', feedback: '' }])));
    } catch (err) {
      setError(errorText(err, 'Could not load submissions.'));
    }
  }

  async function act(fn: () => Promise<{ message?: string }>, fallback: string) {
    if (!open) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fn();
      setNotice(res.message ?? 'Saved.');
      await select(open);
      await load();
    } catch (err) {
      setError(errorText(err, fallback));
    } finally {
      setBusy(false);
    }
  }

  const available = open?.max_score ?? 10;
  const typed = Object.entries(marks).filter(([, m]) => m.marks !== '');

  return (
    <PageShell title="Homework" subtitle="Set work, see who has handed in, and mark it." actions={<Button variant="accent" onClick={() => setCreating(!creating)}>{creating ? 'Close' : 'Set homework'}</Button>}>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {creating && (
        <Card title="New homework" className="mb-5">
          <form onSubmit={(e) => create(e, false)} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Class">
              <select className={inputClass} required value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value, arm_id: '', subject_id: '' })}>
                <option value="">Choose…</option>{classOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="Arm (optional)">
              <select className={inputClass} value={form.arm_id} disabled={!arms.length} onChange={(e) => setForm({ ...form, arm_id: e.target.value })}>
                <option value="">Whole class</option>{arms.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </Field>
            <Field label="Subject">
              <select className={inputClass} required value={form.subject_id} onChange={(e) => setForm({ ...form, subject_id: e.target.value })}>
                <option value="">Choose…</option>{subjectOptions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
            <Field label="Title"><input className={inputClass} required maxLength={255} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
            <Field label="Due date"><input className={inputClass} type="date" required value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} /></Field>
            <Field label="Marks"><input className={inputClass} type="number" min={1} max={1000} value={form.max_score} onChange={(e) => setForm({ ...form, max_score: e.target.value })} /></Field>
            <div className="sm:col-span-2 lg:col-span-3">
              <Field label="Instructions"><textarea className={`${inputClass} min-h-[6rem]`} required value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
            </div>
            <Field label="Students hand in">
              <select className={inputClass} value={form.submission_type} onChange={(e) => setForm({ ...form, submission_type: e.target.value })}>
                <option value="text">Typed answer</option><option value="file">A file (photo, document)</option><option value="text_or_file">Either</option>
              </select>
            </Field>
            <label className="flex items-center gap-2 self-end text-sm"><input type="checkbox" checked={form.allow_late} onChange={(e) => setForm({ ...form, allow_late: e.target.checked })} /> Accept late work</label>
            <label className="flex items-center gap-2 self-end text-sm"><input type="checkbox" checked={form.allow_parent_assist} onChange={(e) => setForm({ ...form, allow_parent_assist: e.target.checked })} /> A parent may hand in for a young child</label>
            <div className="flex gap-2 sm:col-span-2 lg:col-span-3">
              <Button type="submit" disabled={busy}>Set and tell students</Button>
              <Button type="button" variant="secondary" disabled={busy} onClick={(e) => create(e as unknown as React.FormEvent, true)}>Save as draft</Button>
            </div>
          </form>
        </Card>
      )}

      <div className="grid gap-5 lg:grid-cols-[22rem_1fr]">
        <div>
          {!list ? <Loading /> : list.length === 0 ? <Empty>You have not set any homework yet.</Empty> : (
            <ul className="space-y-2">
              {list.map((h) => (
                <li key={h.id}>
                  <button type="button" onClick={() => select(h)} className={`w-full rounded-xl bg-surface p-4 text-left shadow-sm ring-1 hover:ring-brand ${open?.id === h.id ? 'ring-brand' : 'ring-line-soft'}`}>
                    <span className="flex justify-between gap-2"><span className="font-medium">{h.title}</span>{h.publish_status !== 'published' && <Badge tone="amber">{h.publish_status}</Badge>}</span>
                    <span className="mt-1 block text-xs text-ink-muted">{[h.class_name, h.subject, h.due_date && `due ${h.due_date}`].filter(Boolean).join(' · ')}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          {!open ? <Empty>Choose an assignment to see submissions and mark them.</Empty> : (
            <div className="space-y-5">
              <Card title={open.title} description={[open.class_name, open.subject, open.due_date && `due ${open.due_date}`, `${available} marks`].filter(Boolean).join(' · ')}>
                <p className="whitespace-pre-wrap text-sm">{open.description}</p>
                {open.publish_status === 'draft' && (
                  <Button className="mt-3" variant="accent" disabled={busy} onClick={() => act(() => fetchApi(`/academics/homework/${open.id}/publish`, { method: 'POST', body: '{}' }), 'Could not publish.')}>Publish to students</Button>
                )}
              </Card>

              {!sheet ? <Loading /> : (
                <>
                  <div className="grid gap-4 sm:grid-cols-4">
                    <StatTile label="Handed in" value={`${sheet.summary.submitted}/${sheet.summary.class_size}`} tone="blue" />
                    <StatTile label="Outstanding" value={sheet.summary.outstanding} tone={sheet.summary.outstanding ? 'amber' : 'slate'} />
                    <StatTile label="Late" value={sheet.summary.late} tone={sheet.summary.late ? 'red' : 'slate'} />
                    <StatTile label="Marked" value={sheet.summary.marked} tone="green" />
                  </div>

                  <Card title="Marking sheet" description={`Out of ${available}. Type marks for as many as you like, then save them all at once.`}>
                    <Table columns={['Student', 'Status', 'Work', `Mark /${available}`, 'Feedback', '']}>
                      {sheet.submissions.map((r) => {
                        const m = r.submission_id ? marks[r.submission_id] : undefined;
                        return (
                          <tr key={r.student_id}>
                            <Cell className="font-medium">{r.student_name}<span className="block text-xs text-ink-muted">{r.admission_number}</span></Cell>
                            <Cell>
                              <Badge tone={r.status === 'graded' ? 'green' : r.status === 'returned' ? 'amber' : r.submitted ? 'blue' : 'red'}>{r.submitted ? r.status.replaceAll('_', ' ') : 'not handed in'}</Badge>
                              {r.is_late && <Badge tone="red">late</Badge>}
                              {r.parent_assisted && <span className="block text-xs text-ink-muted">by parent</span>}
                            </Cell>
                            <Cell>{r.submitted ? <button type="button" className="text-sm text-brand underline" onClick={() => setViewing(r)}>Read</button> : '—'}</Cell>
                            <Cell>{r.submission_id ? <input className={`${inputClass} w-20`} type="number" min={0} max={available} step="0.5" value={m?.marks ?? ''} onChange={(e) => setMarks({ ...marks, [r.submission_id as number]: { marks: e.target.value, feedback: m?.feedback ?? '' } })} aria-label={`Mark for ${r.student_name}`} /> : '—'}</Cell>
                            <Cell>{r.submission_id ? <input className={inputClass} placeholder="Optional" value={m?.feedback ?? ''} onChange={(e) => setMarks({ ...marks, [r.submission_id as number]: { marks: m?.marks ?? '', feedback: e.target.value } })} aria-label={`Feedback for ${r.student_name}`} /> : null}</Cell>
                            <Cell>{r.submission_id && r.status !== 'graded' && (
                              <button type="button" className="text-xs text-ink-muted underline" onClick={() => {
                                const note = window.prompt(`What should ${r.student_name} correct?`);
                                if (note && note.trim().length >= 3) void act(() => fetchApi(`/academics/submissions/${r.submission_id}/return`, { method: 'POST', body: JSON.stringify({ note: note.trim() }) }), 'Could not return it.');
                              }}>Return</button>
                            )}</Cell>
                          </tr>
                        );
                      })}
                    </Table>
                    <Button className="mt-4" disabled={busy || typed.length === 0} onClick={() => act(() => fetchApi(`/academics/homework/${open.id}/bulk-grade`, {
                      method: 'POST',
                      body: JSON.stringify({ marks_available: available, marks: typed.map(([id, m]) => ({ submission_id: Number(id), marks_awarded: Number(m.marks), teacher_feedback: m.feedback || undefined })) }),
                    }), 'Could not save the marks.')}>Save {typed.length} mark(s)</Button>
                  </Card>
                </>
              )}

              {viewing && (
                <Card title={`${viewing.student_name}’s work`} description={[viewing.submitted_at && `handed in ${viewing.submitted_at.slice(0, 10)}`, viewing.resubmissions ? `resubmitted ${viewing.resubmissions}×` : null].filter(Boolean).join(' · ')}>
                  {viewing.body ? <p className="whitespace-pre-wrap text-sm">{viewing.body}</p> : <p className="text-sm text-ink-muted">No typed answer.</p>}
                  {viewing.files.length > 0 && (
                    <ul className="mt-3 space-y-1 text-sm">{viewing.files.map((f) => <li key={f.id}><a className="text-brand hover:underline" href={`/api/backend/academics/submission-files/${f.id}`}>{f.name}</a></li>)}</ul>
                  )}
                  <Button variant="secondary" className="mt-3" onClick={() => setViewing(null)}>Close</Button>
                </Card>
              )}
            </div>
          )}
        </div>
      </div>
    </PageShell>
  );
}
