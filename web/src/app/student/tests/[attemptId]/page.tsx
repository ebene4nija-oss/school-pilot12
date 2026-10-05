'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { ApiError, errorText, fetchApi } from '@/lib/api';
import { Badge, Button, ErrorNote, Loading } from '@/components/Page';
import { RichContent } from '@/components/RichContent';
import { AnswerInput, isAnswered, type Question, type Response } from '../CbtInputs';
import CbtResult, { type ResultResponse } from '../CbtResult';

/**
 * Sitting a CBT paper (TASK 09): instructions → questions with a navigator →
 * autosave → submit → result as the school releases it.
 *
 * The server owns the clock. The countdown here is `seconds_remaining` from
 * the API counted down locally, re-synced on every save; when it reaches
 * zero the paper is submitted, and the API auto-submits an expired attempt
 * anyway, so a closed laptop cannot buy extra time.
 *
 * Answers save in the background a moment after each change and retry until
 * they land, each stamped with a sequence number so the server keeps the
 * latest one however the requests arrive. Leaving the page, copying, pasting
 * and losing the connection are reported for a human invigilator to read —
 * no camera, nothing biometric (doc §3).
 */

interface Paper {
  attempt: { id: number; status: string; seconds_remaining: number | null; server_deadline_at: string | null };
  exam: { id: number; title: string; instructions: string | null; duration_minutes: number; total_marks: number; negative_marking: boolean };
  questions: Question[];
}

const clock = (s: number) => {
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  return `${h ? `${h}:` : ''}${String(m).padStart(h ? 2 : 1, '0')}:${String(sec).padStart(2, '0')}`;
};

export default function SitPaper() {
  const { attemptId } = useParams<{ attemptId: string }>();
  const [paper, setPaper] = useState<Paper | null>(null);
  const [responses, setResponses] = useState<Record<number, Response>>({});
  const [flags, setFlags] = useState<Record<number, boolean>>({});
  const [index, setIndex] = useState(0);
  const [started, setStarted] = useState(false);
  const [left, setLeft] = useState<number | null>(null);
  const [saveState, setSaveState] = useState<'saved' | 'saving' | 'offline'>('saved');
  const [phase, setPhase] = useState<'loading' | 'sitting' | 'submitting' | 'done'>('loading');
  const [result, setResult] = useState<ResultResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const deadline = useRef<number | null>(null);
  const dirty = useRef<Set<number>>(new Set());
  const latest = useRef<{ responses: Record<number, Response>; flags: Record<number, boolean> }>({ responses: {}, flags: {} });
  // Seeded from the clock when the paper loads, so a reloaded page keeps
  // numbering above anything the last one sent.
  const sequence = useRef(0);
  const saving = useRef<Promise<void> | null>(null);
  const finished = useRef(false);

  // What the next save sends: always the newest answers, read outside render.
  useEffect(() => { latest.current = { responses, flags }; }, [responses, flags]);

  const report = useCallback((event_type: string, metadata?: Record<string, unknown>) => {
    if (finished.current) return;
    fetchApi(`/cbt/attempts/${attemptId}/events`, { method: 'POST', body: JSON.stringify({ event_type, metadata }) }).catch(() => undefined);
  }, [attemptId]);

  const showResult = useCallback(async (message?: string) => {
    finished.current = true;
    try { sessionStorage.removeItem(`cbt-paper-${attemptId}`); } catch { /* ignore */ }
    try {
      setResult(await fetchApi<ResultResponse>(`/cbt/attempts/${attemptId}/result`));
    } catch {
      setResult({ message: message ?? 'Your paper was submitted.' });
    }
    setPhase('done');
  }, [attemptId]);

  // ---- load ------------------------------------------------------------
  useEffect(() => {
    (async () => {
      let p: Paper | null = null;
      try {
        const stored = sessionStorage.getItem(`cbt-paper-${attemptId}`);
        if (stored) {
          p = JSON.parse(stored) as Paper;
          sessionStorage.removeItem(`cbt-paper-${attemptId}`);
        }
      } catch { /* storage blocked */ }
      try {
        p ??= await fetchApi<Paper>(`/cbt/attempts/${attemptId}`);
      } catch (err) {
        // 409: already submitted, or the time ran out while away.
        if (err instanceof ApiError && err.status === 409) {
          await showResult(err.message);
          return;
        }
        setError(errorText(err, 'Could not open this paper.'));
        return;
      }
      sequence.current = Date.now();
      setPaper(p);
      setResponses(Object.fromEntries(p.questions.map((q) => [q.question_id, q.saved_response ?? null])));
      // A paper already in progress (resumed) skips the instructions screen.
      setStarted(p.questions.some((q) => q.saved_response));
      if (p.attempt.seconds_remaining !== null) {
        deadline.current = Date.now() + p.attempt.seconds_remaining * 1000;
        setLeft(p.attempt.seconds_remaining);
      }
      setPhase('sitting');
    })();
  }, [attemptId, showResult]);

  // ---- autosave --------------------------------------------------------
  const flush = useCallback(async (): Promise<void> => {
    if (saving.current) await saving.current;
    if (dirty.current.size === 0 || finished.current) return;
    const ids = [...dirty.current];
    dirty.current.clear();
    setSaveState('saving');
    const run = (async () => {
      try {
        const res = await fetchApi<{ seconds_remaining: number | null }>(`/cbt/attempts/${attemptId}/answers`, {
          method: 'POST',
          body: JSON.stringify({
            answers: ids.map((id) => ({
              question_id: id,
              response: latest.current.responses[id] ?? null,
              flagged_for_review: !!latest.current.flags[id],
              client_timestamp: new Date().toISOString(),
              client_sequence: ++sequence.current,
            })),
          }),
        });
        if (res.seconds_remaining !== null) deadline.current = Date.now() + res.seconds_remaining * 1000;
        setSaveState(dirty.current.size ? 'saving' : 'saved');
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
          await showResult(err.message);
          return;
        }
        ids.forEach((id) => dirty.current.add(id));
        setSaveState('offline');
      }
    })();
    saving.current = run;
    await run;
    saving.current = null;
  }, [attemptId, showResult]);

  useEffect(() => {
    if (phase !== 'sitting') return;
    const t = setInterval(() => { if (dirty.current.size) void flush(); }, 4000);
    return () => clearInterval(t);
  }, [phase, flush]);

  const answer = (qid: number, r: Response) => {
    setResponses((prev) => ({ ...prev, [qid]: r }));
    dirty.current.add(qid);
    setSaveState('saving');
  };
  const toggleFlag = (qid: number) => {
    setFlags((prev) => ({ ...prev, [qid]: !prev[qid] }));
    dirty.current.add(qid);
  };

  // ---- submit ----------------------------------------------------------
  const submit = useCallback(async (auto = false) => {
    if (finished.current) return;
    setPhase('submitting');
    await flush();
    if (finished.current) return;
    try {
      const res = await fetchApi<{ message: string; result: ResultResponse['result'] }>(`/cbt/attempts/${attemptId}/submit`, { method: 'POST', body: '{}' });
      finished.current = true;
      setResult({ message: res.message, result: res.result });
      setPhase('done');
    } catch (err) {
      if (auto || (err instanceof ApiError && err.status === 409)) {
        await showResult();
        return;
      }
      setError(errorText(err, 'Could not submit. Check your connection and try again.'));
      setPhase('sitting');
    }
  }, [attemptId, flush, showResult]);

  // ---- clock -----------------------------------------------------------
  useEffect(() => {
    if (phase !== 'sitting' || deadline.current === null) return;
    const t = setInterval(() => {
      const s = Math.max(0, Math.round(((deadline.current ?? 0) - Date.now()) / 1000));
      setLeft(s);
      if (s === 0) void submit(true);
    }, 1000);
    return () => clearInterval(t);
  }, [phase, submit]);

  // ---- invigilation signals -------------------------------------------
  useEffect(() => {
    if (phase !== 'sitting' || !started) return;
    const vis = () => report(document.hidden ? 'focus_lost' : 'focus_regained');
    const copy = () => report('copy');
    const paste = () => report('paste');
    const offline = () => { report('network_lost'); setSaveState('offline'); };
    const online = () => { report('network_restored'); void flush(); };
    const leave = (e: BeforeUnloadEvent) => { if (dirty.current.size) e.preventDefault(); };
    document.addEventListener('visibilitychange', vis);
    document.addEventListener('copy', copy);
    document.addEventListener('paste', paste);
    window.addEventListener('offline', offline);
    window.addEventListener('online', online);
    window.addEventListener('beforeunload', leave);
    return () => {
      document.removeEventListener('visibilitychange', vis);
      document.removeEventListener('copy', copy);
      document.removeEventListener('paste', paste);
      window.removeEventListener('offline', offline);
      window.removeEventListener('online', online);
      window.removeEventListener('beforeunload', leave);
    };
  }, [phase, started, report, flush]);

  // ---- render ----------------------------------------------------------
  if (phase === 'done' && result) {
    return (
      <main className="mx-auto min-h-screen max-w-3xl bg-canvas p-6 text-ink">
        <h1 className="mb-4 font-headline text-2xl font-bold text-brand">Paper submitted</h1>
        <CbtResult data={result} />
        <Link href="/student/tests" className="mt-4 inline-block text-sm text-brand underline">Back to my tests</Link>
      </main>
    );
  }

  if (!paper) {
    return <main className="min-h-screen bg-canvas p-6"><ErrorNote message={error} />{!error && <Loading />}{error && <Link href="/student/tests" className="text-sm text-brand underline">Back to my tests</Link>}</main>;
  }

  const qs = paper.questions;
  const answeredCount = qs.filter((q) => isAnswered(responses[q.question_id])).length;

  if (!started) {
    return (
      <main className="mx-auto min-h-screen max-w-2xl bg-canvas p-6 text-ink">
        <h1 className="font-headline text-2xl font-bold text-brand">{paper.exam.title}</h1>
        <p className="mt-1 text-sm text-ink-muted">{qs.length} questions · {paper.exam.total_marks} marks{left !== null ? ` · ${clock(left)} left` : ''}</p>
        <section className="mt-5 rounded-xl bg-surface p-5 shadow-sm ring-1 ring-line-soft">
          <h2 className="font-headline text-sm font-semibold text-brand">Instructions</h2>
          <p className="mt-2 whitespace-pre-wrap text-sm">{paper.exam.instructions || 'Answer every question. Your answers save as you go.'}</p>
          <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-ink-muted">
            <li>The clock is already running and does not stop if you leave this page.</li>
            <li>Answers save automatically. You can go back to any question before you submit.</li>
            {paper.exam.negative_marking && <li>Wrong answers lose marks. Leave a question blank if you do not know it.</li>}
            <li>Leaving this page, copying and pasting are recorded for your invigilator.</li>
          </ul>
        </section>
        <Button variant="accent" className="mt-5" onClick={() => setStarted(true)}>Begin</Button>
      </main>
    );
  }

  const q = qs[index];
  const plainStem = ['diagram_label', 'hotspot'].includes(q.question_type);
  const base = plainStem ? (q.media.find((m) => m.role === 'diagram') ?? q.media.find((m) => m.role === 'stem') ?? q.media[0]) : null;
  const low = left !== null && left <= 300;

  return (
    <div className="min-h-screen bg-canvas text-ink">
      <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-line-soft bg-surface px-5 py-3 shadow-sm">
        <div className="min-w-0">
          <p className="truncate font-headline font-semibold text-brand">{paper.exam.title}</p>
          <p className="text-xs text-ink-muted">{answeredCount} of {qs.length} answered · <span className={saveState === 'offline' ? 'text-negative' : ''}>{saveState === 'saved' ? 'All answers saved' : saveState === 'saving' ? 'Saving…' : 'Not saved — will retry when you are back online'}</span></p>
        </div>
        {left !== null && (
          <p role="timer" aria-live={low ? 'assertive' : 'off'} className={`rounded-lg px-3 py-1.5 font-mono text-lg font-bold ${low ? 'bg-negative-soft text-negative' : 'bg-surface-raised text-brand'}`}>{clock(left)}</p>
        )}
        <Button variant="accent" disabled={phase === 'submitting'} onClick={() => {
          const blank = qs.length - answeredCount;
          if (window.confirm(blank ? `You have ${blank} unanswered question(s). Submit anyway? You cannot change answers after submitting.` : 'Submit your paper? You cannot change answers after submitting.')) void submit();
        }}>{phase === 'submitting' ? 'Submitting…' : 'Submit paper'}</Button>
      </header>

      <div className="mx-auto grid max-w-6xl gap-5 p-5 lg:grid-cols-[1fr_15rem]">
        <section className="rounded-xl bg-surface p-6 shadow-sm ring-1 ring-line-soft">
          <ErrorNote message={error} />
          {q.group && (
            <div className="mb-5 rounded-lg border border-line-soft bg-surface-sunken p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">{q.group.title ?? 'Read this first'} · question {q.group.position} of {q.group.of}</p>
              {q.group.instructions && <p className="mt-1 text-sm italic">{q.group.instructions}</p>}
              <RichContent content={q.group.stimulus} contentFormat={q.group.content_format} media={q.group.media} className="mt-2 max-h-80 overflow-y-auto whitespace-pre-wrap text-sm" />
            </div>
          )}
          <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
            <span className="font-semibold text-brand">Question {q.number} of {qs.length}</span>
            <span>· {q.marks} mark{q.marks === 1 ? '' : 's'}</span>
            {q.negative_marks > 0 && <Badge tone="red">−{q.negative_marks} if wrong</Badge>}
            {q.section && <span>· {q.section}</span>}
          </div>
          <RichContent content={q.question} contentFormat={q.content_format} media={plainStem ? q.media.filter((m) => m !== base) : q.media} className="mb-5 text-base" />
          <AnswerInput q={q} value={responses[q.question_id] ?? null} onChange={(r) => answer(q.question_id, r)} />

          <div className="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-line-soft pt-4">
            <Button variant="secondary" disabled={index === 0} onClick={() => setIndex(index - 1)}>← Previous</Button>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={!!flags[q.question_id]} onChange={() => toggleFlag(q.question_id)} /> Flag to review later
            </label>
            {isAnswered(responses[q.question_id]) && (
              <button type="button" className="text-sm text-ink-muted underline" onClick={() => answer(q.question_id, null)}>Clear answer</button>
            )}
            <Button variant={index === qs.length - 1 ? 'secondary' : 'primary'} disabled={index === qs.length - 1} onClick={() => setIndex(index + 1)}>Next →</Button>
          </div>
        </section>

        <nav aria-label="Questions" className="h-fit rounded-xl bg-surface p-4 shadow-sm ring-1 ring-line-soft lg:sticky lg:top-24">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-faint">Questions</p>
          <div className="grid grid-cols-5 gap-1.5">
            {qs.map((x, i) => {
              const done = isAnswered(responses[x.question_id]);
              const flagged = flags[x.question_id];
              return (
                <button key={x.question_id} type="button" onClick={() => setIndex(i)} aria-current={i === index ? 'step' : undefined}
                  aria-label={`Question ${x.number}${done ? ', answered' : ''}${flagged ? ', flagged' : ''}`}
                  className={`relative h-9 rounded-md text-sm font-medium ring-1 ${i === index ? 'ring-2 ring-brand' : 'ring-line-soft'} ${done ? 'bg-brand text-white' : 'bg-surface hover:bg-surface-sunken'}`}>
                  {x.number}
                  {flagged && <span aria-hidden="true" className="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-accent ring-2 ring-surface" />}
                </button>
              );
            })}
          </div>
          <ul className="mt-4 space-y-1 text-xs text-ink-muted">
            <li><span className="mr-1 inline-block h-3 w-3 rounded bg-brand align-middle" /> answered</li>
            <li><span className="mr-1 inline-block h-3 w-3 rounded ring-1 ring-line-soft align-middle" /> not answered</li>
            <li><span className="mr-1 inline-block h-3 w-3 rounded-full bg-accent align-middle" /> flagged</li>
          </ul>
        </nav>
      </div>
    </div>
  );
}
