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
 * Built to survive an exam hall losing its internet, the way the mobile app
 * does. Every answer goes into localStorage the moment it is given, stamped
 * with a sequence number so the server keeps the latest one however the
 * requests arrive. Nothing leaves that store until the server has it, so a
 * dropped line, a frozen PC or a restarted desktop app loses nothing. When
 * time runs out with no connection the paper ends here, on this clock, and
 * the held answers go up with the submit through `/cbt/offline-sync` once the
 * line is back — the server waits for them (config
 * schoolpilot.cbt_offline_grace_minutes).
 *
 * Leaving the page, copying, pasting and losing the connection are reported
 * for a human invigilator to read — no camera, nothing biometric (doc §3).
 */

interface Paper {
  attempt: { id: number; status: string; seconds_remaining: number | null; server_deadline_at: string | null };
  exam: { id: number; title: string; instructions: string | null; duration_minutes: number; total_marks: number; negative_marking: boolean };
  questions: Question[];
}

/** Answers this machine has that the server may not — the mobile app's SQLite buffer, in localStorage. */
interface Held {
  answers: Record<number, { response: Response; flagged: boolean; client_sequence: number; client_timestamp: string }>;
  /** The paper is over (submitted, or the clock ran out) and only needs sending. */
  submit?: boolean;
}

const heldKey = (attemptId: string) => `cbt-held-${attemptId}`;

function readHeld(attemptId: string): Held {
  try {
    const raw = localStorage.getItem(heldKey(attemptId));
    if (raw) return JSON.parse(raw) as Held;
  } catch { /* storage blocked or corrupt */ }
  return { answers: {} };
}

function writeHeld(attemptId: string, held: Held) {
  try {
    if (Object.keys(held.answers).length === 0 && !held.submit) localStorage.removeItem(heldKey(attemptId));
    else localStorage.setItem(heldKey(attemptId), JSON.stringify(held));
  } catch { /* storage full or blocked: the in-memory copy still saves online */ }
}

const payloadOf = (answers: Held['answers']) => Object.entries(answers).map(([id, a]) => ({
  question_id: Number(id),
  response: a.response,
  flagged_for_review: a.flagged,
  client_sequence: a.client_sequence,
  client_timestamp: a.client_timestamp,
}));

/** A request that never reached the server, as opposed to one it refused. */
const isOffline = (err: unknown) => !(err instanceof ApiError);

/** Stop the routine autosave this close to the end; the submit carries the rest. */
const END_MARGIN_MS = 3000;

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
  const [phase, setPhase] = useState<'loading' | 'sitting' | 'submitting' | 'waiting' | 'done'>('loading');
  const [result, setResult] = useState<ResultResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const deadline = useRef<number | null>(null);
  const latest = useRef<{ responses: Record<number, Response>; flags: Record<number, boolean> }>({ responses: {}, flags: {} });
  // Seeded from the clock, so numbering stays above anything an earlier
  // session on this machine already sent.
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

  /** Write one answer to this machine before anything else happens to it. */
  const hold = useCallback((qid: number, response: Response, flagged: boolean) => {
    const held = readHeld(attemptId);
    held.answers[qid] = { response, flagged, client_sequence: ++sequence.current, client_timestamp: new Date().toISOString() };
    writeHeld(attemptId, held);
  }, [attemptId]);

  /**
   * Send everything held, in one request that also submits if the paper is
   * over. The store is cleared only once the server has answered. Returns the
   * attempt's status as the server now sees it.
   */
  const sendHeld = useCallback(async (): Promise<string> => {
    const held = readHeld(attemptId);
    const answers = payloadOf(held.answers);
    let status = 'in_progress';
    if (answers.length) {
      const res = await fetchApi<{ attempt_status?: string }>('/cbt/offline-sync', {
        method: 'POST',
        body: JSON.stringify({ attempt_id: Number(attemptId), answers, submit: !!held.submit }),
      });
      status = res.attempt_status ?? (held.submit ? 'submitted' : status);
    } else if (held.submit) {
      try {
        await fetchApi(`/cbt/attempts/${attemptId}/submit`, { method: 'POST', body: '{}' });
      } catch (err) {
        // 409: it was already closed, which is what we wanted.
        if (!(err instanceof ApiError && err.status === 409)) throw err;
      }
      status = 'submitted';
    }
    try { localStorage.removeItem(heldKey(attemptId)); } catch { /* ignore */ }
    return status;
  }, [attemptId]);

  // ---- load ------------------------------------------------------------
  useEffect(() => {
    (async () => {
      sequence.current = Date.now();

      // Answers left here by an earlier session — the app was closed, the PC
      // restarted, or the paper ended with no internet. They go up before the
      // paper is reopened, because reopening an expired paper closes it.
      const held = readHeld(attemptId);
      if (held.submit || Object.keys(held.answers).length) {
        try {
          const status = await sendHeld();
          if (status !== 'in_progress') { await showResult(); return; }
        } catch (err) {
          if (err instanceof ApiError && err.status === 409) { await showResult(err.message); return; }
          if (isOffline(err)) {
            if (held.submit) { finished.current = true; setPhase('waiting'); return; }
            setError('No internet connection. Your saved answers are safe on this computer — reopen this paper when the connection is back.');
            return;
          }
          // Refused for another reason: open the paper; the autosave retries.
        }
      }

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

      // Fetch every image now, while there is a connection, so a diagram on
      // question 38 still shows if the line drops at question 5.
      for (const q of p.questions) {
        const media = [...q.media, ...(q.group?.media ?? []), ...q.options.flatMap((o) => (o.image ? [o.image] : []))];
        for (const m of media) if (m?.url) new Image().src = m.url;
      }

      const local = readHeld(attemptId).answers;
      setPaper(p);
      setResponses(Object.fromEntries(p.questions.map((q) => [q.question_id, local[q.question_id]?.response ?? q.saved_response ?? null])));
      setFlags(Object.fromEntries(Object.entries(local).map(([id, a]) => [id, a.flagged])));
      // A paper already in progress (resumed) skips the instructions screen.
      setStarted(p.questions.some((q) => q.saved_response || local[q.question_id]));
      if (p.attempt.seconds_remaining !== null) {
        deadline.current = Date.now() + p.attempt.seconds_remaining * 1000;
        setLeft(p.attempt.seconds_remaining);
      }
      setPhase('sitting');
    })();
  }, [attemptId, showResult, sendHeld]);

  // ---- autosave --------------------------------------------------------
  const flush = useCallback(async (): Promise<void> => {
    if (saving.current) await saving.current;
    if (finished.current) return;
    // In the last seconds the server may already count the paper as over, and
    // an expired save is refused unsaved. The submit carries these instead.
    if (deadline.current !== null && deadline.current - Date.now() < END_MARGIN_MS) return;
    const sent = readHeld(attemptId).answers;
    const answers = payloadOf(sent);
    if (!answers.length) return;
    setSaveState('saving');
    const run = (async () => {
      try {
        const res = await fetchApi<{ seconds_remaining: number | null }>(`/cbt/attempts/${attemptId}/answers`, {
          method: 'POST',
          body: JSON.stringify({ answers }),
        });
        if (res.seconds_remaining !== null) deadline.current = Date.now() + res.seconds_remaining * 1000;
        // Forget only what was sent; anything answered since stays held.
        const held = readHeld(attemptId);
        for (const [id, a] of Object.entries(sent)) {
          if (held.answers[Number(id)]?.client_sequence === a.client_sequence) delete held.answers[Number(id)];
        }
        writeHeld(attemptId, held);
        setSaveState(Object.keys(held.answers).length ? 'saving' : 'saved');
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
          await showResult(err.message);
          return;
        }
        setSaveState('offline');
      }
    })();
    saving.current = run;
    await run;
    saving.current = null;
  }, [attemptId, showResult]);

  useEffect(() => {
    if (phase !== 'sitting') return;
    const t = setInterval(() => { void flush(); }, 4000);
    return () => clearInterval(t);
  }, [phase, flush]);

  const answer = (qid: number, r: Response) => {
    setResponses((prev) => ({ ...prev, [qid]: r }));
    hold(qid, r, !!latest.current.flags[qid]);
    setSaveState((s) => (s === 'offline' ? s : 'saving'));
  };
  const toggleFlag = (qid: number) => {
    const flagged = !latest.current.flags[qid];
    setFlags((prev) => ({ ...prev, [qid]: flagged }));
    hold(qid, latest.current.responses[qid] ?? null, flagged);
  };

  // ---- submit ----------------------------------------------------------
  const submit = useCallback(async () => {
    if (finished.current) return;
    // The decision is recorded on this machine first: with the line down the
    // paper is still over, and it is sent the moment the line returns.
    finished.current = true;
    writeHeld(attemptId, { ...readHeld(attemptId), submit: true });
    setPhase('submitting');
    if (saving.current) await saving.current;
    try {
      await sendHeld();
      await showResult();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) { await showResult(err.message); return; }
      if (!isOffline(err)) setError(errorText(err, 'Could not submit yet.'));
      setPhase('waiting');
    }
  }, [attemptId, sendHeld, showResult]);

  // A finished paper waiting for the internet: retry until it lands.
  useEffect(() => {
    if (phase !== 'waiting') return;
    let busy = false;
    const retry = async () => {
      if (busy) return;
      busy = true;
      try {
        await sendHeld();
        await showResult();
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) await showResult(err.message);
      } finally {
        busy = false;
      }
    };
    const t = setInterval(() => { void retry(); }, 10000);
    window.addEventListener('online', retry);
    return () => { clearInterval(t); window.removeEventListener('online', retry); };
  }, [phase, sendHeld, showResult]);

  // ---- clock -----------------------------------------------------------
  useEffect(() => {
    if (phase !== 'sitting' || deadline.current === null) return;
    const t = setInterval(() => {
      const s = Math.max(0, Math.round(((deadline.current ?? 0) - Date.now()) / 1000));
      setLeft(s);
      if (s === 0) void submit();
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
    document.addEventListener('visibilitychange', vis);
    document.addEventListener('copy', copy);
    document.addEventListener('paste', paste);
    window.addEventListener('offline', offline);
    window.addEventListener('online', online);
    return () => {
      document.removeEventListener('visibilitychange', vis);
      document.removeEventListener('copy', copy);
      document.removeEventListener('paste', paste);
      window.removeEventListener('offline', offline);
      window.removeEventListener('online', online);
    };
  }, [phase, started, report, flush]);

  // ---- render ----------------------------------------------------------
  if (phase === 'done' && result) {
    // Calm on purpose: the first thing after a test says "it is safe, you are
    // done", then the facts, then the score if the school releases it now.
    const total = paper?.questions.length ?? 0;
    const answered = paper ? paper.questions.filter((q) => isAnswered(responses[q.question_id])).length : 0;
    return (
      <main className="min-h-screen bg-canvas px-4 py-10 text-ink sm:py-16">
        <div className="mx-auto max-w-xl">
          <div className="sp-settle text-center">
            <div className="mx-auto flex h-24 w-24 items-center justify-center rounded-full bg-positive-soft">
              <svg viewBox="0 0 24 24" className="h-12 w-12 text-positive" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
            </div>
            <h1 className="mt-6 font-headline text-3xl font-bold text-brand">Paper submitted</h1>
            <p className="mt-2 text-ink-muted">Well done. Your answers are safely in — take a breath.</p>
            {paper?.exam.title && <p className="mt-1 text-sm font-medium text-ink-muted">{paper.exam.title}</p>}
          </div>
          {paper && (
            <dl className="mt-8 divide-y divide-line-soft rounded-xl bg-surface px-5 shadow-sm ring-1 ring-line-soft">
              <div className="flex justify-between py-3 text-sm"><dt className="text-ink-muted">Questions answered</dt><dd className="font-semibold">{answered} of {total}</dd></div>
              <div className="flex justify-between py-3 text-sm"><dt className="text-ink-muted">Submitted</dt><dd className="font-semibold">{new Date().toLocaleString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</dd></div>
            </dl>
          )}
          <div className="mt-6">
            <CbtResult data={result} />
          </div>
          <div className="mt-8 text-center">
            <Link href="/student/tests" className="inline-block rounded-lg bg-brand px-6 py-2.5 text-sm font-medium text-white hover:bg-brand-soft">Done</Link>
          </div>
        </div>
      </main>
    );
  }

  if (phase === 'waiting') {
    return (
      <main className="mx-auto min-h-screen max-w-2xl bg-canvas p-6 text-ink">
        <h1 className="font-headline text-2xl font-bold text-brand">Your paper is finished</h1>
        <section className="mt-5 rounded-xl bg-surface p-5 shadow-sm ring-1 ring-line-soft">
          <p className="text-sm">There is no internet connection right now. <strong>Every answer you gave is saved on this computer</strong> and will be sent automatically when the connection is back.</p>
          <p className="mt-3 text-sm text-ink-muted">You do not need to do anything. Please leave this computer on — if it is switched off, your answers are sent the next time this paper is opened on it.</p>
          <ErrorNote message={error} />
          <p className="mt-4 flex items-center gap-2 text-xs text-ink-muted"><span className="inline-block h-2 w-2 animate-pulse rounded-full bg-accent" /> Waiting for the internet…</p>
        </section>
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
            <li>Answers save automatically, even if the internet goes off. You can go back to any question before you submit.</li>
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
          <p className="text-xs text-ink-muted">{answeredCount} of {qs.length} answered · <span className={saveState === 'offline' ? 'font-semibold text-accent' : ''}>{saveState === 'saved' ? 'All answers saved' : saveState === 'saving' ? 'Saving…' : 'No internet — your answers are saved on this computer and will send when it is back'}</span></p>
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
