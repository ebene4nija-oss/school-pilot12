'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { ApiError, errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';
import { RichText } from '@/components/RichContent';
import { TYPES } from '@/components/cbt/QuestionEditor';

/**
 * One CBT exam (§7.8): the paper, its settings, publishing, results and
 * marking, and offline bundles for a lab with no internet.
 *
 * A published paper is frozen — questions cannot be added, removed or
 * re-marked underneath candidates; only the closing time and the results
 * setting may change. Theory answers are marked here, never above what a
 * question is worth (the API refuses it).
 */

type Tab = 'paper' | 'settings' | 'results' | 'offline';
interface ExamQ { id: number; question_id: number; order_index: number; marks: number | string | null; negative_marks: number | string | null; section: string | null; question: { id: number; question: string; question_type: string; topic: string | null; difficulty: string | null; marks: number | string } | null }
interface Exam {
  id: number; title: string; status: string; subject_id: number; subject: { name: string } | null; class_id: number | null; instructions: string | null;
  duration_minutes: number; opens_at: string | null; closes_at: string | null; shuffle_questions: boolean; shuffle_options: boolean; shuffle_within_group: boolean;
  questions_per_attempt: number | null; max_attempts: number; negative_marking: boolean; pass_mark: number | string | null; show_results_immediately: boolean; allow_offline: boolean;
  exam_questions: ExamQ[];
}
interface Bank { id: number; question: string; question_type: string; topic: string | null; marks: number; content_format: 'plain' | 'latex' }
interface Results {
  summary: { attempts: number; awaiting_marking: number; average_percentage: number | null; highest: number | null; lowest: number | null; pass_rate: number | null };
  candidates: { attempt_id: number; student_name: string | null; percentage: number; grade: string | null; requires_manual_grading: boolean; integrity_flags: Record<string, number> | null }[];
  item_analysis: { question_id: number; answered: number; correct: number; facility_index: number | null }[];
}
interface Script { attempt_id: number; exam_title: string; percentage?: number; raw_score?: number; max_score?: number; questions?: { question_id: number; question_type: string | null; marks: number | null; question: string | null; your_response: Record<string, unknown> | null; is_correct: boolean | null; awarded_marks: number; correct_answer?: unknown; feedback: string | null }[] }
interface Bundle { bundle_id: string; relay_identity: string | null; attempt_count: number; question_count: number; ciphertext_bytes: number; built_at: string | null; unlocked_at: string | null; revoked_at: string | null }

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
        </div>
      }
    >
      <p className="-mt-3 mb-4 text-sm"><Link href="/cbt-setup" className="text-brand underline">← All exams</Link></p>
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['paper', 'Paper'], ['settings', 'Settings'], ['results', 'Results & marking'], ['offline', 'Offline lab']] as [Tab, string][]).map(([k, l]) => (
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
      {tab === 'offline' && <Offline exam={exam} run={run} busy={busy} />}
    </PageShell>
  );
}

type Run = (fn: () => Promise<{ message?: string; notice?: string }>, fallback: string) => Promise<void>;

function Paper({ exam, draft, busy, run }: { exam: Exam; draft: boolean; busy: boolean; run: Run }) {
  const [search, setSearch] = useState({ search: '', topic: '', question_type: '' });
  const [bank, setBank] = useState<Bank[] | null>(null);
  const [picked, setPicked] = useState<Record<number, { marks: string; section: string }>>({});
  const onPaper = new Set(exam.exam_questions.map((q) => q.question_id));

  const find = useCallback(async () => {
    const qs = new URLSearchParams({ per_page: '50', status: 'approved', subject_id: String(exam.subject_id), ...Object.fromEntries(Object.entries(search).filter(([, v]) => v)) });
    const r = await fetchApi<{ data: Bank[] }>(`/cbt/questions?${qs}`);
    setBank(r.data);
  }, [search, exam.subject_id]);

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
        <Card title="Add from the question bank" description={`Showing ready ${exam.subject?.name ?? ''} questions.`}>
          <div className="mb-3 grid gap-3 sm:grid-cols-3">
            <Field label="Search"><input className={inputClass} value={search.search} onChange={(e) => setSearch({ ...search, search: e.target.value })} /></Field>
            <Field label="Topic"><input className={inputClass} value={search.topic} onChange={(e) => setSearch({ ...search, topic: e.target.value })} /></Field>
            <Field label="Type"><select className={inputClass} value={search.question_type} onChange={(e) => setSearch({ ...search, question_type: e.target.value })}><option value="">All</option>{TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
          </div>
          {!bank ? <Loading /> : bank.length === 0 ? <Empty>No matching questions. <Link href="/cbt-bank" className="text-brand underline">Write some in the Question bank</Link>.</Empty> : (
            <Table columns={['', 'Question', 'Type', 'Marks on this paper', 'Section']}>
              {bank.filter((b) => !onPaper.has(b.id)).map((b) => {
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
          <Button className="mt-3" disabled={busy || Object.keys(picked).length === 0} onClick={attach}>Add {Object.keys(picked).length} question(s) to the paper</Button>
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
  });
  const live = exam.status !== 'draft';

  function save(e: React.FormEvent) {
    e.preventDefault();
    const body = live
      ? { closes_at: f.closes_at || null, show_results_immediately: f.show_results_immediately }
      : { ...f, duration_minutes: Number(f.duration_minutes), max_attempts: Number(f.max_attempts), questions_per_attempt: f.questions_per_attempt ? Number(f.questions_per_attempt) : null, pass_mark: Number(f.pass_mark), opens_at: f.opens_at || null, closes_at: f.closes_at || null };
    void run(() => fetchApi(`/cbt/exams/${exam.id}`, { method: 'PUT', body: JSON.stringify(body) }), 'Could not save settings.');
  }

  const check = (k: keyof typeof f, label: string) => (
    <label className="flex items-center gap-2 text-sm"><input type="checkbox" disabled={live && k !== 'show_results_immediately'} checked={f[k] as boolean} onChange={(e) => setF({ ...f, [k]: e.target.checked })} /> {label}</label>
  );

  return (
    <Card description={live ? 'While the exam is published, only the closing time and whether students see their results can change.' : undefined}>
      <form onSubmit={save} className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Title"><input className={inputClass} disabled={live} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
          <Field label="Duration (minutes)"><input className={inputClass} disabled={live} type="number" min={1} max={600} value={f.duration_minutes} onChange={(e) => setF({ ...f, duration_minutes: e.target.value })} /></Field>
          <Field label="Opens"><input className={inputClass} disabled={live} type="datetime-local" value={f.opens_at} onChange={(e) => setF({ ...f, opens_at: e.target.value })} /></Field>
          <Field label="Closes"><input className={inputClass} type="datetime-local" value={f.closes_at} onChange={(e) => setF({ ...f, closes_at: e.target.value })} /></Field>
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
          {check('show_results_immediately', 'Show students their score and review after they submit')}
          {check('allow_offline', 'Can be sat offline in a computer lab (needs an opening time)')}
        </div>
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

function Offline({ exam, run, busy }: { exam: Exam; run: Run; busy: boolean }) {
  const [bundles, setBundles] = useState<Bundle[] | null>(null);
  const [size, setSize] = useState<number | null>(null);
  const [relay, setRelay] = useState('');

  const load = useCallback(async () => {
    const r = await fetchApi<{ data: Bundle[] }>(`/cbt/exams/${exam.id}/offline-bundles`).catch(() => ({ data: [] as Bundle[] }));
    setBundles(r.data);
  }, [exam.id]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ media_manifest?: { total_bytes?: number } }>(`/cbt/exams/${exam.id}/offline-package`).then((p) => setSize(p.media_manifest?.total_bytes ?? null)).catch(() => undefined);
  }, [load, exam.id]);

  if (!exam.allow_offline) return <Empty>This exam is online only. Turn on “Can be sat offline” in Settings to prepare it for a lab with no internet.</Empty>;

  return (
    <Card description="An encrypted copy of the paper for the school's relay laptop. The relay fetches the key itself on exam day; it is never shown here.">
      {size !== null && <p className="mb-3 text-sm">Images to copy to the lab: {(size / 1_048_576).toFixed(1)} MB.</p>}
      <div className="mb-4 flex flex-wrap items-end gap-2">
        <Field label="Relay laptop name (optional)"><input className={inputClass} value={relay} onChange={(e) => setRelay(e.target.value)} placeholder="ICT lab relay" /></Field>
        <Button disabled={busy || exam.status !== 'published'} onClick={() => run(() => fetchApi(`/cbt/exams/${exam.id}/offline-bundle`, { method: 'POST', body: JSON.stringify({ relay_identity: relay || undefined }) }).then((r) => { void load(); return r as { message?: string }; }), 'Could not build the bundle.')}>Build bundle</Button>
        {exam.status !== 'published' && <span className="text-xs text-ink-muted">Publish the exam first.</span>}
      </div>
      {!bundles ? <Loading /> : bundles.length === 0 ? <Empty>No bundles built.</Empty> : (
        <Table columns={['Bundle', 'Relay', 'Students', 'Built', 'State', '']}>
          {bundles.map((b) => (
            <tr key={b.bundle_id}>
              <Cell className="font-mono text-xs">{b.bundle_id.slice(0, 12)}…</Cell>
              <Cell>{b.relay_identity ?? '—'}</Cell>
              <Cell>{b.attempt_count}</Cell>
              <Cell className="text-xs">{b.built_at?.slice(0, 16).replace('T', ' ')}</Cell>
              <Cell><Badge tone={b.revoked_at ? 'red' : b.unlocked_at ? 'green' : 'amber'}>{b.revoked_at ? 'revoked' : b.unlocked_at ? 'unlocked' : 'waiting'}</Badge></Cell>
              <Cell>{!b.revoked_at && <button type="button" className="text-sm text-negative underline" onClick={() => { if (window.confirm('Revoke this bundle? Its key will never be released, and unsat attempts are returned.')) void run(() => fetchApi(`/cbt/offline-bundle/${b.bundle_id}/revoke`, { method: 'POST', body: '{}' }).then((r) => { void load(); return r as { message?: string }; }), 'Could not revoke.'); }}>Revoke</button>}</Cell>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  );
}
