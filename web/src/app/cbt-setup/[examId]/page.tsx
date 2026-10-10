'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { ApiError, errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';
import { RichText } from '@/components/RichContent';
import { TYPES } from '@/components/cbt/QuestionEditor';

/**
 * One CBT exam (§7.8): the paper, its settings, publishing, results and
 * marking. Any published paper can be sat in an exam hall on the desktop
 * app; a PC that loses its internet keeps answers locally and syncs them.
 *
 * A published paper is frozen — questions cannot be added, removed or
 * re-marked underneath candidates; only the closing time and the results
 * setting may change. Theory answers are marked here, never above what a
 * question is worth (the API refuses it).
 */

type Tab = 'paper' | 'settings' | 'results';
interface ExamQ { id: number; question_id: number; order_index: number; marks: number | string | null; negative_marks: number | string | null; section: string | null; question: { id: number; question: string; question_type: string; topic: string | null; difficulty: string | null; marks: number | string } | null }
interface Exam {
  id: number; title: string; status: string; subject_id: number; subject: { name: string } | null; class_id: number | null; instructions: string | null;
  duration_minutes: number; opens_at: string | null; closes_at: string | null; shuffle_questions: boolean; shuffle_options: boolean; shuffle_within_group: boolean;
  questions_per_attempt: number | null; max_attempts: number; negative_marking: boolean; pass_mark: number | string | null; show_results_immediately: boolean; allow_offline: boolean;
  review_answers?: boolean; review_correct_answers?: boolean; results_released_at?: string | null;
  exam_questions: ExamQ[];
}
interface Bank { id: number; question: string; question_type: string; topic: string | null; marks: number; content_format: 'plain' | 'latex' }
interface Results {
  summary: { attempts: number; awaiting_marking: number; average_percentage: number | null; highest: number | null; lowest: number | null; pass_rate: number | null };
  candidates: { attempt_id: number; student_name: string | null; percentage: number; grade: string | null; requires_manual_grading: boolean; integrity_flags: Record<string, number> | null }[];
  item_analysis: { question_id: number; answered: number; correct: number; facility_index: number | null }[];
}
interface Script { attempt_id: number; exam_title: string; percentage?: number; raw_score?: number; max_score?: number; questions?: { question_id: number; question_type: string | null; marks: number | null; question: string | null; your_response: Record<string, unknown> | null; is_correct: boolean | null; awarded_marks: number; correct_answer?: unknown; feedback: string | null }[] }

const TYPE_NAME = Object.fromEntries(TYPES);
const local = (iso: string | null) => (iso ? new Date(iso).toISOString().slice(0, 16) : '');
const show = (r: Record<string, unknown> | null) => {
  if (!r) return '— (blank)';
  if (typeof r.text === 'string') return r.text;
  if (r.parts) return Object.entries(r.parts as Record<string, string>).map(([k, v]) => `(${k}) ${v}`).join('\n');
  return JSON.stringify(r);
};

export default function ExamEditor() {
  const { examId } = useParams<{ examId: string }>();
  const [tab, setTab] = useState<Tab>('paper');
  const [exam, setExam] = useState<Exam | null>(null);
  const [total, setTotal] = useState(0);
  const [warnings, setWarnings] = useState<string[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ exam: Exam; total_marks: number }>(`/cbt/exams/${examId}`);
      setExam(res.exam);
      setTotal(res.total_marks);
      const c = await fetchApi<{ warnings: string[] }>(`/cbt/exams/${examId}/checks`).catch(() => ({ warnings: [] }));
      setWarnings(c.warnings);
    } catch (err) {
      setError(errorText(err, 'Could not load this exam.'));
    }
  }, [examId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function run(fn: () => Promise<{ message?: string; notice?: string }>, fallback: string) {
    setBusy(true);
    setError(null);
    try {
      const r = await fn();
      setNotice([r.message, r.notice].filter(Boolean).join(' ') || 'Saved.');
      await load();
    } catch (err) {
      if (err instanceof ApiError && (err.payload as { code?: string })?.code === 'approval_required') {
        setError(`${err.message} Submit it for approval on CBT monitor & regrade.`);
      } else setError(errorText(err, fallback));
    } finally {
      setBusy(false);
    }
  }

  function publish() {
    let reason: string | null = '';
    if (warnings.length) {
      reason = window.prompt(`Before publishing, note:\n\n• ${warnings.join('\n• ')}\n\nPublish anyway? Give a reason for the record:`);
      if (reason === null) return;
    } else if (!window.confirm('Publish? Students in the class can then sit it, and the questions can no longer change.')) return;
    void run(() => fetchApi(`/cbt/exams/${examId}/publish`, { method: 'POST', body: JSON.stringify(reason ? { override_reason: reason } : {}) }), 'Could not publish.');
  }

  /** Release this paper's scores to students and parents. Report cards are not affected. */
  async function releaseResults() {
    if (!window.confirm('Release the results? Students who sat this paper, and their parents, will see their scores. Term report cards are not affected.')) return;
    setBusy(true);
    setError(null);
    try {
      const r = await fetchApi<{ message: string }>(`/cbt/exams/${examId}/release-results`, { method: 'POST', body: '{}' });
      setNotice(r.message);
      await load();
    } catch (err) {
      const unmarked = err instanceof ApiError && (err.payload as { code?: string })?.code === 'unmarked_scripts';
      if (unmarked && window.confirm(`${err.message}

Release anyway?`)) {
        await run(() => fetchApi(`/cbt/exams/${examId}/release-results`, { method: 'POST', body: JSON.stringify({ include_unmarked: true }) }), 'Could not release.');
        return;
      }
      if (!unmarked) setError(errorText(err, 'Could not release.'));
    } finally {
      setBusy(false);
    }
  }

  if (!exam) return <PageShell title="Exam"><ErrorNote message={error} />{!error && <Loading />}</PageShell>;
  const draft = exam.status === 'draft';

  return (
    <PageShell
      title={exam.title}
      subtitle={[exam.subject?.name, `${exam.duration_minutes} min`, `${exam.exam_questions.length} questions`, `${total} marks`].join(' · ')}
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={exam.status === 'published' ? 'green' : draft ? 'amber' : 'slate'}>{exam.status.replaceAll('_', ' ')}</Badge>
          {draft && <Button variant="accent" disabled={busy || exam.exam_questions.length === 0} onClick={publish}>Publish</Button>}
          {exam.status === 'published' && <Button variant="secondary" disabled={busy} onClick={() => { if (window.confirm('Close the exam? No new attempts can start.')) void run(() => fetchApi(`/cbt/exams/${examId}/close`, { method: 'POST', body: '{}' }), 'Could not close.'); }}>Close</Button>}
          {!draft && !exam.show_results_immediately && (exam.results_released_at ? (
            <>
              <Badge tone="blue">Results released</Badge>
              <Button variant="secondary" disabled={busy} onClick={() => { if (window.confirm('Withdraw the results? Students and parents stop seeing the scores until you release again.')) void run(() => fetchApi(`/cbt/exams/${examId}/withdraw-results`, { method: 'POST', body: '{}' }), 'Could not withdraw.'); }}>Withdraw results</Button>
            </>
          ) : (
            <Button disabled={busy} onClick={() => void releaseResults()}>Release results</Button>
          ))}
        </div>
      }
    >
      <p className="-mt-3 mb-4 text-sm"><Link href="/cbt-setup" className="text-brand underline">← All exams</Link></p>
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['paper', 'Paper'], ['settings', 'Settings'], ['results', 'Results & marking']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {warnings.length > 0 && tab === 'paper' && (
        <div className="mb-5 rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">
          <p className="font-semibold">Check before publishing</p>
          <ul className="mt-1 list-disc pl-5">{warnings.map((w) => <li key={w}>{w}</li>)}</ul>
        </div>
      )}

      {tab === 'paper' && <Paper exam={exam} draft={draft} busy={busy} run={run} />}
      {tab === 'settings' && <Settings exam={exam} run={run} busy={busy} />}
      {tab === 'results' && <ResultsTab examId={exam.id} onError={(e, f) => setError(errorText(e, f))} onDone={setNotice} />}
    </PageShell>
  );
}

type Run = (fn: () => Promise<{ message?: string; notice?: string }>, fallback: string) => Promise<void>;

function Paper({ exam, draft, busy, run }: { exam: Exam; draft: boolean; busy: boolean; run: Run }) {
  const [search, setSearch] = useState({ search: '', topic: '', question_type: '', difficulty: '' });
  // A paper for one class shows that class's questions unless asked otherwise.
  const [otherClasses, setOtherClasses] = useState(false);
  const [bank, setBank] = useState<{ data: Bank[]; total: number } | null>(null);
  const [picked, setPicked] = useState<Record<number, { marks: string; section: string }>>({});
  const [sectionForAll, setSectionForAll] = useState('');
  const onPaper = new Set(exam.exam_questions.map((q) => q.question_id));

  // The same filters the bank list takes, so "add all matching" adds what is shown.
  const filters = useMemo(() => ({
    status: 'approved',
    subject_id: String(exam.subject_id),
    // for_class keeps questions tagged to no class; class_id alone hid them.
    ...(exam.class_id && !otherClasses ? { for_class: String(exam.class_id) } : {}),
    ...Object.fromEntries(Object.entries(search).filter(([, v]) => v)),
  }), [search, exam.subject_id, exam.class_id, otherClasses]);

  const find = useCallback(async () => {
    const r = await fetchApi<{ data: Bank[]; total: number }>(`/cbt/questions?${new URLSearchParams({ per_page: '100', ...filters })}`);
    setBank({ data: r.data, total: r.total });
  }, [filters]);

  useEffect(() => {
    if (!draft) return;
    void (async () => {
      await find().catch(() => undefined);
    })();
  }, [draft, find]);

  const attach = () => run(() => fetchApi(`/cbt/exams/${exam.id}/questions`, {
    method: 'POST',
    body: JSON.stringify({ questions: Object.entries(picked).map(([id, p]) => ({ question_id: Number(id), marks: p.marks ? Number(p.marks) : undefined, section: p.section || undefined })) }),
  }).then((r) => { setPicked({}); return r as { message?: string }; }), 'Could not add those questions.');

  const addAllMatching = () => {
    if (!bank || !window.confirm(`Add every matching question (${bank.total}) not already on the paper, with their own marks?`)) return;
    void run(() => fetchApi('/cbt/questions/bulk', { method: 'POST', body: JSON.stringify({ action: 'add_to_exam', exam_id: exam.id, filters }) })
      .then((r) => { setPicked({}); return r as { message?: string }; }), 'Could not add those questions.');
  };

  const shown = bank?.data.filter((b) => !onPaper.has(b.id)) ?? [];
  const allTicked = shown.length > 0 && shown.every((b) => picked[b.id]);
  const tickAll = (on: boolean) => setPicked(on ? Object.fromEntries(shown.map((b) => [b.id, picked[b.id] ?? { marks: '', section: sectionForAll }])) : {});

  return (
    <div className="space-y-5">
      <Card title="On this paper" description={draft ? 'Marks can be set per paper; blank means the question’s own marks.' : 'Published papers are frozen.'}>
        {exam.exam_questions.length === 0 ? <Empty>No questions yet. Add some from the bank below.</Empty> : (
          <Table columns={['#', 'Question', 'Type', 'Section', 'Marks', '']}>
            {exam.exam_questions.map((x, i) => (
              <tr key={x.id}>
                <Cell>{i + 1}</Cell>
                <Cell className="max-w-md"><RichText content={x.question?.question ?? ''} contentFormat="latex" /><span className="block text-xs text-ink-muted">{x.question?.topic}</span></Cell>
                <Cell className="text-xs">{TYPE_NAME[x.question?.question_type ?? ''] ?? x.question?.question_type}</Cell>
                <Cell>{x.section ?? '—'}</Cell>
                <Cell>{x.marks ?? x.question?.marks}</Cell>
                <Cell>{draft && <button type="button" className="text-sm text-negative underline" disabled={busy} onClick={() => run(() => fetchApi(`/cbt/exams/${exam.id}/questions/${x.question_id}`, { method: 'DELETE' }), 'Could not remove it.')}>Remove</button>}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      {draft && (
        <Card title="Add from the question bank" description={`Showing ready ${exam.subject?.name ?? ''} questions${exam.class_id && !otherClasses ? ' written for this class' : ' from every class'}.`}>
          <div className="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Search"><input className={inputClass} value={search.search} onChange={(e) => setSearch({ ...search, search: e.target.value })} /></Field>
            <Field label="Topic"><input className={inputClass} value={search.topic} onChange={(e) => setSearch({ ...search, topic: e.target.value })} /></Field>
            <Field label="Type"><select className={inputClass} value={search.question_type} onChange={(e) => setSearch({ ...search, question_type: e.target.value })}><option value="">All</option>{TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
            <Field label="Difficulty"><select className={inputClass} value={search.difficulty} onChange={(e) => setSearch({ ...search, difficulty: e.target.value })}><option value="">All</option><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></Field>
          </div>
          <div className="mb-3 flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2"><input type="checkbox" className="h-4 w-4 accent-brand" checked={allTicked} disabled={shown.length === 0} onChange={(e) => tickAll(e.target.checked)} /> Tick all shown ({shown.length})</label>
            {exam.class_id && <label className="flex items-center gap-2 text-ink-muted"><input type="checkbox" className="h-4 w-4 accent-brand" checked={otherClasses} onChange={(e) => { setOtherClasses(e.target.checked); setPicked({}); }} /> Include other classes’ questions</label>}
            <span className="flex items-center gap-2">
              <input className={`${inputClass} w-36`} placeholder="Section for ticked" value={sectionForAll} onChange={(e) => setSectionForAll(e.target.value)} aria-label="Section for every ticked question" />
              <Button variant="secondary" disabled={!sectionForAll || Object.keys(picked).length === 0} onClick={() => setPicked(Object.fromEntries(Object.entries(picked).map(([id, p]) => [id, { ...p, section: sectionForAll }])))}>Apply</Button>
            </span>
          </div>
          {!bank ? <Loading /> : shown.length === 0 ? <Empty>No matching questions. <Link href="/cbt-bank" className="text-brand underline">Write some in the Question bank</Link>.</Empty> : (
            <Table columns={['', 'Question', 'Type', 'Marks on this paper', 'Section']}>
              {shown.map((b) => {
                const p = picked[b.id];
                return (
                  <tr key={b.id}>
                    <Cell><input type="checkbox" aria-label={`Add question ${b.id}`} checked={!!p} onChange={(e) => { const n = { ...picked }; if (e.target.checked) n[b.id] = { marks: '', section: '' }; else delete n[b.id]; setPicked(n); }} /></Cell>
                    <Cell className="max-w-md"><RichText content={b.question.length > 140 ? `${b.question.slice(0, 140)}…` : b.question} contentFormat={b.content_format} /><span className="block text-xs text-ink-muted">{b.topic}</span></Cell>
                    <Cell className="text-xs">{TYPE_NAME[b.question_type]}</Cell>
                    <Cell>{p ? <input className={`${inputClass} w-20`} type="number" min={0} max={100} step="0.5" placeholder={String(b.marks)} value={p.marks} onChange={(e) => setPicked({ ...picked, [b.id]: { ...p, marks: e.target.value } })} /> : b.marks}</Cell>
                    <Cell>{p && <input className={`${inputClass} w-28`} placeholder="e.g. Section A" value={p.section} onChange={(e) => setPicked({ ...picked, [b.id]: { ...p, section: e.target.value } })} />}</Cell>
                  </tr>
                );
              })}
            </Table>
          )}
          <div className="mt-3 flex flex-wrap items-center gap-3">
            <Button disabled={busy || Object.keys(picked).length === 0} onClick={attach}>Add {Object.keys(picked).length} ticked question(s)</Button>
            {bank && bank.total > 0 && <Button variant="secondary" disabled={busy} onClick={addAllMatching}>Add all {bank.total} matching</Button>}
            {bank && bank.total > shown.length + onPaper.size && <span className="text-xs text-ink-muted">Showing the first 100; “Add all matching” takes every one.</span>}
          </div>
        </Card>
      )}
    </div>
  );
}

function Settings({ exam, run, busy }: { exam: Exam; run: Run; busy: boolean }) {
  const [f, setF] = useState({
    title: exam.title, instructions: exam.instructions ?? '', duration_minutes: String(exam.duration_minutes), opens_at: local(exam.opens_at), closes_at: local(exam.closes_at),
    max_attempts: String(exam.max_attempts ?? 1), questions_per_attempt: exam.questions_per_attempt ? String(exam.questions_per_attempt) : '', pass_mark: exam.pass_mark != null ? String(exam.pass_mark) : '50',
    shuffle_questions: exam.shuffle_questions, shuffle_options: exam.shuffle_options, shuffle_within_group: exam.shuffle_within_group, negative_marking: exam.negative_marking,
    show_results_immediately: exam.show_results_immediately, allow_offline: exam.allow_offline,
    review_answers: exam.review_answers ?? true, review_correct_answers: exam.review_correct_answers ?? false,
  });
  // What students see at the end may change even while the paper is live.
  const endOfTest = ['show_results_immediately', 'review_answers', 'review_correct_answers'];
  const live = exam.status !== 'draft';

  function save(e: React.FormEvent) {
    e.preventDefault();
    const body = live
      ? { closes_at: f.closes_at || null, show_results_immediately: f.show_results_immediately, review_answers: f.review_answers, review_correct_answers: f.review_correct_answers }
      : { ...f, duration_minutes: Number(f.duration_minutes), max_attempts: Number(f.max_attempts), questions_per_attempt: f.questions_per_attempt ? Number(f.questions_per_attempt) : null, pass_mark: Number(f.pass_mark), opens_at: f.opens_at || null, closes_at: f.closes_at || null };
    void run(() => fetchApi(`/cbt/exams/${exam.id}`, { method: 'PUT', body: JSON.stringify(body) }), 'Could not save settings.');
  }

  const check = (k: keyof typeof f, label: string) => (
    <label className="flex items-center gap-2 text-sm"><input type="checkbox" disabled={live && !endOfTest.includes(k)} checked={f[k] as boolean} onChange={(e) => setF({ ...f, [k]: e.target.checked })} /> {label}</label>
  );

  return (
    <Card description={live ? 'While the exam is published, only the closing time and what students see at the end can change.' : undefined}>
      <form onSubmit={save} className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Title"><input className={inputClass} disabled={live} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
          <Field label="Duration (minutes)"><input className={inputClass} disabled={live} type="number" min={1} max={600} value={f.duration_minutes} onChange={(e) => setF({ ...f, duration_minutes: e.target.value })} /></Field>
          {/* Two columns each: a date-time box in a quarter of a laptop screen
              clips its text and leaves only the calendar icon. */}
          <div className="sm:col-span-2"><Field label="Opens"><input className={inputClass} disabled={live} type="datetime-local" value={f.opens_at} onChange={(e) => setF({ ...f, opens_at: e.target.value })} /></Field></div>
          <div className="sm:col-span-2"><Field label="Closes"><input className={inputClass} type="datetime-local" value={f.closes_at} onChange={(e) => setF({ ...f, closes_at: e.target.value })} /></Field></div>
          <Field label="Attempts allowed"><input className={inputClass} disabled={live} type="number" min={1} max={10} value={f.max_attempts} onChange={(e) => setF({ ...f, max_attempts: e.target.value })} /></Field>
          <Field label="Questions per student (random draw)"><input className={inputClass} disabled={live} type="number" min={1} placeholder="All" value={f.questions_per_attempt} onChange={(e) => setF({ ...f, questions_per_attempt: e.target.value })} /></Field>
          <Field label="Pass mark (%)"><input className={inputClass} disabled={live} type="number" min={0} max={100} value={f.pass_mark} onChange={(e) => setF({ ...f, pass_mark: e.target.value })} /></Field>
        </div>
        <Field label="Instructions to students"><textarea className={`${inputClass} min-h-[5rem]`} disabled={live} value={f.instructions} onChange={(e) => setF({ ...f, instructions: e.target.value })} /></Field>
        <div className="grid gap-2 sm:grid-cols-2">
          {check('shuffle_questions', 'Shuffle question order for each student')}
          {check('shuffle_options', 'Shuffle the options')}
          {check('shuffle_within_group', 'Shuffle questions inside a passage')}
          {check('negative_marking', 'Negative marking (wrong answers lose marks; blanks never do)')}
          {check('allow_offline', 'Exam-hall paper: fixed opening time, scores hidden until you release them')}
        </div>
        <fieldset className="rounded-lg border border-line-soft p-4">
          <legend className="px-1 text-sm font-semibold text-ink">At the end of the test, students see</legend>
          <div className="grid gap-2 sm:grid-cols-3">
            {check('show_results_immediately', 'Their score, as soon as they submit')}
            {check('review_answers', 'Their answers, marked right or wrong')}
            {check('review_correct_answers', 'The correct options (after the test closes)')}
          </div>
          <p className="mt-2 text-xs text-ink-muted">With the score off, nothing shows until you switch it on later to release results. Correct options wait until the closing time so early finishers cannot pass them on.</p>
        </fieldset>
        <Button type="submit" disabled={busy}>Save settings</Button>
      </form>
    </Card>
  );
}

function ResultsTab({ examId, onError, onDone }: { examId: number; onError: (e: unknown, f: string) => void; onDone: (m: string) => void }) {
  const [r, setR] = useState<Results | null>(null);
  const [script, setScript] = useState<Script | null>(null);
  const [marks, setMarks] = useState<Record<number, { awarded: string; feedback: string }>>({});
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { setR(await fetchApi<Results>(`/cbt/exams/${examId}/results`)); } catch (err) { onError(err, 'Could not load results.'); }
  }, [examId, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function open(attemptId: number) {
    try {
      const res = await fetchApi<{ result: Script }>(`/cbt/attempts/${attemptId}/result`);
      setScript(res.result);
      setMarks(Object.fromEntries((res.result.questions ?? []).filter((q) => q.question_type === 'theory' || q.is_correct === null).map((q) => [q.question_id, { awarded: q.is_correct === null ? '' : String(q.awarded_marks), feedback: q.feedback ?? '' }])));
    } catch (err) { onError(err, 'Could not open that script.'); }
  }

  async function grade() {
    if (!script) return;
    setBusy(true);
    try {
      const rows = Object.entries(marks).filter(([, m]) => m.awarded !== '').map(([id, m]) => ({ question_id: Number(id), awarded_marks: Number(m.awarded), feedback: m.feedback || undefined }));
      const res = await fetchApi<{ message: string; result: Script }>(`/cbt/attempts/${script.attempt_id}/grade`, { method: 'POST', body: JSON.stringify({ marks: rows }) });
      onDone(res.message);
      setScript(res.result);
      await load();
    } catch (err) { onError(err, 'Could not save the marks.'); } finally { setBusy(false); }
  }

  if (!r) return <Loading />;
  const flags = (f: Record<string, number> | null) => Object.entries(f ?? {}).filter(([, n]) => n > 0).map(([k, n]) => `${k.replaceAll('_', ' ')} ×${n}`).join(', ');

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <StatTile label="Sat" value={r.summary.attempts} />
        <StatTile label="To mark" value={r.summary.awaiting_marking} tone={r.summary.awaiting_marking ? 'amber' : 'slate'} />
        <StatTile label="Average" value={r.summary.average_percentage != null ? `${r.summary.average_percentage}%` : '—'} tone="blue" />
        <StatTile label="Highest / lowest" value={r.summary.highest != null ? `${r.summary.highest} / ${r.summary.lowest}` : '—'} />
        <StatTile label="Pass rate" value={r.summary.pass_rate != null ? `${r.summary.pass_rate}%` : '—'} tone="green" />
      </div>

      {script && (
        <Card title={`Script #${script.attempt_id}`} description="Theory answers wait for you. Objective answers were marked automatically.">
          <ol className="space-y-4">
            {(script.questions ?? []).map((q, i) => (
              <li key={q.question_id} className="rounded-lg border border-line-soft p-3 text-sm">
                <div className="flex justify-between gap-2"><span><span className="text-ink-muted">{i + 1}. </span><RichText content={q.question} contentFormat="latex" /></span><span className="text-xs text-ink-muted">{q.marks ?? '?'} mark(s)</span></div>
                <p className="mt-2 whitespace-pre-wrap rounded bg-surface-sunken px-3 py-2">{show(q.your_response)}</p>
                {marks[q.question_id] ? (
                  <div className="mt-2 flex flex-wrap gap-2">
                    <input className={`${inputClass} w-24`} type="number" min={0} max={q.marks ?? undefined} step="0.5" placeholder={`/ ${q.marks ?? '?'}`} value={marks[q.question_id].awarded} onChange={(e) => setMarks({ ...marks, [q.question_id]: { ...marks[q.question_id], awarded: e.target.value } })} aria-label="Marks awarded" />
                    <input className={`${inputClass} flex-1`} placeholder="Feedback (optional)" value={marks[q.question_id].feedback} onChange={(e) => setMarks({ ...marks, [q.question_id]: { ...marks[q.question_id], feedback: e.target.value } })} />
                  </div>
                ) : (
                  <p className="mt-1 text-xs">{q.is_correct ? <Badge tone="green">correct · {q.awarded_marks}</Badge> : <Badge tone="red">{q.awarded_marks} awarded</Badge>}{q.correct_answer !== undefined && q.correct_answer !== null && <span className="ml-2 text-ink-muted">Answer: {JSON.stringify(q.correct_answer)}</span>}</p>
                )}
              </li>
            ))}
          </ol>
          <div className="mt-3 flex gap-2">
            {Object.keys(marks).length > 0 && <Button disabled={busy} onClick={grade}>Save marks</Button>}
            <Button variant="secondary" onClick={() => setScript(null)}>Close script</Button>
          </div>
        </Card>
      )}

      <Card title="Candidates">
        {r.candidates.length === 0 ? <Empty>No one has sat it yet.</Empty> : (
          <Table columns={['Student', 'Score', 'Grade', 'Invigilation', '']}>
            {r.candidates.map((c) => (
              <tr key={c.attempt_id}>
                <Cell className="font-medium">{c.student_name}</Cell>
                <Cell>{c.percentage}%</Cell>
                <Cell>{c.grade}</Cell>
                <Cell className="text-xs text-ink-muted">{flags(c.integrity_flags) || '—'}</Cell>
                <Cell><Button variant={c.requires_manual_grading ? 'accent' : 'secondary'} onClick={() => open(c.attempt_id)}>{c.requires_manual_grading ? 'Mark' : 'View'}</Button></Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      {r.item_analysis.length > 0 && (
        <Card title="Item analysis" description="Facility index p: the share who got each question right. A very low p on taught material usually means the question is broken.">
          <Table columns={['Question', 'Answered', 'Correct', 'p']}>
            {r.item_analysis.map((it) => (
              <tr key={it.question_id}>
                <Cell>#{it.question_id}</Cell><Cell>{it.answered}</Cell><Cell>{it.correct}</Cell>
                <Cell>{it.facility_index != null ? <Badge tone={it.facility_index < 0.2 ? 'red' : it.facility_index > 0.9 ? 'amber' : 'green'}>{it.facility_index}</Badge> : '—'}</Cell>
              </tr>
            ))}
          </Table>
        </Card>
      )}
    </div>
  );
}
