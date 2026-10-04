'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, Field, Table, inputClass } from '@/components/Page';

/**
 * Fill a CA from homework and quizzes (TASK 08, 21). The teacher picks the
 * work that counts, sees each student's suggested mark, changes any of them,
 * and only then enters them. Exams are never filled this way.
 */

interface Option { id: number; name: string }
interface CaPart { key: string; name: string; max: number; kind?: string }
interface Source { id: number; title: string; date?: string | null; type?: string | null; graded: number }
interface Item { source: string; title: string; percent: number | null; state: 'marked' | 'not_marked' | 'not_submitted' }
interface Row { student_id: number; name: string | null; admission_number: string | null; items: Item[]; average_percent: number | null; suggested: number | null; current: number | null; unmarked: number }

export default function CourseworkCa({ classId, termId, subjects, parts }: { classId: string; termId: string; subjects: Option[]; parts: CaPart[] }) {
  const [subjectId, setSubjectId] = useState('');
  const [component, setComponent] = useState('');
  const [sources, setSources] = useState<{ homework: Source[]; quizzes: Source[] } | null>(null);
  const [hw, setHw] = useState<number[]>([]);
  const [quiz, setQuiz] = useState<number[]>([]);
  const [missing, setMissing] = useState<'zero' | 'skip'>('zero');
  const [rows, setRows] = useState<Row[] | null>(null);
  const [final, setFinal] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const caParts = parts.filter((p) => (p.kind ?? 'ca') === 'ca');
  const scope = { term_id: Number(termId), class_id: Number(classId), subject_id: Number(subjectId) };

  useEffect(() => {
    if (!subjectId || !classId || !termId) return;
    let cancelled = false;
    (async () => {
      try {
        const res = await fetchApi<{ homework: Source[]; quizzes: Source[] }>(`/assessment/coursework-ca/sources?term_id=${termId}&class_id=${classId}&subject_id=${subjectId}`);
        if (!cancelled) { setSources(res); setHw([]); setQuiz([]); setRows(null); }
      } catch (err) {
        if (!cancelled) setError(errorText(err, 'Could not load the coursework.'));
      }
    })();
    return () => { cancelled = true; };
  }, [subjectId, classId, termId]);

  async function suggest() {
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ rows: Row[] }>('/assessment/coursework-ca/suggest', { method: 'POST', body: JSON.stringify({ ...scope, component, homework_ids: hw, exam_ids: quiz, missing }) });
      setRows(res.rows);
      setFinal(Object.fromEntries(res.rows.map((r) => [r.student_id, r.suggested === null ? '' : String(r.suggested)])));
    } catch (err) {
      setError(errorText(err, 'Could not work out the marks.'));
    }
  }

  async function apply() {
    if (!rows) return;
    setError(null);
    try {
      const res = await fetchApi<{ message: string; refused: { student_id: number; reason: string }[] }>('/assessment/coursework-ca/apply', {
        method: 'POST',
        body: JSON.stringify({ ...scope, component, homework_ids: hw, exam_ids: quiz, missing, rows: rows.map((r) => ({ student_id: r.student_id, suggested: r.suggested, score: final[r.student_id] === '' ? null : Number(final[r.student_id]) })) }),
      });
      const names = res.refused.map((x) => `${rows.find((r) => r.student_id === x.student_id)?.name}: ${x.reason}`);
      setNotice([res.message, ...names].join(' '));
    } catch (err) {
      setError(errorText(err, 'The marks could not be entered.'));
    }
  }

  const toggle = (list: number[], set: (v: number[]) => void, id: number) => set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
  const part = caParts.find((p) => p.key === component);

  if (!classId || !termId) return <Card title="CA from coursework"><Empty>Choose a class and term first.</Empty></Card>;

  return (
    <Card title="CA from coursework" description="Choose the homework and quizzes that count, check each mark, change any you need to, then enter them.">
      {error && <p role="alert" className="mb-3 text-sm text-negative">{error}</p>}
      {notice && <p role="status" className="mb-3 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Subject">
          <select value={subjectId} onChange={(e) => setSubjectId(e.target.value)} className={inputClass}>
            <option value="">Choose…</option>
            {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </Field>
        <Field label="Fills which CA">
          <select value={component} onChange={(e) => setComponent(e.target.value)} className={inputClass}>
            <option value="">Choose…</option>
            {caParts.map((p) => <option key={p.key} value={p.key}>{p.name} (out of {p.max})</option>)}
          </select>
        </Field>
        <Field label="Work not handed in">
          <select value={missing} onChange={(e) => setMissing(e.target.value as 'zero' | 'skip')} className={inputClass}>
            <option value="zero">Counts as 0</option>
            <option value="skip">Left out of the average</option>
          </select>
        </Field>
      </div>

      {sources && (
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <p className="mb-1 text-sm font-semibold">Homework</p>
            {sources.homework.length === 0 ? <p className="text-sm text-ink-muted">No marked homework this term.</p> : sources.homework.map((h) => (
              <label key={h.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={hw.includes(h.id)} onChange={() => toggle(hw, setHw, h.id)} /> {h.title} <span className="text-ink-muted">· {h.date} · {h.graded} marked</span>
              </label>
            ))}
          </div>
          <div>
            <p className="mb-1 text-sm font-semibold">Quizzes and tests</p>
            {sources.quizzes.length === 0 ? <p className="text-sm text-ink-muted">No CBT quizzes this term.</p> : sources.quizzes.map((q) => (
              <label key={q.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={quiz.includes(q.id)} onChange={() => toggle(quiz, setQuiz, q.id)} /> {q.title} <span className="text-ink-muted">· {q.graded} marked</span>
              </label>
            ))}
          </div>
        </div>
      )}

      <div className="mt-4">
        <Button onClick={suggest} disabled={!subjectId || !component || hw.length + quiz.length === 0}>Work out marks</Button>
      </div>

      {rows && (
        <div className="mt-6">
          {rows.length === 0 ? <Empty>No students.</Empty> : (
            <Table columns={['Student', 'Coursework', 'Average', 'Suggested', `Mark${part ? ` (/${part.max})` : ''}`, 'On sheet now']}>
              {rows.map((r) => (
                <tr key={r.student_id}>
                  <Cell className="font-medium">{r.name}<span className="block text-xs text-ink-muted">{r.admission_number}</span></Cell>
                  <Cell className="text-xs">
                    {r.items.map((i) => (
                      <span key={i.source} className="mr-2 inline-block">
                        {i.title}: {i.state === 'marked' ? `${i.percent}%` : i.state === 'not_marked' ? <Badge tone="amber">not marked</Badge> : <Badge tone="red">not handed in</Badge>}
                      </span>
                    ))}
                  </Cell>
                  <Cell>{r.average_percent === null ? '—' : `${r.average_percent}%`}</Cell>
                  <Cell>{r.suggested ?? '—'}</Cell>
                  <Cell>
                    <input
                      type="number" min={0} max={part?.max} step="0.5" aria-label={`Mark for ${r.name}`}
                      value={final[r.student_id] ?? ''}
                      onChange={(e) => setFinal({ ...final, [r.student_id]: e.target.value })}
                      className={`${inputClass} w-24 ${final[r.student_id] !== (r.suggested === null ? '' : String(r.suggested)) ? 'ring-2 ring-amber-400' : ''}`}
                    />
                  </Cell>
                  <Cell>{r.current ?? '—'}</Cell>
                </tr>
              ))}
            </Table>
          )}
          <p className="mt-3 text-xs text-ink-muted">Marks you change are highlighted. A blank mark is not entered. Nothing reaches the score sheet until you press Enter marks.</p>
          <Button className="mt-3" onClick={apply}>Enter marks</Button>
        </div>
      )}
    </Card>
  );
}
