'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import ChildSwitcher, { useChildren } from '@/components/ChildSwitcher';

/**
 * A child's homework, for their parent (TASK 08): what is set, what is
 * handed in, what came back marked. For a young child, where the teacher
 * allowed it and the school permits it for that section, a parent may hand
 * the work in on the child's behalf — the API says so when it is not allowed.
 */

interface Hw { id: number; title: string; description: string | null; due_date: string | null; subject: string | null; teacher: string | null; submission_type: string; status: string; overdue: boolean; marks_awarded: number | null; marks_available: number | null; teacher_feedback: string | null; return_note: string | null; materials: { id: number; title: string; type: string }[] }

const STATE = (h: Hw) => (h.overdue ? ['missed', 'red'] : ({ not_submitted: ['to do', 'amber'], submitted: ['handed in', 'blue'], graded: ['marked', 'green'], returned: ['returned for correction', 'amber'] } as Record<string, [string, string]>)[h.status] ?? [h.status, 'slate']) as [string, 'red' | 'amber' | 'blue' | 'green' | 'slate'];

export default function ParentHomework() {
  const { children, selected, select, error: childError } = useChildren();
  const [items, setItems] = useState<Hw[] | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const [body, setBody] = useState('');
  const [files, setFiles] = useState<FileList | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!selected) return;
    try {
      setItems((await fetchApi<{ data: Hw[] }>(`/academics/homework?student_id=${selected.id}&include_past=1&per_page=100`)).data);
    } catch (err) {
      setError(errorText(err, 'Could not load homework.'));
    }
  }, [selected]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function handIn(h: Hw) {
    if (!selected) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('student_id', String(selected.id));
      if (body.trim()) form.append('body', body);
      if (files) Array.from(files).forEach((f) => form.append('files[]', f));
      const r = await uploadApi<{ message: string }>(`/parent/homework/${h.id}/submit`, form);
      setNotice(r.message);
      setOpen(null);
      setBody('');
      setFiles(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not hand it in.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell title="Homework" subtitle="What your child has been set, and how it went.">
      <ErrorNote message={childError ?? error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {!children ? <Loading /> : children.length === 0 ? <Empty>No child is linked to your account yet.</Empty> : (
        <>
          <ChildSwitcher items={children} selected={selected} onSelect={select} />
          {!items ? <Loading /> : items.length === 0 ? <Empty>No homework set recently.</Empty> : (
            <ul className="space-y-3">
              {items.map((h) => {
                const [label, tone] = STATE(h);
                return (
                  <li key={h.id}>
                    <Card>
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                          <p className="font-medium">{h.title}</p>
                          <p className="text-xs text-ink-muted">{[h.subject, h.teacher, h.due_date && `due ${h.due_date}`].filter(Boolean).join(' · ')}</p>
                        </div>
                        <Badge tone={tone}>{label}</Badge>
                      </div>
                      {h.description && <p className="mt-2 whitespace-pre-wrap text-sm">{h.description}</p>}
                      {h.status === 'graded' && <p className="mt-2 text-sm"><span className="font-semibold text-positive">{h.marks_awarded} / {h.marks_available}</span>{h.teacher_feedback && <span className="text-ink-muted"> — {h.teacher_feedback}</span>}</p>}
                      {h.return_note && <p className="mt-2 text-sm text-caution">Teacher asks: {h.return_note}</p>}
                      {['not_submitted', 'returned'].includes(h.status) && (
                        open === h.id ? (
                          <div className="mt-3 space-y-2 border-t border-line-soft pt-3">
                            <p className="text-xs text-ink-muted">For young children, where the teacher allows it. Your child’s own work, sent by you.</p>
                            {h.submission_type !== 'file' && <Field label="Answer"><textarea className={`${inputClass} min-h-[5rem]`} value={body} onChange={(e) => setBody(e.target.value)} /></Field>}
                            {h.submission_type !== 'text' && <Field label="Photo or file of the work"><input type="file" multiple accept="image/*,.pdf,.doc,.docx" className="block text-sm" onChange={(e) => setFiles(e.target.files)} /></Field>}
                            <div className="flex gap-2"><Button disabled={busy} onClick={() => handIn(h)}>Hand in for my child</Button><Button variant="secondary" onClick={() => setOpen(null)}>Cancel</Button></div>
                          </div>
                        ) : <button type="button" className="mt-2 text-sm text-brand underline" onClick={() => { setOpen(h.id); setBody(''); setFiles(null); }}>Hand in on my child’s behalf</button>
                      )}
                    </Card>
                  </li>
                );
              })}
            </ul>
          )}
        </>
      )}
    </PageShell>
  );
}
