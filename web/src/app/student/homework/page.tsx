'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, StatTile, inputClass } from '@/components/Page';
import { day, fileSize, openMaterial } from '@/lib/student';

/**
 * Homework (TASK 08): read the instructions, open the attached materials,
 * hand in typed work or files, resubmit until it is marked, and read the
 * teacher's feedback. Lateness and the late policy are decided by the API at
 * the moment of submission; this page only reports what it says.
 */

interface Homework {
  id: number; title: string; description: string | null; due_date: string | null; subject: string | null; teacher: string | null;
  max_score: number | null; submission_type: 'text' | 'file' | 'text_or_file'; allow_late: boolean;
  materials: { id: number; title: string; type: string }[];
  submitted: boolean; submitted_at: string | null; status: string; overdue: boolean;
  marks_awarded: number | null; marks_available: number | null; teacher_feedback: string | null; return_note: string | null;
}
interface Mine { submission: { body: string | null; is_late: boolean; submitted_at: string | null } | null; files: { id: number; name: string; size_bytes: number | null }[] }

type Filter = 'todo' | 'handed_in' | 'marked' | 'all';

const STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'blue' | 'slate' }> = {
  not_submitted: { label: 'to do', tone: 'amber' },
  submitted: { label: 'handed in', tone: 'blue' },
  graded: { label: 'marked', tone: 'green' },
  returned: { label: 'returned for correction', tone: 'amber' },
};

const stateOf = (h: Homework) => (h.overdue ? { label: 'missed', tone: 'red' as const } : STATUS[h.status] ?? { label: h.status, tone: 'slate' as const });

export default function StudentHomework() {
  const [items, setItems] = useState<Homework[] | null>(null);
  const [filter, setFilter] = useState<Filter>('todo');
  const [open, setOpen] = useState<Homework | null>(null);
  const [mine, setMine] = useState<Mine | null>(null);
  const [body, setBody] = useState('');
  const [files, setFiles] = useState<FileList | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Homework[] }>('/academics/homework?include_past=1&per_page=100');
      setItems(res.data);
      return res.data;
    } catch (err) {
      setError(errorText(err, 'Could not load your homework.'));
      return [];
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function select(h: Homework) {
    setOpen(h);
    setMine(null);
    setNotice(null);
    setError(null);
    setFiles(null);
    try {
      const m = await fetchApi<Mine>(`/academics/homework/${h.id}/my-submission`);
      setMine(m);
      setBody(m.submission?.body ?? '');
    } catch (err) {
      setError(errorText(err, 'Could not load your submission.'));
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!open) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      let res: { message: string };
      if (files && files.length > 0) {
        const form = new FormData();
        if (body.trim()) form.append('body', body);
        Array.from(files).forEach((f) => form.append('files[]', f));
        res = await uploadApi<{ message: string }>(`/academics/homework/${open.id}/submit`, form);
      } else {
        res = await fetchApi<{ message: string }>(`/academics/homework/${open.id}/submit`, { method: 'POST', body: JSON.stringify({ body }) });
      }
      setNotice(res.message);
      const fresh = await load();
      const again = fresh.find((h) => h.id === open.id);
      if (again) await select(again);
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'Could not hand this in.'));
    } finally {
      setBusy(false);
    }
  }

  const counts = {
    todo: items?.filter((h) => ['not_submitted', 'returned'].includes(h.status) && !h.overdue).length ?? 0,
    handed_in: items?.filter((h) => h.status === 'submitted').length ?? 0,
    marked: items?.filter((h) => h.status === 'graded').length ?? 0,
    missed: items?.filter((h) => h.overdue).length ?? 0,
  };
  const shown = (items ?? []).filter((h) =>
    filter === 'all' ? true
      : filter === 'todo' ? ['not_submitted', 'returned'].includes(h.status)
      : filter === 'handed_in' ? h.status === 'submitted'
      : h.status === 'graded');

  const canSubmit = open && open.status !== 'graded' && !(open.overdue && !open.allow_late && !open.submitted);
  const wantsText = open?.submission_type !== 'file';
  const wantsFile = open?.submission_type !== 'text';

  return (
    <PageShell title="Homework" subtitle="Everything your teachers have set you.">
      <ErrorNote message={error} />
      <div className="mb-5 grid gap-4 sm:grid-cols-4">
        <StatTile label="To do" value={counts.todo} tone={counts.todo ? 'amber' : 'slate'} />
        <StatTile label="Handed in" value={counts.handed_in} tone="blue" />
        <StatTile label="Marked" value={counts.marked} tone="green" />
        <StatTile label="Missed" value={counts.missed} tone={counts.missed ? 'red' : 'slate'} />
      </div>

      <div className="grid gap-5 lg:grid-cols-[22rem_1fr]">
        <div>
          <div className="mb-3 flex flex-wrap gap-2" role="group" aria-label="Filter">
            {([['todo', 'To do'], ['handed_in', 'Handed in'], ['marked', 'Marked'], ['all', 'All']] as [Filter, string][]).map(([k, l]) => (
              <Button key={k} variant={filter === k ? 'primary' : 'secondary'} onClick={() => setFilter(k)}>{l}</Button>
            ))}
          </div>
          {!items ? <Loading /> : shown.length === 0 ? <Empty>Nothing here.</Empty> : (
            <ul className="space-y-2">
              {shown.map((h) => {
                const st = stateOf(h);

                return (
                  <li key={h.id}>
                    <button type="button" onClick={() => select(h)} className={`w-full rounded-xl bg-surface p-4 text-left shadow-sm ring-1 hover:ring-brand ${open?.id === h.id ? 'ring-brand' : 'ring-line-soft'}`}>
                      <span className="flex items-start justify-between gap-2">
                        <span className="font-medium">{h.title}</span>
                        <Badge tone={st.tone}>{st.label}</Badge>
                      </span>
                      <span className="mt-1 block text-xs text-ink-muted">{[h.subject, h.due_date && `due ${day(h.due_date)}`].filter(Boolean).join(' · ')}</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        <div>
          {!open ? <Empty>Choose an assignment to see it and hand it in.</Empty> : (
            <div className="space-y-5">
              <Card title={open.title} description={[open.subject, open.teacher, open.due_date && `due ${day(open.due_date)}`, open.max_score !== null && `${open.max_score} marks`].filter(Boolean).join(' · ')}>
                {open.description ? <p className="whitespace-pre-wrap text-sm">{open.description}</p> : <p className="text-sm text-ink-muted">No written instructions.</p>}
                {open.materials.length > 0 && (
                  <ul className="mt-4 space-y-1 text-sm">
                    {open.materials.map((m) => (
                      <li key={m.id}><button type="button" className="text-brand hover:underline" onClick={() => openMaterial(m).catch((err) => setError(errorText(err, 'Could not open.')))}>📎 {m.title}</button></li>
                    ))}
                  </ul>
                )}
                {!open.allow_late && <p className="mt-3 text-xs text-ink-muted">Late work is not accepted for this assignment.</p>}
              </Card>

              {open.status === 'graded' && (
                <Card title="Marked">
                  <p className="font-headline text-2xl font-bold text-positive">{open.marks_awarded ?? '—'}<span className="text-base font-normal text-ink-muted"> / {open.marks_available ?? open.max_score ?? '—'}</span></p>
                  {open.teacher_feedback && <p className="mt-2 whitespace-pre-wrap text-sm">{open.teacher_feedback}</p>}
                </Card>
              )}
              {open.status === 'returned' && open.return_note && (
                <p className="rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">Returned for correction: {open.return_note}</p>
              )}
              {notice && <p role="status" className="rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

              {mine?.submission && (
                <Card title="What you handed in" description={[mine.submission.submitted_at && `on ${day(mine.submission.submitted_at)}`, mine.submission.is_late && 'late'].filter(Boolean).join(' · ')}>
                  {mine.submission.body && <p className="whitespace-pre-wrap text-sm">{mine.submission.body}</p>}
                  {mine.files.length > 0 && (
                    <ul className="mt-3 space-y-1 text-sm">
                      {mine.files.map((f) => <li key={f.id}><a className="text-brand hover:underline" href={`/api/backend/academics/submission-files/${f.id}`}>{f.name}</a> <span className="text-ink-muted">{fileSize(f.size_bytes)}</span></li>)}
                    </ul>
                  )}
                </Card>
              )}

              {canSubmit ? (
                <Card title={open.submitted ? 'Change your answer' : 'Hand it in'} description={open.submitted ? 'You can change it until your teacher marks it.' : undefined}>
                  <form onSubmit={submit} className="space-y-3">
                    {wantsText && (
                      <Field label={open.submission_type === 'text' ? 'Your answer' : 'Your answer (or attach files below)'}>
                        <textarea className={`${inputClass} min-h-[10rem]`} value={body} maxLength={20000} onChange={(e) => setBody(e.target.value)} required={open.submission_type === 'text'} />
                      </Field>
                    )}
                    {wantsFile && (
                      <Field label={`Files (up to 5)${open.submitted && mine?.files.length ? ' — new files replace the ones you sent before' : ''}`}>
                        <input type="file" multiple className="block text-sm" accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.jpg,.jpeg,.png,.webp,.heic,.ppt,.pptx,.mp3,.m4a,.mp4" onChange={(e) => setFiles(e.target.files)} required={open.submission_type === 'file' && !mine?.files.length} />
                      </Field>
                    )}
                    <Button type="submit" variant="accent" disabled={busy}>{busy ? 'Sending…' : open.submitted ? 'Resubmit' : 'Hand in'}</Button>
                  </form>
                </Card>
              ) : open.status !== 'graded' && (
                <p className="text-sm text-ink-muted">The deadline has passed and this assignment does not accept late work.</p>
              )}
            </div>
          )}
        </div>
      </div>
    </PageShell>
  );
}
