'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Loading, PageShell } from '@/components/Page';
import { when } from '@/lib/student';
import CbtResult, { type ResultResponse } from './CbtResult';

/**
 * Tests (TASK 09): what I can sit now, what is coming, and the results of
 * what I have sat. Starting a paper starts the server's clock, so it asks
 * first; a paper left open (a closed tab, a dropped connection) resumes with
 * whatever time the server says is left.
 */

interface Available { exam_id: number; title: string; subject: string | null; duration_minutes: number; total_marks: number; closes_at: string | null; attempts_used: number; attempts_allowed: number; can_start: boolean; resumable_attempt_id: number | null }
interface MyTest { exam_id: number; title: string; subject: string | null; duration_minutes: number; opens_at: string | null; closes_at: string | null; state: 'upcoming' | 'open' | 'closed'; attempts: { attempt_id: number; attempt_number: number; status: string; submitted_at: string | null; percentage: number | null }[] }

export default function StudentTests() {
  const router = useRouter();
  const [available, setAvailable] = useState<Available[] | null>(null);
  const [mine, setMine] = useState<MyTest[] | null>(null);
  const [result, setResult] = useState<ResultResponse | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    fetchApi<{ data: Available[] }>('/cbt/available').then((r) => setAvailable(r.data)).catch((err) => { setAvailable([]); setError(errorText(err, 'Could not load your tests.')); });
    fetchApi<{ data: MyTest[] }>('/me/tests').then((r) => setMine(r.data)).catch(() => setMine([]));
  }, []);

  useEffect(() => { load(); }, [load]);

  async function start(exam: Available) {
    if (exam.resumable_attempt_id) {
      router.push(`/student/tests/${exam.resumable_attempt_id}`);
      return;
    }
    const left = exam.attempts_allowed - exam.attempts_used;
    if (!window.confirm(`Start "${exam.title}"?\n\nYou will have ${exam.duration_minutes} minutes, and the clock keeps running if you close the page. This uses 1 of your ${left} remaining attempt(s).`)) return;
    setBusy(true);
    setError(null);
    try {
      const paper = await fetchApi<{ attempt: { id: number } }>(`/cbt/exams/${exam.exam_id}/start`, { method: 'POST', body: '{}' });
      // Hand the paper to the exam screen so opening it is not logged as a resume.
      try { sessionStorage.setItem(`cbt-paper-${paper.attempt.id}`, JSON.stringify(paper)); } catch { /* storage blocked: the screen fetches it */ }
      router.push(`/student/tests/${paper.attempt.id}`);
    } catch (err) {
      setError(errorText(err, 'Could not start this test.'));
      setBusy(false);
    }
  }

  async function showResult(attemptId: number) {
    setError(null);
    try {
      setResult(await fetchApi<ResultResponse>(`/cbt/attempts/${attemptId}/result`));
    } catch (err) {
      setError(errorText(err, 'Could not load that result.'));
    }
  }

  const upcoming = (mine ?? []).filter((t) => t.state === 'upcoming');
  const sat = (mine ?? []).filter((t) => t.attempts.some((a) => a.status !== 'in_progress'));

  return (
    <PageShell title="Tests" subtitle="Computer-based tests set for your class.">
      <ErrorNote message={error} />

      {result && (
        <div className="mb-6">
          <CbtResult data={result} />
          <Button variant="secondary" className="mt-3" onClick={() => setResult(null)}>Close result</Button>
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Open now">
          {!available ? <Loading /> : available.length === 0 ? <Empty>No test is open for you right now.</Empty> : (
            <ul className="space-y-3">
              {available.map((e) => (
                <li key={e.exam_id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line-soft p-4">
                  <div>
                    <p className="font-medium">{e.title}</p>
                    <p className="text-xs text-ink-muted">
                      {[e.subject, `${e.duration_minutes} min`, `${e.total_marks} marks`, `attempt ${Math.min(e.attempts_used + 1, e.attempts_allowed)} of ${e.attempts_allowed}`, e.closes_at && `closes ${when(e.closes_at)}`].filter(Boolean).join(' · ')}
                    </p>
                  </div>
                  {e.resumable_attempt_id ? (
                    <Button variant="accent" onClick={() => start(e)}>Continue</Button>
                  ) : e.can_start ? (
                    <Button disabled={busy} onClick={() => start(e)}>Start</Button>
                  ) : (
                    <Badge>no attempts left</Badge>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title="Coming up">
          {!mine ? <Loading /> : upcoming.length === 0 ? <Empty>Nothing scheduled.</Empty> : (
            <ul className="space-y-2 text-sm">
              {upcoming.map((t) => (
                <li key={t.exam_id}><span className="font-medium">{t.title}</span> · {t.subject}{t.opens_at && ` · opens ${when(t.opens_at)}`} · {t.duration_minutes} min</li>
              ))}
            </ul>
          )}
        </Card>

        <Card title="Tests you have sat" className="lg:col-span-2">
          {!mine ? <Loading /> : sat.length === 0 ? <Empty>You have not sat a test yet.</Empty> : (
            <ul className="divide-y divide-line-soft text-sm">
              {sat.flatMap((t) => t.attempts.filter((a) => a.status !== 'in_progress').map((a) => (
                <li key={a.attempt_id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                  <span>
                    <span className="font-medium">{t.title}</span>
                    <span className="text-ink-muted"> · {[t.subject, t.attempts.length > 1 && `attempt ${a.attempt_number}`, a.submitted_at && when(a.submitted_at)].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span className="flex items-center gap-2">
                    {a.percentage !== null ? <Badge tone={a.percentage >= 50 ? 'green' : 'red'}>{a.percentage}%</Badge> : <Badge>{a.status === 'graded' ? 'awaiting release' : a.status}</Badge>}
                    <Button variant="secondary" onClick={() => showResult(a.attempt_id)}>View</Button>
                  </span>
                </li>
              )))}
            </ul>
          )}
        </Card>
      </div>
    </PageShell>
  );
}
