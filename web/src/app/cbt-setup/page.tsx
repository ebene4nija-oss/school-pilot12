'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { useClassSubjects } from '@/lib/useClassSubjects';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * CBT exams (§7.8): the list, and a new draft. Each exam opens in its own
 * editor (/cbt-setup/{id}) for questions, settings, publishing, results and
 * marking; questions themselves live in the Question bank.
 *
 * This page used to post a free-text subject the API does not accept — every
 * create failed — and rendered the API's subject object as text, which
 * crashes React as soon as one exam exists.
 */

interface Exam { id: number; title: string; status: string; subject: { name: string } | null; class_id: number | null; duration_minutes: number; opens_at: string | null; closes_at: string | null; exam_questions_count: number; attempts_count: number; total_marks: number | string | null }

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const TONE: Record<string, 'green' | 'amber' | 'slate' | 'blue'> = { published: 'green', draft: 'amber', closed: 'slate', pending_approval: 'blue' };

export default function CbtSetupPage() {
  const [exams, setExams] = useState<Exam[] | null>(null);
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([]);
  const [terms, setTerms] = useState<{ id: number; name: string; session_name?: string | null }[]>([]);
  const [form, setForm] = useState({ title: '', subject_id: '', class_id: '', term_id: '', duration_minutes: '45', opens_at: '', closes_at: '' });
  const subjects = useClassSubjects(form.class_id);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const data = await fetchApi<{ data: Exam[] }>('/cbt/exams?per_page=100');
      setExams(data.data ?? []);
    } catch (err) {
      setError(errorText(err, 'Could not load the exam list.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: { id: number; name: string }[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string; session_name?: string | null }[]; current_term_id: number | null }>('/terms').then((r) => {
      setTerms(r.data ?? []);
      if (r.current_term_id) setForm((f) => ({ ...f, term_id: String(r.current_term_id) }));
    }).catch(() => undefined);
  }, [load]);

  async function create(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ exam: { id: number } }>('/cbt/exams', {
        method: 'POST',
        body: JSON.stringify({
          title: form.title, subject_id: Number(form.subject_id), class_id: form.class_id ? Number(form.class_id) : null,
          term_id: form.term_id ? Number(form.term_id) : null, duration_minutes: Number(form.duration_minutes) || 45,
          opens_at: form.opens_at || null, closes_at: form.closes_at || null, shuffle_questions: true, shuffle_options: true,
        }),
      });
      window.location.href = `/cbt-setup/${res.exam.id}`;
    } catch (err) {
      setError(errorText(err, 'Could not create that exam.'));
      setBusy(false);
    }
  }

  const className = (id: number | null) => (id ? classes.find((c) => c.id === id)?.name ?? '' : 'All classes');

  return (
    <PageShell title="CBT exams" subtitle="Create a paper, add questions from the bank, publish it to a class, then mark and review." actions={<Link href="/cbt-bank" className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium hover:bg-surface-sunken">Question bank</Link>}>
      <ErrorNote message={error} />
      <div className="grid gap-6 xl:grid-cols-3">
        <Card title="New exam" className="h-fit">
          <form onSubmit={create} className="space-y-3">
            <Field label="Title"><input className={inputClass} required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="First term Mathematics CA 1" /></Field>
            <Field label="Class"><select className={inputClass} value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value, subject_id: '' })}><option value="">Any class</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="Subject"><select className={inputClass} required value={form.subject_id} onChange={(e) => setForm({ ...form, subject_id: e.target.value })}><option value="">Choose…</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label="Term"><select className={inputClass} value={form.term_id} onChange={(e) => setForm({ ...form, term_id: e.target.value })}><option value="">—</option>{terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}</select></Field>
            <Field label="Duration (minutes)"><input className={inputClass} type="number" min={1} max={600} value={form.duration_minutes} onChange={(e) => setForm({ ...form, duration_minutes: e.target.value })} /></Field>
            <Field label="Opens (optional)"><input className={inputClass} type="datetime-local" value={form.opens_at} onChange={(e) => setForm({ ...form, opens_at: e.target.value })} /></Field>
            <Field label="Closes (optional)"><input className={inputClass} type="datetime-local" value={form.closes_at} onChange={(e) => setForm({ ...form, closes_at: e.target.value })} /></Field>
            <Button type="submit" disabled={busy} className="w-full">{busy ? 'Creating…' : 'Create draft and add questions'}</Button>
          </form>
        </Card>

        <Card title="Exams" className="xl:col-span-2">
          {!exams ? <Loading /> : exams.length === 0 ? <Empty>No exams yet. Create a draft to get started.</Empty> : (
            <Table columns={['Title', 'Class', 'Questions', 'Window', 'Sat', 'Status']}>
              {exams.map((x) => (
                <tr key={x.id}>
                  <Cell><Link href={`/cbt-setup/${x.id}`} className="font-medium text-brand hover:underline">{x.title}</Link><span className="block text-xs text-ink-muted">{x.subject?.name} · {x.duration_minutes} min</span></Cell>
                  <Cell>{className(x.class_id)}</Cell>
                  <Cell>{x.exam_questions_count}</Cell>
                  <Cell className="text-xs">{when(x.opens_at)} → {when(x.closes_at)}</Cell>
                  <Cell>{x.attempts_count}</Cell>
                  <Cell><Badge tone={TONE[x.status] ?? 'slate'}>{x.status.replaceAll('_', ' ')}</Badge></Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>
    </PageShell>
  );
}
