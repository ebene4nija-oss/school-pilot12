'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { useCookieRole } from '@/components/Sidebar';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * CBT oversight: approve exams, watch an exam live, read how it went, and
 * correct a faulty question. Every intervention asks for a reason, which is
 * logged. A regrade is always previewed first and can be rolled back.
 */

type Tab = 'monitor' | 'analysis' | 'regrade';

interface Exam { id: number; title: string; status: string; approval_status: string | null; class_id: number | null }
interface MonitorRow { student_id: number; name: string; admission_number: string; state: string; attempt_id: number | null; answered: number; questions: number; seconds_left: number | null; extra_time_minutes: number; last_seen: string | null }
interface QuestionStat { question_id: number; topic: string | null; served: number; correct: number; incorrect: number; skipped: number; percent_correct: number | null; option_counts: Record<string, number>; correct_answer: string | null }
interface Analysis {
  class_report: { students: number; participants: number; non_participants: number; highest: number | null; lowest: number | null; average: number | null; pass_rate: number | null; grade_distribution: Record<string, number> };
  attendance: { started: number; submitted: number; absent: number; absent_students: { id: number; name: string }[] };
  questions: QuestionStat[];
  stale_results: { question_id: number; attempts: number }[];
}
interface Preview { attempts: number; up: number; down: number; unchanged: number; grade_changes: number; pass_fail_changes: number; exams: { average_before: number; average_after: number }[]; students: { student_name: string; old_percentage: number; new_percentage: number; old_grade: string; new_grade: string }[] }
interface Job { id: number; action: string; reason: string; status: string; summary: { changed: number } | null; applied_at: string }

const STATE: Record<string, { tone: 'green' | 'amber' | 'red' | 'slate' | 'blue'; label: string }> = {
  submitted: { tone: 'green', label: '✓ Submitted' }, in_progress: { tone: 'blue', label: '● Writing' },
  disconnected: { tone: 'red', label: '! Disconnected' }, not_started: { tone: 'slate', label: '○ Not started' },
};

export default function CbtOversightPage() {
  // The server allows resets to school admins only; offer it to no one else.
  const admin = useCookieRole() === 'school_admin';
  const [exams, setExams] = useState<Exam[]>([]);
  const [examId, setExamId] = useState('');
  const [tab, setTab] = useState<Tab>('monitor');
  const [monitor, setMonitor] = useState<{ counts: Record<string, number>; students: MonitorRow[]; polled_at: string } | null>(null);
  const [analysis, setAnalysis] = useState<Analysis | null>(null);
  const [jobs, setJobs] = useState<Job[]>([]);
  const [regrade, setRegrade] = useState({ question_id: '', action: 'change_key', key: '', keys: '', scope: 'exam', reason: '' });
  const [preview, setPreview] = useState<Preview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const res = await fetchApi<{ data?: Exam[] } | Exam[]>('/cbt/exams');
        const list = Array.isArray(res) ? res : (res.data ?? []);
        setExams(list);
        if (list[0]) setExamId(String(list[0].id));
      } catch (err) {
        setError(errorText(err, 'Could not load exams.'));
      }
    })();
  }, []);

  const loadTab = useCallback(async () => {
    if (!examId) return;
    if (tab === 'monitor') setMonitor(await fetchApi(`/cbt/exams/${examId}/monitor`));
    if (tab === 'analysis' || tab === 'regrade') setAnalysis(await fetchApi(`/cbt/exams/${examId}/analysis`));
    if (tab === 'regrade') setJobs((await fetchApi<{ data: Job[] }>(`/cbt/exams/${examId}/regrades`)).data);
  }, [examId, tab]);

  useEffect(() => {
    (async () => {
      try { await loadTab(); } catch (err) { setError(errorText(err, 'Could not load this view.')); }
    })();
    if (tab !== 'monitor') return;
    // Polling is enough for a live monitor (§9); push comes later.
    const timer = setInterval(() => { loadTab().catch(() => undefined); }, 15000);
    return () => clearInterval(timer);
  }, [loadTab, tab]);

  async function post(path: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(res.message ?? done);
      await loadTab();
      return res;
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
      return null;
    } finally {
      setBusy(false);
    }
  }

  function withReason(path: string, extra: Record<string, unknown> = {}, prompt = 'Reason (recorded):') {
    const reason = window.prompt(prompt);
    if (reason && reason.trim().length >= 3) post(path, { ...extra, reason }, 'Done.');
  }

  const regradeBody = () => ({
    question_id: Number(regrade.question_id), action: regrade.action, scope: regrade.scope,
    params: regrade.action === 'change_key' ? { correct_answer: regrade.key } : regrade.action === 'accept_answers' ? { correct_answers: regrade.keys.split(',').map((k) => k.trim()).filter(Boolean) } : {},
  });

  const exam = exams.find((e) => String(e.id) === examId);
  const pending = exams.filter((e) => e.approval_status === 'pending');

  return (
    <PageShell title="CBT oversight" subtitle="Approve, monitor, analyse and correct exams. Every intervention is logged with its reason."
      actions={<select className={inputClass} aria-label="Exam" value={examId} onChange={(e) => { setExamId(e.target.value); setPreview(null); }}>{exams.map((e) => <option key={e.id} value={e.id}>{e.title}</option>)}</select>}>
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {pending.length > 0 && (
        <Card className="mb-6" title="Awaiting approval">
          <ul className="space-y-2 text-sm">{pending.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center gap-2">{e.title}
              <Button variant="secondary" disabled={busy} onClick={() => post(`/cbt/exams/${e.id}/approval`, { approve: true }, 'Approved.')}>Approve</Button>
              <Button variant="danger" disabled={busy} onClick={() => { const note = window.prompt('What needs changing?'); if (note) post(`/cbt/exams/${e.id}/approval`, { approve: false, note }, 'Returned.'); }}>Return</Button>
            </li>
          ))}</ul>
        </Card>
      )}

      {exam && exam.status === 'draft' && exam.approval_status !== 'pending' && (
        <p className="mb-4 text-sm">Draft. <button className="text-brand underline" onClick={() => post(`/cbt/exams/${exam.id}/submit-for-approval`, {}, 'Submitted.')}>Submit for approval</button></p>
      )}

      <nav className="mb-6 flex gap-2">
        {(['monitor', 'analysis', 'regrade'] as Tab[]).map((t) => <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => setTab(t)}>{{ monitor: 'Live monitor', analysis: 'Analysis', regrade: 'Correct a question' }[t]}</Button>)}
      </nav>

      {!examId ? <Empty>No exams yet.</Empty> : tab === 'monitor' ? (
        !monitor ? <Loading /> : (
          <>
            <div className="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
              {(['in_progress', 'disconnected', 'submitted', 'not_started'] as const).map((k) => <StatTile key={k} label={STATE[k].label} value={monitor.counts[k] ?? 0} tone={STATE[k].tone === 'blue' ? 'blue' : STATE[k].tone === 'red' ? 'red' : STATE[k].tone === 'green' ? 'green' : 'slate'} />)}
            </div>
            <p className="mb-2 text-xs text-ink-muted">Refreshes every 15 seconds · last {new Date(monitor.polled_at).toLocaleTimeString('en-NG')}</p>
            <Card>
              <Table columns={['Student', 'State', 'Progress', 'Time left', '']}>
                {monitor.students.map((s) => (
                  <tr key={s.student_id}>
                    <Cell>{s.name}<span className="block text-xs text-ink-muted">{s.admission_number}</span></Cell>
                    <Cell><Badge tone={STATE[s.state]?.tone ?? 'slate'}>{STATE[s.state]?.label ?? s.state}</Badge></Cell>
                    <Cell>{s.questions ? `${s.answered}/${s.questions}` : '—'}</Cell>
                    <Cell>{s.seconds_left != null ? `${Math.floor(s.seconds_left / 60)} min` : '—'}{s.extra_time_minutes ? ` (+${s.extra_time_minutes})` : ''}</Cell>
                    <Cell>
                      {s.attempt_id && ['in_progress', 'disconnected'].includes(s.state) && (
                        <div className="flex flex-wrap gap-2">
                          <Button variant="secondary" disabled={busy} onClick={() => { const m = window.prompt('Add how many minutes?', '10'); if (m) withReason(`/cbt/attempts/${s.attempt_id}/extend`, { minutes: Number(m) }); }}>Extend</Button>
                          <Button variant="secondary" disabled={busy} onClick={() => withReason(`/cbt/attempts/${s.attempt_id}/force-submit`)}>Submit</Button>
                          {admin && <Button variant="danger" disabled={busy} onClick={() => withReason(`/cbt/attempts/${s.attempt_id}/reset`, {}, 'Reset voids this attempt so the student restarts. Reason:')}>Reset</Button>}
                        </div>
                      )}
                      {/* A sat paper can be reset too (a power cut, the wrong
                          student signed in); admin only, with a reason, and
                          the voided attempt is kept. */}
                      {admin && s.attempt_id && s.state === 'submitted' && (
                        <Button variant="danger" disabled={busy} onClick={() => withReason(`/cbt/attempts/${s.attempt_id}/reset`, {}, `Reset ${s.name}'s submitted paper so they can sit it again? Their answers are kept for the record. Reason:`)}>Reset</Button>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            </Card>
            <Button className="mt-4" variant="secondary" disabled={busy} onClick={() => { const m = window.prompt('Add how many minutes for everyone writing?', '10'); if (m) withReason(`/cbt/exams/${examId}/extend`, { minutes: Number(m) }); }}>Extend for everyone</Button>
          </>
        )
      ) : !analysis ? <Loading /> : tab === 'analysis' ? (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatTile label="Participants" value={`${analysis.class_report.participants} of ${analysis.class_report.students}`} />
            <StatTile label="Average" value={analysis.class_report.average ?? '—'} />
            <StatTile label="Pass rate" value={analysis.class_report.pass_rate != null ? `${analysis.class_report.pass_rate}%` : '—'} tone="green" />
            <StatTile label="Absent" value={analysis.attendance.absent} tone="amber" hint={analysis.attendance.absent_students.map((s) => s.name).join(', ')} />
          </div>
          <Card title="Questions, hardest first" description="A very low percentage on taught material usually means the question is faulty, not the class.">
            <Table columns={['Question', 'Topic', '% correct', 'Correct', 'Wrong', 'Skipped', 'Answers chosen (key)']}>
              {analysis.questions.map((q) => (
                <tr key={q.question_id}>
                  <Cell>#{q.question_id}{analysis.stale_results.some((s) => s.question_id === q.question_id) && <span className="block text-xs text-caution">Marked on an older version</span>}</Cell>
                  <Cell>{q.topic ?? '—'}</Cell><Cell>{q.percent_correct ?? '—'}%</Cell><Cell>{q.correct}</Cell><Cell>{q.incorrect}</Cell><Cell>{q.skipped}</Cell>
                  <Cell>{Object.entries(q.option_counts).map(([k, n]) => `${k === q.correct_answer ? '✓' : ''}${k}: ${n}`).join(' · ')}</Cell>
                </tr>
              ))}
            </Table>
          </Card>
        </div>
      ) : (
        <div className="grid gap-6 xl:grid-cols-2">
          <Card title="Correct a faulty question" description="Preview first. Applying needs a reason, adds a new result revision and keeps the old one.">
            <div className="grid gap-3">
              <Field label="Question">
                <select className={inputClass} value={regrade.question_id} onChange={(e) => { setRegrade({ ...regrade, question_id: e.target.value }); setPreview(null); }}>
                  <option value="">Choose…</option>
                  {analysis.questions.map((q) => <option key={q.question_id} value={q.question_id}>#{q.question_id} · {q.topic ?? 'no topic'} · {q.percent_correct}% correct</option>)}
                </select>
              </Field>
              <Field label="Correction">
                <select className={inputClass} value={regrade.action} onChange={(e) => { setRegrade({ ...regrade, action: e.target.value }); setPreview(null); }}>
                  <option value="change_key">Change the answer key</option><option value="accept_answers">Accept more than one answer</option>
                  <option value="credit_all">Give everyone full marks</option><option value="drop">Drop the question and rescale</option>
                </select>
              </Field>
              {regrade.action === 'change_key' && <Field label="Correct option"><input className={inputClass} value={regrade.key} onChange={(e) => setRegrade({ ...regrade, key: e.target.value })} placeholder="e.g. C" /></Field>}
              {regrade.action === 'accept_answers' && <Field label="Accepted options, comma-separated"><input className={inputClass} value={regrade.keys} onChange={(e) => setRegrade({ ...regrade, keys: e.target.value })} placeholder="e.g. A, C" /></Field>}
              <Field label="Scope">
                <select className={inputClass} value={regrade.scope} onChange={(e) => setRegrade({ ...regrade, scope: e.target.value })}><option value="exam">This exam only</option><option value="all_exams">Every exam that used this question</option></select>
              </Field>
              <Button variant="secondary" disabled={busy || !regrade.question_id} onClick={async () => {
                setError(null);
                try { setPreview(await fetchApi<Preview>(`/cbt/exams/${examId}/regrade/preview`, { method: 'POST', body: JSON.stringify(regradeBody()) })); } catch (err) { setError(errorText(err, 'Could not preview.')); }
              }}>Preview the impact</Button>
            </div>
            {preview && (
              <div className="mt-4 text-sm">
                <p>{preview.attempts} result(s): ▲ {preview.up} up · ▼ {preview.down} down · {preview.unchanged} unchanged · {preview.grade_changes} grade change(s) · {preview.pass_fail_changes} pass/fail change(s)</p>
                {preview.exams.map((e, i) => <p key={i}>Class average {e.average_before} → {e.average_after}</p>)}
                <ul className="mt-2 max-h-48 overflow-y-auto">{preview.students.filter((s) => s.old_percentage !== s.new_percentage).map((s, i) => <li key={i}>{s.student_name}: {s.old_percentage}% ({s.old_grade}) → {s.new_percentage}% ({s.new_grade})</li>)}</ul>
                <Field label="Reason (recorded)"><input className={inputClass} value={regrade.reason} onChange={(e) => setRegrade({ ...regrade, reason: e.target.value })} /></Field>
                <Button className="mt-2" variant="accent" disabled={busy || regrade.reason.length < 5} onClick={async () => { if (await post(`/cbt/exams/${examId}/regrade`, { ...regradeBody(), reason: regrade.reason }, 'Regraded.')) setPreview(null); }}>Apply regrade</Button>
              </div>
            )}
          </Card>
          <Card title="Regrade history">
            {jobs.length === 0 ? <Empty>No regrades.</Empty> : (
              <ul className="space-y-2 text-sm">{jobs.map((j) => (
                <li key={j.id} className="rounded-lg p-2 ring-1 ring-line-soft">
                  <Badge tone={j.status === 'applied' ? 'blue' : 'slate'}>{j.status}</Badge> #{j.id} · {j.action.replace('_', ' ')} · {j.summary?.changed ?? 0} changed
                  <p className="text-xs text-ink-muted">{j.reason} · {new Date(j.applied_at).toLocaleString('en-NG')}</p>
                  {j.status === 'applied' && <button className="text-xs text-negative underline" onClick={() => withReason(`/cbt/regrades/${j.id}/rollback`, {}, 'Why roll this back?')}>Roll back</button>}
                </li>
              ))}</ul>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
