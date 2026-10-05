'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';
import { myStudentId } from '@/lib/student';

/**
 * My results and report card (TASK 10, 11).
 *
 * Released terms only. Where the school sells result PINs the breakdown and
 * the printable card open only with a PIN, exactly as for a parent — the
 * portal is not a way round the result checker. A student can type in a PIN
 * the school or a parent gave them; buying one online is left to parents.
 * A teacher's comment shows only once a teacher wrote or approved it.
 */

interface Sheet {
  status: 'released' | 'not_released' | 'no_scores' | 'locked';
  message?: string;
  average?: number;
  preview?: { subjects: number; average: number };
  subjects?: { subject: string; state: string; components: Record<string, number>; total: number | null; grade: string | null; position: number | null; comment: string | null }[];
}
type Summary =
  | { status: 'not_released' | 'no_scores'; message: string }
  | { status: 'released'; term_name: string; unlocked: boolean; preview: { subjects_count: number; average: number; position: number | null; class_size: number }; access: { serial: string; views_remaining: number } | null; unlock_options: { redeem_pin: { available: boolean; description: string } } | null };

const label = (k: string) => k.replaceAll('_', ' ').replace(/\bca(\d)?\b/i, (m) => m.toUpperCase());

export default function StudentResults() {
  const [studentId, setStudentId] = useState<number | null>(null);
  const [terms, setTerms] = useState<{ id: number; name: string; released: boolean }[] | null>(null);
  const [termId, setTermId] = useState('');
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    myStudentId().then(setStudentId).catch((err) => setError(errorText(err, 'Could not load your record.')));
    fetchApi<{ terms: { id: number; name: string; released: boolean }[] }>('/me/results')
      .then((res) => {
        setTerms(res.terms);
        const first = res.terms.find((t) => t.released);
        if (first) setTermId(String(first.id));
      })
      .catch((err) => setError(errorText(err, 'Could not load your results.')));
  }, []);

  const load = useCallback(async () => {
    if (!termId) return;
    setSheet(null);
    setSummary(null);
    try {
      setSheet(await fetchApi<Sheet>(`/me/results?term_id=${termId}`));
      if (studentId) setSummary(await fetchApi<Summary>(`/result-checker/${studentId}/${termId}/summary`));
    } catch (err) {
      setError(errorText(err, 'Could not load this term.'));
    }
  }, [termId, studentId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function redeem(e: React.FormEvent) {
    e.preventDefault();
    if (!studentId || !termId) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message: string }>('/result-checker/redeem', { method: 'POST', body: JSON.stringify({ pin: pin.trim(), student_id: studentId, term_id: Number(termId) }) });
      setNotice(res.message);
      setPin('');
      await load();
    } catch (err) {
      setError(errorText(err, 'That PIN was not accepted.'));
    } finally {
      setBusy(false);
    }
  }

  function openCard(format: 'html' | 'pdf') {
    if (!studentId || summary?.status !== 'released') return;
    const left = summary.access?.views_remaining;
    if (left !== undefined && !window.confirm(`Opening the report card uses one of the ${left} views left on this PIN. Continue?`)) return;
    window.open(`/api/backend/result-checker/${studentId}/${termId}${format === 'pdf' ? '?format=pdf' : ''}`, '_blank', 'noopener');
    setTimeout(() => void load(), 2500);
  }

  return (
    <PageShell title="Results & report card" subtitle="Your results appear once your school releases them.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {!terms ? (!error && <Loading />) : terms.length === 0 ? <Empty>No terms yet.</Empty> : (
        <>
          <div className="mb-6 max-w-xs">
            <Field label="Term">
              <select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)}>
                <option value="">Choose a term…</option>
                {terms.map((t) => <option key={t.id} value={t.id} disabled={!t.released}>{t.name}{t.released ? '' : ' (not released)'}</option>)}
              </select>
            </Field>
          </div>

          {!termId ? <Empty>No results have been released for you yet.</Empty> : !sheet ? <Loading /> : sheet.status === 'not_released' || sheet.status === 'no_scores' ? <Empty>{sheet.message}</Empty> : (
            <div className="space-y-5">
              <div className="grid gap-4 sm:grid-cols-3">
                <StatTile label="Average" value={`${(sheet.average ?? sheet.preview?.average ?? 0).toFixed(1)}%`} tone="green" />
                {summary?.status === 'released' && summary.preview.position && <StatTile label="Position in class" value={`${summary.preview.position} of ${summary.preview.class_size}`} />}
                <StatTile label="Subjects" value={sheet.subjects?.length ?? sheet.preview?.subjects ?? 0} />
              </div>

              {summary?.status === 'released' && (
                <Card title="Report card">
                  {summary.unlocked ? (
                    <>
                      {summary.access && <p className="mb-3 text-xs text-ink-muted">Unlocked with PIN {summary.access.serial} · {summary.access.views_remaining} view(s) left</p>}
                      <div className="flex flex-wrap gap-2">
                        <Button onClick={() => openCard('html')}>Open report card</Button>
                        <Button variant="secondary" onClick={() => openCard('pdf')}>Download PDF</Button>
                      </div>
                    </>
                  ) : (
                    <form onSubmit={redeem} className="max-w-sm space-y-2">
                      <p className="text-sm text-ink-muted">Your school releases full results and the report card with a result-checker PIN. Ask your parent or guardian, or type in a PIN you were given.</p>
                      {summary.unlock_options?.redeem_pin.available !== false && (
                        <>
                          <input className={inputClass} value={pin} onChange={(e) => setPin(e.target.value)} placeholder="Result PIN" autoComplete="off" required />
                          <Button type="submit" variant="secondary" disabled={busy || !pin.trim()}>Use this PIN</Button>
                        </>
                      )}
                    </form>
                  )}
                </Card>
              )}

              {sheet.status === 'released' && sheet.subjects && (
                <Card title="Subject by subject">
                  <Table columns={['Subject', 'Scores', 'Total', 'Grade', 'Teacher’s comment']}>
                    {sheet.subjects.map((s) => (
                      <tr key={s.subject}>
                        <Cell className="font-medium">{s.subject}</Cell>
                        <Cell className="text-xs text-ink-muted">{s.state !== 'scored' ? s.state : Object.entries(s.components).map(([k, v]) => `${label(k)} ${v}`).join(' · ')}</Cell>
                        <Cell>{s.total ?? '—'}</Cell>
                        <Cell>{s.grade && <Badge tone="blue">{s.grade}</Badge>}{s.position ? <span className="ml-1 text-xs text-ink-muted">pos. {s.position}</span> : null}</Cell>
                        <Cell className="text-sm">{s.comment ?? ''}</Cell>
                      </tr>
                    ))}
                  </Table>
                </Card>
              )}
            </div>
          )}
        </>
      )}
    </PageShell>
  );
}
