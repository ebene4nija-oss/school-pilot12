'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * Student progress and analysis, for teachers and administrators.
 *
 * Read-only on results: every figure comes from the API, which reads locked
 * term records and the central calculator. Changes are in points. Insights
 * are labelled as automated and kept apart from teacher remarks. Each chart
 * has a table beside it, and status is never colour alone.
 */

type Tab = 'overview' | 'subjects' | 'assessments' | 'attendance' | 'support' | 'class';

interface TrendPoint { term_id: number | null; label: string; average: number | null; class_average: number | null; provisional: boolean; scale_changed: boolean }
interface SubjectRow { subject_id: number | null; subject: string; state: string; ca: number | null; exam: number | null; total: number | null; grade: string | null; previous_total: number | null; change: number | null; class_average: number | null; gap: number | null; position: number | null }
interface Insight { rule: string; category: string; severity: string; text: string }
interface Remark { id: number; teacher: string; teacher_id: number; subject: string | null; body: string; visibility: string | null; created_at: string; label: string }
interface Plan { id: number; subject: string | null; issue: string; baseline: string; target: string; action: string; responsible_teacher: string; review_date: string; review_due: boolean; status: string; outcome: string | null }
interface Progress {
  student: { id: number; name: string; admission_number: string; class: string | null; class_id: number | null; class_teacher: string | null; status: string };
  period: { term_id: number; label: string; provisional: boolean } | null;
  overview: { average: number | null; previous_average: number | null; change_points: number | null; subjects_passed: number; subjects_counted: number; pass_mark: number; attendance_rate: number | null; position: number | null; class_size: number | null; provisional: boolean } | null;
  subjects: SubjectRow[]; trend: TrendPoint[]; insights: Insight[]; flags: Insight[]; flags_total: number; insights_label: string;
  completion: { assigned: number; completed: number; missed: number; pending: number; awaiting_grading: number; rate: number | null };
  remarks: Remark[]; interventions: Plan[]; explanations: Record<string, string>; empty?: boolean;
}

const TOPIC_BADGE: Record<string, { tone: 'green' | 'amber' | 'red' | 'slate'; text: string }> = {
  strong: { tone: 'green', text: '▲ Strong' }, developing: { tone: 'amber', text: '● Developing' },
  needs_attention: { tone: 'red', text: '▼ Needs attention' }, insufficient_data: { tone: 'slate', text: '? Insufficient data' },
};

function points(n: number | null | undefined): string {
  if (n == null) return '—';
  return `${n > 0 ? '▲ +' : n < 0 ? '▼ ' : ''}${n} pts`;
}

/** One measure over terms; a dashed class-average line where staff may see it. */
function TrendChart({ data }: { data: TrendPoint[] }) {
  const w = 560, h = 200, pad = 32;
  const valid = data.filter((d) => d.average != null);
  if (valid.length < 2) return <p className="text-sm text-ink-muted">A trend needs at least two terms on record.</p>;
  const x = (i: number) => pad + (i * (w - pad * 2)) / (data.length - 1);
  const y = (v: number) => h - pad - (v / 100) * (h - pad * 2);
  const line = (key: 'average' | 'class_average') =>
    data.map((d, i) => (d[key] == null ? null : `${x(i)},${y(d[key] as number)}`)).filter(Boolean).join(' ');
  const hasClass = data.some((d) => d.class_average != null);

  return (
    <figure>
      <svg viewBox={`0 0 ${w} ${h}`} className="w-full text-brand" role="img" aria-label="Average by term">
        {[0, 50, 100].map((g) => (
          <g key={g}>
            <line x1={pad} x2={w - pad} y1={y(g)} y2={y(g)} className="stroke-line-soft" strokeWidth={1} />
            <text x={4} y={y(g) + 4} className="fill-ink-faint text-[10px]">{g}</text>
          </g>
        ))}
        {hasClass && <polyline points={line('class_average')} fill="none" className="stroke-ink-faint" strokeWidth={2} strokeDasharray="5 4" />}
        <polyline points={line('average')} fill="none" stroke="currentColor" strokeWidth={2} />
        {data.map((d, i) => d.average != null && (
          <g key={i}>
            <circle cx={x(i)} cy={y(d.average)} r={5} fill={d.provisional ? 'var(--color-surface, #fff)' : 'currentColor'} stroke="currentColor" strokeWidth={2}>
              <title>{`${d.label}: ${d.average}${d.provisional ? ' (provisional)' : ''}${d.class_average != null ? ` · class ${d.class_average}` : ''}`}</title>
            </circle>
            <text x={x(i)} y={h - 8} textAnchor="middle" className="fill-ink-muted text-[9px]">{d.label.split(' ').slice(-2).join(' ')}</text>
            {d.scale_changed && <text x={x(i)} y={pad - 8} textAnchor="middle" className="fill-caution text-[9px]">scale changed</text>}
          </g>
        ))}
      </svg>
      <figcaption className="mt-1 flex gap-4 text-xs text-ink-muted">
        <span>━ Student</span>{hasClass && <span>┅ Class average</span>}<span>○ provisional</span>
      </figcaption>
    </figure>
  );
}

/** Horizontal bars: each subject's total, the pass-mark line, last term's tick. */
function SubjectBars({ rows, passMark }: { rows: SubjectRow[]; passMark: number }) {
  const scored = rows.filter((r) => r.total != null);
  return (
    <div className="space-y-2" role="img" aria-label="Subject totals against the pass mark">
      {scored.map((r) => (
        <div key={r.subject} className="grid grid-cols-[8rem_1fr_3rem] items-center gap-2 text-xs" title={`${r.subject}: ${r.total}${r.previous_total != null ? ` (last term ${r.previous_total})` : ''}`}>
          <span className="truncate">{r.subject}</span>
          <div className="relative h-4 rounded bg-surface-raised">
            <div className={`h-4 rounded-r ${(r.total ?? 0) < passMark ? 'bg-caution' : 'bg-brand'}`} style={{ width: `${Math.min(100, r.total ?? 0)}%` }} />
            <div className="absolute inset-y-0 w-0.5 bg-ink" style={{ left: `${passMark}%` }} aria-hidden />
            {r.previous_total != null && <div className="absolute -top-1 h-6 w-0.5 bg-ink-faint" style={{ left: `${Math.min(100, r.previous_total)}%` }} aria-hidden />}
          </div>
          <span className="text-right font-medium">{r.total}</span>
        </div>
      ))}
      <p className="text-xs text-ink-muted">Dark line: pass mark ({passMark}). Thin tick: last term. Below the pass mark shows in amber and is also listed in the table.</p>
    </div>
  );
}

export default function ProgressPage() {
  const [picked, setPicked] = useState<PickedStudent | null>(() => {
    if (typeof window === 'undefined') return null;
    const id = Number(new URLSearchParams(window.location.search).get('student_id'));
    return id ? { id, name: null, admission_number: null, class: null } : null;
  });
  const [tab, setTab] = useState<Tab>('overview');
  const [data, setData] = useState<Progress | null>(null);
  const [termId, setTermId] = useState('');
  const [sort, setSort] = useState<'subject' | 'high' | 'low' | 'improved' | 'declined'>('subject');
  const [detail, setDetail] = useState<{ subject: { name: string }; history: { label: string; total: number; grade: string }[]; topics: { topic: string; questions: number; percentage: number | null; category: string; suggested_materials: { title: string }[] }[] } | null>(null);
  const [assess, setAssess] = useState<{ history: { kind: string; title: string; subject: string; date: string; score: number | null; total: number | null; percentage: number | null; awaiting_grading: boolean }[] } | null>(null);
  const [att, setAtt] = useState<{ totals: Record<string, number | null>; monthly: { month: string; present: number; absent: number; late: number; rate: number | null }[]; note: string } | null>(null);
  const [cls, setCls] = useState<{ provisional: boolean; subjects: { id: number; name: string }[]; class_stats: Record<string, number | null>; students: { id: number; name: string; scores: Record<string, number | null>; average: number | null; change: number | null; flags: string[] }[]; subject_stats: { subject: string; average: number | null; pass_rate: number | null; ca_exam_gap: number | null }[] } | null>(null);
  const [remark, setRemark] = useState({ body: '', visibility: 'staff_only' });
  const [plan, setPlan] = useState({ issue: '', baseline: '', target: '', action: '', review_date: '' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    if (!picked) return;
    setData(await fetchApi<Progress>(`/students/${picked.id}/progress${termId ? `?term_id=${termId}` : ''}`));
  }, [picked, termId]);

  useEffect(() => {
    (async () => {
      try {
        setError(null);
        await load();
      } catch (err) {
        setError(errorText(err, 'Could not load progress.'));
      }
    })();
  }, [load]);

  useEffect(() => {
    if (!picked) return;
    (async () => {
      try {
        if (tab === 'assessments') setAssess(await fetchApi(`/students/${picked.id}/progress/assessments`));
        if (tab === 'attendance') setAtt(await fetchApi(`/students/${picked.id}/progress/attendance`));
        if (tab === 'class' && data?.student.class_id) setCls(await fetchApi(`/classes/${data.student.class_id}/analysis${termId ? `?term_id=${termId}` : ''}`));
      } catch (err) {
        setError(errorText(err, 'Could not load this section.'));
      }
    })();
  }, [tab, picked, data?.student.class_id, termId]);

  async function post(path: string, body: unknown, method = 'POST') {
    setBusy(true);
    setError(null);
    try {
      await fetchApi(path, { method, body: JSON.stringify(body) });
      await load();
      return true;
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
      return false;
    } finally {
      setBusy(false);
    }
  }

  const sorted = [...(data?.subjects ?? [])].sort((a, b) => ({
    subject: a.subject.localeCompare(b.subject),
    high: (b.total ?? -1) - (a.total ?? -1),
    low: (a.total ?? 999) - (b.total ?? 999),
    improved: (b.change ?? -999) - (a.change ?? -999),
    declined: (a.change ?? 999) - (b.change ?? 999),
  }[sort]));

  const o = data?.overview;

  return (
    <PageShell title="Progress & analysis" subtitle="How a student is doing, whether they are improving, and what to do next."
      actions={data && (
        <div className="flex gap-2">
          <select className={inputClass} aria-label="Term" value={termId} onChange={(e) => setTermId(e.target.value)}>
            <option value="">Latest term</option>
            {data.trend.filter((t) => t.term_id).map((t) => <option key={t.term_id!} value={t.term_id!}>{t.label}</option>)}
          </select>
          <a className="rounded-lg bg-brand px-3.5 py-2 text-sm text-white" href={`/api/backend/students/${data.student.id}/progress/report${termId ? `?term_id=${termId}` : ''}`}>Download report</a>
        </div>
      )}>
      <ErrorNote message={error} />
      <Card className="mb-6" title="Student"><div className="max-w-md"><StudentPicker value={picked} onChange={(s) => { setPicked(s); setData(null); setDetail(null); }} /></div></Card>

      {picked && !data && !error && <Loading />}

      {data && (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <h2 className="font-headline text-lg font-semibold">{data.student.name}</h2>
            <span className="text-sm text-ink-muted">{data.student.admission_number} · {data.student.class} {data.student.class_teacher ? `· Class teacher ${data.student.class_teacher}` : ''}</span>
            {data.period && <Badge tone={data.period.provisional ? 'amber' : 'green'}>{data.period.label}{data.period.provisional ? ' · provisional' : ''}</Badge>}
            <a className="text-sm text-brand underline" href={`/academic-history?student_id=${data.student.id}`}>Academic history</a>
          </div>

          <nav className="mb-6 flex flex-wrap gap-2">
            {(['overview', 'subjects', 'assessments', 'attendance', 'support', 'class'] as Tab[]).map((t) => (
              <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => setTab(t)}>
                {{ overview: 'Overview', subjects: 'Subjects', assessments: 'Assessments', attendance: 'Attendance', support: 'Remarks & plans', class: 'Class view' }[t]}
              </Button>
            ))}
          </nav>

          {data.empty && tab !== 'support' && <Empty>No results are recorded for this student yet.</Empty>}

          {!data.empty && tab === 'overview' && o && (
            <div className="space-y-6">
              <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <StatTile label="Overall average" value={o.average ?? '—'} hint={data.explanations.average} />
                <StatTile label="Previous term" value={o.previous_average ?? '—'} />
                <StatTile label="Change" value={points(o.change_points)} tone={o.change_points != null && o.change_points < 0 ? 'red' : 'green'} hint={data.explanations.change} />
                <StatTile label="Subjects passed" value={`${o.subjects_passed} of ${o.subjects_counted}`} hint={`Pass mark ${o.pass_mark}`} />
                <StatTile label="Attendance" value={o.attendance_rate != null ? `${o.attendance_rate}%` : '—'} />
                <StatTile label="Assessments done" value={`${data.completion.completed} of ${data.completion.assigned}`} hint={data.explanations.completion} />
              </div>
              {o.position && <p className="text-sm">Class position: {o.position} of {o.class_size}</p>}

              <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Average by term" description={data.explanations.provisional}>
                  <TrendChart data={data.trend} />
                  <details className="mt-2 text-sm"><summary>Show as a table</summary>
                    <Table columns={['Term', 'Average', 'Class average']}>
                      {data.trend.map((t, i) => <tr key={i}><Cell>{t.label}{t.provisional ? ' (provisional)' : ''}</Cell><Cell>{t.average ?? '—'}</Cell><Cell>{t.class_average ?? '—'}</Cell></tr>)}
                    </Table>
                  </details>
                </Card>
                <Card title="What the records show" description={data.insights_label}>
                  {data.flags.length > 0 && (
                    <ul className="mb-3 space-y-1">
                      {data.flags.map((f, i) => <li key={i} className="text-sm"><Badge tone={f.severity === 'high' ? 'red' : 'amber'}>⚑ {f.severity}</Badge> <span className="ml-1">{f.text}</span></li>)}
                      {data.flags_total > data.flags.length && <li className="text-xs text-ink-muted">{data.flags_total - data.flags.length} more not shown.</li>}
                    </ul>
                  )}
                  <h3 className="text-sm font-semibold">Strengths</h3>
                  <ul className="mb-3 list-disc pl-5 text-sm">{data.insights.filter((i) => i.category === 'strength').map((i, k) => <li key={k}>{i.text}</li>)}</ul>
                  <h3 className="text-sm font-semibold">Areas for improvement</h3>
                  <ul className="mb-3 list-disc pl-5 text-sm">{data.insights.filter((i) => i.category === 'weakness').map((i, k) => <li key={k}>{i.text}</li>)}</ul>
                  <h3 className="text-sm font-semibold">Trends</h3>
                  <ul className="list-disc pl-5 text-sm">{data.insights.filter((i) => i.category === 'trend').map((i, k) => <li key={k}>{i.text}</li>)}</ul>
                </Card>
              </div>
            </div>
          )}

          {!data.empty && tab === 'subjects' && o && (
            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
              <Card title="Subjects">
                <div className="mb-3 flex flex-wrap gap-2 text-xs">
                  {(['subject', 'high', 'low', 'improved', 'declined'] as const).map((s) => (
                    <Button key={s} variant={sort === s ? 'primary' : 'secondary'} onClick={() => setSort(s)}>{{ subject: 'A–Z', high: 'Highest', low: 'Lowest', improved: 'Most improved', declined: 'Most declined' }[s]}</Button>
                  ))}
                </div>
                <Table columns={['Subject', 'CA', 'Exam', 'Total', 'Grade', 'Class avg', 'Gap', 'Last term', 'Change']}>
                  {sorted.map((s) => (
                    <tr key={s.subject} className="cursor-pointer hover:bg-surface-sunken" onClick={async () => s.subject_id && setDetail(await fetchApi(`/students/${data.student.id}/progress/subjects/${s.subject_id}`))}>
                      <Cell>{s.subject}</Cell><Cell>{s.ca ?? '—'}</Cell><Cell>{s.exam ?? '—'}</Cell>
                      <Cell>{s.state === 'scored' ? s.total : s.state === 'absent' ? 'ABS' : '—'}{s.total != null && s.total < o.pass_mark && ' ⚠'}</Cell>
                      <Cell>{s.grade ?? '—'}</Cell><Cell>{s.class_average ?? '—'}</Cell><Cell>{s.gap == null ? '—' : points(s.gap)}</Cell>
                      <Cell>{s.previous_total ?? '—'}</Cell><Cell>{points(s.change)}</Cell>
                    </tr>
                  ))}
                </Table>
                <div className="mt-6"><SubjectBars rows={sorted} passMark={o.pass_mark} /></div>
              </Card>
              <Card title={detail ? detail.subject.name : 'Subject detail'} description="Choose a subject in the table.">
                {detail && (
                  <>
                    <Table columns={['Term', 'Total', 'Grade']}>{detail.history.map((h, i) => <tr key={i}><Cell>{h.label}</Cell><Cell>{h.total}</Cell><Cell>{h.grade}</Cell></tr>)}</Table>
                    <h3 className="mb-2 mt-4 text-sm font-semibold">Topics (from CBT questions)</h3>
                    {detail.topics.length === 0 ? <p className="text-sm text-ink-muted">No topic-tagged questions answered yet.</p> : (
                      <ul className="space-y-1 text-sm">
                        {detail.topics.map((t) => (
                          <li key={t.topic}>
                            <Badge tone={TOPIC_BADGE[t.category].tone}>{TOPIC_BADGE[t.category].text}</Badge> {t.topic}
                            <span className="text-ink-muted"> · {t.percentage != null ? `${t.percentage}% over ` : ''}{t.questions} question(s)</span>
                            {t.suggested_materials.length > 0 && <span className="block text-xs">Revise with: {t.suggested_materials.map((m) => m.title).join(', ')}</span>}
                          </li>
                        ))}
                      </ul>
                    )}
                    <p className="mt-2 text-xs text-ink-muted">{data.explanations.topics}</p>
                  </>
                )}
              </Card>
            </div>
          )}

          {tab === 'assessments' && (
            <Card title="Assessment history" description="Original marks always shown beside the percentage.">
              <p className="mb-3 text-sm">Assigned {data.completion.assigned} · completed {data.completion.completed} · missed {data.completion.missed} · pending {data.completion.pending} · awaiting grading {data.completion.awaiting_grading}</p>
              {!assess ? <Loading /> : assess.history.length === 0 ? <Empty>No CBT tests or graded assignments yet.</Empty> : (
                <Table columns={['Date', 'Assessment', 'Subject', 'Type', 'Score', '%']}>
                  {assess.history.map((a, i) => (
                    <tr key={i}><Cell>{a.date}</Cell><Cell>{a.title}</Cell><Cell>{a.subject}</Cell><Cell>{a.kind === 'cbt' ? 'CBT' : 'Assignment'}</Cell>
                      <Cell>{a.awaiting_grading ? 'Awaiting grading' : a.score != null ? `${a.score} / ${a.total}` : '—'}</Cell><Cell>{a.percentage ?? '—'}</Cell></tr>
                  ))}
                </Table>
              )}
            </Card>
          )}

          {tab === 'attendance' && (
            !att ? <Loading /> : (
              <Card title="Attendance" description={att.note}>
                <p className="mb-3 text-sm">Days {att.totals.days} · present {att.totals.present} · absent {att.totals.absent} · late {att.totals.late} · rate {att.totals.rate ?? '—'}%</p>
                <Table columns={['Month', 'Present', 'Absent', 'Late', 'Rate']}>
                  {att.monthly.map((m) => <tr key={m.month}><Cell>{m.month}</Cell><Cell>{m.present}</Cell><Cell>{m.absent}</Cell><Cell>{m.late}</Cell><Cell>{m.rate ?? '—'}%</Cell></tr>)}
                </Table>
              </Card>
            )
          )}

          {tab === 'support' && (
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Teacher remarks" description="Teacher observations, kept apart from automated insights.">
                <div className="mb-4 space-y-2">
                  <textarea className={inputClass} rows={3} value={remark.body} onChange={(e) => setRemark({ ...remark, body: e.target.value })} placeholder="What have you observed?" />
                  <div className="flex gap-2">
                    <select className={inputClass} value={remark.visibility} onChange={(e) => setRemark({ ...remark, visibility: e.target.value })}>
                      <option value="staff_only">Staff only</option><option value="student_visible">Student can see</option>
                    </select>
                    <Button disabled={busy || remark.body.length < 3} onClick={async () => { if (await post(`/students/${data.student.id}/remarks`, remark)) setRemark({ body: '', visibility: 'staff_only' }); }}>Add</Button>
                  </div>
                </div>
                {data.remarks.length === 0 ? <Empty>No remarks yet.</Empty> : (
                  <ul className="space-y-2 text-sm">{data.remarks.map((r) => (
                    <li key={r.id} className="rounded-lg p-2 ring-1 ring-line-soft"><p>{r.body}</p><p className="text-xs text-ink-muted">{r.teacher} · {r.created_at}{r.subject ? ` · ${r.subject}` : ''} · {r.visibility === 'student_visible' ? 'student can see' : 'staff only'}</p></li>
                  ))}</ul>
                )}
              </Card>
              <Card title="Support plans" description="A measurable target, an action, and reviews until there is an outcome.">
                <div className="mb-4 grid gap-2">
                  <Field label="Issue"><input className={inputClass} value={plan.issue} onChange={(e) => setPlan({ ...plan, issue: e.target.value })} placeholder="e.g. Low performance in algebra" /></Field>
                  <div className="grid grid-cols-2 gap-2">
                    <Field label="Baseline"><input className={inputClass} value={plan.baseline} onChange={(e) => setPlan({ ...plan, baseline: e.target.value })} placeholder="48%" /></Field>
                    <Field label="Target"><input className={inputClass} value={plan.target} onChange={(e) => setPlan({ ...plan, target: e.target.value })} placeholder="65% next test" /></Field>
                  </div>
                  <Field label="Action"><input className={inputClass} value={plan.action} onChange={(e) => setPlan({ ...plan, action: e.target.value })} /></Field>
                  <Field label="Review by"><input type="date" className={inputClass} value={plan.review_date} onChange={(e) => setPlan({ ...plan, review_date: e.target.value })} /></Field>
                  <Button disabled={busy || !plan.issue || !plan.baseline || !plan.target || !plan.action || !plan.review_date}
                    onClick={async () => { if (await post(`/students/${data.student.id}/interventions`, { ...plan, start_date: new Date().toISOString().slice(0, 10) })) setPlan({ issue: '', baseline: '', target: '', action: '', review_date: '' }); }}>Create plan</Button>
                </div>
                {data.interventions.map((p) => (
                  <div key={p.id} className="mb-3 rounded-lg p-3 text-sm ring-1 ring-line-soft">
                    <p className="font-semibold">{p.issue} <Badge tone={p.status === 'completed' ? 'green' : p.review_due ? 'red' : 'amber'}>{p.review_due ? '! review due' : p.status}</Badge></p>
                    <p>Baseline {p.baseline} → target {p.target}. {p.action}</p>
                    <p className="text-xs text-ink-muted">{p.responsible_teacher} · review {p.review_date}{p.outcome ? ` · outcome: ${p.outcome}` : ''}</p>
                    {p.status !== 'completed' && p.status !== 'cancelled' && (
                      <div className="mt-2 flex gap-2">
                        <Button variant="secondary" disabled={busy} onClick={() => { const progress = window.prompt('Progress since the last review?'); if (progress) post(`/interventions/${p.id}/reviews`, { progress }); }}>Record review</Button>
                        <Button variant="secondary" disabled={busy} onClick={() => { const outcome = window.prompt('Outcome against the target?'); if (outcome) post(`/interventions/${p.id}`, { status: 'completed', outcome }, 'PATCH'); }}>Complete</Button>
                      </div>
                    )}
                  </div>
                ))}
              </Card>
            </div>
          )}

          {tab === 'class' && (
            !cls ? <Loading /> : (
              <div className="space-y-6">
                {cls.provisional && <p className="text-sm text-caution">Provisional: this term is not yet approved.</p>}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                  <StatTile label="Class average" value={cls.class_stats.class_average ?? '—'} />
                  <StatTile label="Passed" value={cls.class_stats.pass_count ?? 0} tone="green" />
                  <StatTile label="Below pass mark" value={cls.class_stats.fail_count ?? 0} tone="red" />
                  <StatTile label="Flagged for review" value={cls.students.filter((s) => s.flags.length).length} tone="amber" />
                </div>
                <Card title="Students by subject">
                  <Table columns={['Student', ...cls.subjects.map((s) => s.name), 'Average', 'Change', 'Flags']}>
                    {cls.students.map((s) => (
                      <tr key={s.id}>
                        <Cell>{s.name}</Cell>
                        {cls.subjects.map((sub) => <Cell key={sub.id} className={(s.scores[sub.id] ?? 100) < 40 ? 'bg-caution-soft' : ''}>{s.scores[sub.id] ?? '—'}</Cell>)}
                        <Cell>{s.average ?? '—'}</Cell><Cell>{points(s.change)}</Cell>
                        <Cell>{s.flags.length ? <span className="text-negative">⚑ {s.flags.join('; ')}</span> : '—'}</Cell>
                      </tr>
                    ))}
                  </Table>
                </Card>
                <Card title="Subjects" description="A large CA-over-exam gap can point to generous CA marks.">
                  <Table columns={['Subject', 'Average', 'Pass rate', 'CA vs exam gap']}>
                    {cls.subject_stats.map((s) => <tr key={s.subject}><Cell>{s.subject}</Cell><Cell>{s.average ?? '—'}</Cell><Cell>{s.pass_rate != null ? `${s.pass_rate}%` : '—'}</Cell><Cell>{s.ca_exam_gap == null ? '—' : points(s.ca_exam_gap)}</Cell></tr>)}
                  </Table>
                </Card>
              </div>
            )
          )}
        </>
      )}
    </PageShell>
  );
}
