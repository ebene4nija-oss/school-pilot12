'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * The broadsheet: a class's results for a term, their review and the record
 * of what was approved.
 *
 * Every figure comes from the API's central calculator — this page computes
 * nothing. Buttons appear only for the person whose step it is, but that is
 * courtesy: the API refuses an action from anyone else.
 */

type Tab = 'overview' | 'class' | 'subject' | 'validation' | 'analytics' | 'history';

interface Option { id: number; name: string; is_current?: boolean }

interface Part { key: string; name: string; max: number; kind: string }
interface Result {
  state: 'scored' | 'absent' | 'exempt' | 'not_entered' | 'not_offered';
  components: Record<string, number> | null;
  total: number | null;
  grade: string | null;
  position: number | null;
}
interface StudentRow {
  id: number; name: string; admission_number: string | null; gender: string | null;
  results: Record<string, Result>;
  total: number | null; average: number | null; grade: string | null; position: number | null;
  complete: boolean; partial_term: boolean;
  attendance?: { rate: number | null } | null;
}
interface Subject { id: number; name: string; code: string | null; department: string | null; submission_status?: string }
interface Step { role: string; label: string; index: number; state: 'done' | 'current' | 'waiting'; holders: string[] }
interface Summary {
  id: number; reference: string; status: string; current_step: number; current_version: number;
  report_cards_stale: boolean; steps: Step[]; subjects: Subject[];
  can_act: boolean; can_publish: boolean; can_decide_corrections: boolean; pending_corrections: number;
}
interface Calc {
  scheme: { name: string; parts: Part[] };
  config: { ranking_basis: string; numeric_averages: boolean; pass_mark: number };
  subjects: Subject[];
  students: StudentRow[];
  class_stats: { students: number; class_average: number | null; highest_average: number | null; lowest_average: number | null; pass_count: number; fail_count: number; incomplete: number; grade_distribution: { grade: string; count: number }[] };
}
interface ClassView { broadsheet: Summary; calculation: Calc; validation: { errors: number; warnings: number; notices: number; unresolved_warnings: number }; is_provisional: boolean }
interface Issue { severity: 'error' | 'warning' | 'notice'; code: string; key: string; message: string; overridden: boolean; override_reason: string | null }
interface SubjectView {
  broadsheet_id: number;
  status: string;
  scheme: { parts: Part[] };
  submission: { status: string; returned_student_ids: number[] | null };
  can_submit: boolean;
  students: { id: number; name: string; admission_number: string | null; result: Result; editable: boolean }[];
  stats: { average: number | null; pass_rate: number | null; missing: number } | null;
}
interface SubjectStat { subject_id: number; subject: string; average: number | null; highest: number | null; lowest: number | null; pass_rate: number | null }
interface Analytics {
  is_provisional: boolean;
  class_stats: Calc['class_stats'] & { pass_mark: number };
  subject_stats: SubjectStat[];
  comparison: null | {
    term: { name: string };
    note: string | null;
    subjects: { subject_id: number; subject: string; average: number | null; previous_average: number | null; change_points: number | null }[];
  };
}
interface History {
  events: { id: number; created_at: string; action: string; step_role: string | null; department: string | null; user_name: string | null; reason: string | null }[];
  versions: { version: number; approved_at: string | null; approved_by: string | null; intact: boolean; superseded_reason: string | null }[];
}
interface Correction { id: number; student_name: string; subject_name: string; reason: string; requested_by_name: string; status: string }
interface DashRow { class_id: number; class: string; status: string; current_step: string | null; students: number; completed: number; pending: number; subjects_submitted: number; subjects_total: number; class_average: number | null }

const STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'slate' | 'blue'> = {
  published: 'green', approved: 'blue', in_review: 'amber', returned: 'red', rejected: 'red', draft: 'slate', not_started: 'slate',
};

const STATUS_LABEL: Record<string, string> = {
  published: 'Published', approved: 'Approved', in_review: 'Under review', returned: 'Returned', rejected: 'Rejected', draft: 'Draft', not_started: 'Not started',
};

function ordinal(n: number | null): string {
  if (!n) return '—';
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

function shown(r: Result | undefined): string {
  if (!r) return '';
  if (r.state === 'scored') return String(r.total);
  return { absent: 'ABS', exempt: 'EX', not_entered: '—', not_offered: '' }[r.state] ?? '';
}

export default function BroadsheetPage() {
  const [tab, setTab] = useState<Tab>('overview');
  const [terms, setTerms] = useState<Option[]>([]);
  const [classes, setClasses] = useState<Option[]>([]);
  const [termId, setTermId] = useState('');
  const [classId, setClassId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  // The class's own subjects (its arms' streams included), not the school's whole list.
  const [subjectOptions, setSubjectOptions] = useState<{ classId: string; options: Option[] }>({ classId: '', options: [] });

  const [dash, setDash] = useState<{ summary: Record<string, number | null>; classes: DashRow[] } | null>(null);
  const [view, setView] = useState<ClassView | null>(null);
  const [issues, setIssues] = useState<Issue[]>([]);
  const [subjectView, setSubjectView] = useState<SubjectView | null>(null);
  const [analytics, setAnalytics] = useState<Analytics | null>(null);
  const [history, setHistory] = useState<History | null>(null);
  const [corrections, setCorrections] = useState<Correction[]>([]);

  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [reason, setReason] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const [t, c] = await Promise.all([
          fetchApi<{ data?: Option[] }>('/terms'),
          fetchApi<{ data?: Option[] }>('/classes'),
        ]);
        const termList = t.data ?? [];
        setTerms(termList);
        setClasses(c.data ?? []);
        const current = termList.find((x) => x.is_current) ?? termList[0];
        if (current) setTermId(String(current.id));
        if (c.data?.[0]) setClassId(String(c.data[0].id));
      } catch (err) {
        setError(errorText(err, 'Could not load terms and classes.'));
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const loadDash = useCallback(async () => {
    if (!termId) return;
    setDash(await fetchApi(`/broadsheets/dashboard?term_id=${termId}`));
  }, [termId]);

  const loadClass = useCallback(async (): Promise<ClassView | null> => {
    if (!termId || !classId) return null;
    const v = await fetchApi<ClassView>(`/broadsheets/class?term_id=${termId}&class_id=${classId}`);
    setView(v);
    if (!subjectId && v.calculation.subjects[0]) setSubjectId(String(v.calculation.subjects[0].id));
    const val = await fetchApi<{ issues: Issue[] }>(`/broadsheets/${v.broadsheet.id}/validation`);
    setIssues(val.issues);
    return v;
  }, [termId, classId, subjectId]);

  const loadSubject = useCallback(async () => {
      let sid = subjectId;
      let options = subjectOptions.options;
      if (subjectOptions.classId !== classId) {
        options = (await fetchApi<{ data?: Option[] }>(`/subjects?class_id=${classId}`).catch(() => ({ data: [] as Option[] }))).data ?? [];
        setSubjectOptions({ classId, options });
      }
      if (!sid && options[0]) {
        sid = String(options[0].id);
        setSubjectId(sid);
      }
      if (sid) setSubjectView(await fetchApi<SubjectView>(`/broadsheets/subject?term_id=${termId}&class_id=${classId}&subject_id=${sid}`));
  }, [subjectId, subjectOptions, termId, classId]);

  useEffect(() => {
    let cancelled = false;

    const loadTab = async () => {
      if (tab === 'overview') await loadDash();
      if (tab === 'class' || tab === 'validation') await loadClass();
      // A subject teacher may not open the class sheet, so the subject tab
      // stands on its own and only needs the subject list.
      if (tab === 'subject') await loadSubject();
      if (tab === 'analytics') {
        const a = await fetchApi<Analytics>(`/broadsheets/analytics?term_id=${termId}&class_id=${classId}`);
        if (!cancelled) setAnalytics(a);
      }
      if (tab === 'history') {
        const v = await loadClass();
        if (!v || cancelled) return;
        const [h, c] = await Promise.all([
          fetchApi<History>(`/broadsheets/${v.broadsheet.id}/history`),
          fetchApi<{ data: Correction[] }>(`/broadsheets/${v.broadsheet.id}/corrections`),
        ]);
        if (!cancelled) {
          setHistory(h);
          setCorrections(c.data);
        }
      }
    };

    (async () => {
      try {
        await loadTab();
      } catch (err) {
        if (!cancelled) setError(errorText(err, 'Could not load the broadsheet.'));
      }
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, termId, classId, subjectId]);

  async function act(path: string, body: unknown, done: string) {
    setBusy(true);
    setNotice(null);
    setError(null);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body ?? {}) });
      setNotice(res.message ?? done);
      setReason('');
      await loadClass();
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  }

  const bs = view?.broadsheet;
  const calc = view?.calculation;
  const ranked = calc?.config.ranking_basis !== 'none';

  const termName = useMemo(() => terms.find((t) => String(t.id) === termId)?.name ?? '', [terms, termId]);

  if (loading) return <PageShell title="Broadsheet"><Loading /></PageShell>;

  return (
    <PageShell
      title="Broadsheet"
      subtitle="Consolidated class results, their review and the approved record."
      actions={
        <div className="flex flex-wrap gap-2">
          <select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)} aria-label="Term">
            {terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
          {tab !== 'overview' && (
            <select className={inputClass} value={classId} onChange={(e) => { setClassId(e.target.value); setSubjectId(''); }} aria-label="Class">
              {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          )}
        </div>
      }
    >
      <ErrorNote message={error} />
      {notice && <p className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <nav className="mb-6 flex flex-wrap gap-2" aria-label="Broadsheet sections">
        {(['overview', 'class', 'subject', 'validation', 'analytics', 'history'] as Tab[]).map((t) => (
          <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => setTab(t)}>
            {{ overview: 'Overview', class: 'Class broadsheet', subject: 'Subject sheet', validation: 'Validation', analytics: 'Analytics', history: 'History & exports' }[t]}
          </Button>
        ))}
      </nav>

      {tab === 'overview' && (
        dash ? (
          <>
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
              <StatTile label="Students" value={dash.summary.students ?? 0} />
              <StatTile label="Results complete" value={dash.summary.completed ?? 0} tone="green" />
              <StatTile label="Results pending" value={dash.summary.pending ?? 0} tone="amber" />
              <StatTile label="Published" value={dash.summary.published ?? 0} tone="blue" />
              <StatTile label="Class average" value={dash.summary.class_average ?? '—'} />
              <StatTile label="Highest average" value={dash.summary.highest_average ?? '—'} tone="green" />
              <StatTile label="Lowest average" value={dash.summary.lowest_average ?? '—'} tone="red" />
              <StatTile label="Approved" value={dash.summary.approved ?? 0} tone="blue" />
            </div>
            <Card title={`Result processing — ${termName}`}>
              {dash.classes.length === 0 ? <Empty>No classes you can review for this term.</Empty> : (
                <Table columns={['Class', 'Students', 'Complete', 'Pending', 'Subjects in', 'Status', '']}>
                  {dash.classes.map((r) => (
                    <tr key={r.class_id}>
                      <Cell>{r.class}</Cell>
                      <Cell>{r.students}</Cell>
                      <Cell>{r.completed}</Cell>
                      <Cell>{r.pending}</Cell>
                      <Cell>{r.subjects_submitted}/{r.subjects_total}</Cell>
                      <Cell>
                        <Badge tone={STATUS_TONE[r.status]}>{STATUS_LABEL[r.status] ?? r.status}</Badge>
                        {r.current_step && r.status === 'in_review' && <span className="ml-2 text-xs text-ink-muted">at {r.current_step}</span>}
                      </Cell>
                      <Cell><Button variant="secondary" onClick={() => { setClassId(String(r.class_id)); setTab('class'); }}>Open</Button></Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          </>
        ) : <Loading />
      )}

      {tab === 'class' && (
        !view || !bs || !calc ? <Loading /> : (
          <>
            <Card className="mb-6" title={`${bs.reference} · version ${bs.current_version || '—'}`}
              description={view.is_provisional ? 'Provisional: these figures are not yet approved.' : 'Approved record. Changes go through a correction.'}>
              <ol className="flex flex-wrap gap-2" aria-label="Approval steps">
                {bs.steps.map((s) => (
                  <li key={s.index} className={`rounded-lg px-3 py-2 text-xs ring-1 ${s.state === 'current' ? 'bg-caution-soft ring-accent' : s.state === 'done' ? 'bg-positive-soft ring-positive/30' : 'ring-line-soft'}`}>
                    <span className="font-semibold">{s.state === 'done' ? '✓ ' : s.state === 'current' ? '● ' : '○ '}{s.label}</span>
                    {s.holders.length > 0 && <span className="block text-ink-muted">{s.holders.join(', ')}</span>}
                  </li>
                ))}
              </ol>
              <div className="mt-4 flex flex-wrap items-center gap-2">
                <Badge tone={STATUS_TONE[bs.status]}>{STATUS_LABEL[bs.status] ?? bs.status}</Badge>
                <span className="text-sm text-ink-muted">
                  {view.validation.errors} error(s) · {view.validation.unresolved_warnings} unresolved warning(s) · {view.validation.notices} notice(s)
                </span>
                {bs.report_cards_stale && <Badge tone="amber">Report cards need regenerating after a correction</Badge>}
              </div>

              {(bs.can_act || bs.can_publish) && (
                <div className="mt-4 grid gap-3 md:grid-cols-[1fr_auto]">
                  <Field label="Note or reason (required to return or reject)">
                    <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
                  </Field>
                  <div className="flex flex-wrap items-end gap-2">
                    {bs.can_act && (
                      <>
                        <Button variant="accent" disabled={busy} onClick={() => act(`/broadsheets/${bs.id}/approve`, { note: reason || null }, 'Approved.')}>
                          {bs.steps[bs.current_step]?.role === 'class_teacher' ? 'Compile & approve' : 'Approve step'}
                        </Button>
                        <Button variant="secondary" disabled={busy || reason.length < 3} onClick={() => act(`/broadsheets/${bs.id}/return`, {
                          reason,
                          items: bs.subjects.map((s) => ({ subject_id: s.id })),
                        }, 'Returned.')}>Return all subjects</Button>
                        <Button variant="danger" disabled={busy || reason.length < 3} onClick={() => act(`/broadsheets/${bs.id}/reject`, { reason }, 'Rejected.')}>Reject</Button>
                      </>
                    )}
                    {bs.can_publish && (
                      <Button variant="accent" disabled={busy} onClick={() => act(`/broadsheets/${bs.id}/publish`, {}, 'Published.')}>Publish to parents</Button>
                    )}
                  </div>
                </div>
              )}
            </Card>

            <Card title={`Class broadsheet — ${calc.scheme.name}`}>
              {calc.students.length === 0 ? <Empty>No students are enrolled in this class for the term.</Empty> : (
                <Table columns={['#', 'Adm. No.', 'Student', ...calc.subjects.map((s) => s.code || s.name), ...(calc.config.numeric_averages ? ['Total', 'Average'] : []), 'Grade', ...(ranked ? ['Position'] : []), 'Attendance']}>
                  {calc.students.map((s, i) => (
                    <tr key={s.id}>
                      <Cell>{i + 1}</Cell>
                      <Cell>{s.admission_number}</Cell>
                      <Cell>
                        {s.name}
                        {!s.complete && <span className="ml-1 text-xs text-caution" title="Some results not entered">●</span>}
                        {s.partial_term && <span className="ml-1 text-xs text-info" title="Joined or left during the term">◐</span>}
                      </Cell>
                      {calc.subjects.map((sub) => {
                        const r = s.results[sub.id];
                        return (
                          <Cell key={sub.id} className={r?.state === 'not_entered' ? 'bg-caution-soft' : ''}>
                            {shown(r)}{r?.grade && <span className="ml-1 text-xs text-ink-muted">{r.grade}</span>}
                          </Cell>
                        );
                      })}
                      {calc.config.numeric_averages && <><Cell>{s.total ?? '—'}</Cell><Cell>{s.average?.toFixed(2) ?? '—'}</Cell></>}
                      <Cell>{s.grade ?? '—'}</Cell>
                      {ranked && <Cell>{ordinal(s.position)}</Cell>}
                      <Cell>{s.attendance?.rate != null ? `${s.attendance.rate}%` : '—'}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
              <p className="mt-3 text-xs text-ink-muted">— not entered (never counted as zero) · ABS absent · EX exempt · ● incomplete · ◐ part of the term</p>
            </Card>
          </>
        )
      )}

      {tab === 'subject' && (
        <Card title="Subject sheet" description="Each subject teacher hands in their subject; the marks then lock for review.">
          <div className="mb-4 max-w-xs">
            <Field label="Subject">
              <select className={inputClass} value={subjectId} onChange={(e) => setSubjectId(e.target.value)}>
                {(view?.calculation.subjects ?? subjectOptions.options).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </Field>
          </div>
          {!subjectView ? <Loading /> : (
            <>
              <div className="mb-4 flex flex-wrap items-center gap-3">
                <Badge tone={subjectView.submission.status === 'submitted' ? 'green' : subjectView.submission.status === 'returned' ? 'red' : 'slate'}>
                  {subjectView.submission.status === 'submitted' ? 'Handed in' : subjectView.submission.status === 'returned' ? 'Returned for correction' : 'Not handed in'}
                </Badge>
                {subjectView.stats && <span className="text-sm text-ink-muted">Average {subjectView.stats.average ?? '—'} · pass rate {subjectView.stats.pass_rate ?? '—'}% · {subjectView.stats.missing} missing</span>}
                {subjectView.can_submit && (
                  <Button variant="accent" disabled={busy} onClick={async () => {
                    await act(`/broadsheets/${subjectView.broadsheet_id}/subjects/${subjectId}/submit`, {}, 'Handed in.');
                    setSubjectView(await fetchApi<SubjectView>(`/broadsheets/subject?term_id=${termId}&class_id=${classId}&subject_id=${subjectId}`));
                  }}>Hand in this subject</Button>
                )}
              </div>
              <Table columns={['Adm. No.', 'Student', ...subjectView.scheme.parts.map((p: Part) => `${p.name} (${p.max})`), 'Total', 'Grade', 'Position', 'Editable']}>
                {subjectView.students.map((s) => (
                  <tr key={s.id}>
                    <Cell>{s.admission_number}</Cell>
                    <Cell>{s.name}</Cell>
                    {subjectView.scheme.parts.map((p: Part) => <Cell key={p.key}>{s.result.state === 'scored' ? s.result.components?.[p.key] ?? '' : ''}</Cell>)}
                    <Cell>{shown(s.result)}</Cell>
                    <Cell>{s.result.grade ?? '—'}</Cell>
                    <Cell>{ordinal(s.result.position)}</Cell>
                    <Cell>{s.editable ? 'Yes' : 'Locked'}</Cell>
                  </tr>
                ))}
              </Table>
            </>
          )}
        </Card>
      )}

      {tab === 'validation' && (
        <Card title="Validation" description="Errors block approval and cannot be overridden. Warnings need an override with a reason before final approval.">
          {issues.length === 0 ? <Empty>No issues. This broadsheet is ready for review.</Empty> : (
            <ul className="space-y-2">
              {issues.map((i) => (
                <li key={`${i.code}-${i.key}`} className="flex flex-wrap items-start justify-between gap-3 rounded-lg px-3 py-2 ring-1 ring-line-soft">
                  <div>
                    <Badge tone={i.severity === 'error' ? 'red' : i.severity === 'warning' ? 'amber' : 'slate'}>
                      {i.severity === 'error' ? '✕ Error' : i.severity === 'warning' ? '! Warning' : 'i Notice'}
                    </Badge>
                    <span className="ml-2 text-sm">{i.message}</span>
                    {i.overridden && <p className="mt-1 text-xs text-ink-muted">Accepted: {i.override_reason}</p>}
                  </div>
                  {i.severity === 'warning' && !i.overridden && bs && (bs.can_act || bs.can_decide_corrections) && (
                    <Button variant="secondary" disabled={busy} onClick={() => {
                      const why = window.prompt('Why is it right to accept this? (recorded)');
                      if (why && why.trim().length >= 5) act(`/broadsheets/${bs.id}/overrides`, { code: i.code, key: i.key, reason: why }, 'Accepted.');
                    }}>Accept with reason</Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'analytics' && (
        !analytics ? <Loading /> : (
          <>
            {analytics.is_provisional && <p className="mb-4 text-sm text-caution">Provisional figures: this term is not yet approved.</p>}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
              <StatTile label="Class average" value={analytics.class_stats.class_average ?? '—'} />
              <StatTile label="Passed" value={analytics.class_stats.pass_count} tone="green" hint={`Pass mark ${analytics.class_stats.pass_mark}`} />
              <StatTile label="Below pass mark" value={analytics.class_stats.fail_count} tone="red" />
              <StatTile label="Incomplete" value={analytics.class_stats.incomplete} tone="amber" />
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Subjects">
                <Table columns={['Subject', 'Average', 'Highest', 'Lowest', 'Pass rate']}>
                  {analytics.subject_stats.map((s) => (
                    <tr key={s.subject_id}><Cell>{s.subject}</Cell><Cell>{s.average ?? '—'}</Cell><Cell>{s.highest ?? '—'}</Cell><Cell>{s.lowest ?? '—'}</Cell><Cell>{s.pass_rate != null ? `${s.pass_rate}%` : '—'}</Cell></tr>
                  ))}
                </Table>
              </Card>
              <Card title="Grade distribution">
                <Table columns={['Grade', 'Students']}>
                  {analytics.class_stats.grade_distribution.map((g) => <tr key={g.grade}><Cell>{g.grade}</Cell><Cell>{g.count}</Cell></tr>)}
                </Table>
              </Card>
            </div>
            {analytics.comparison && (
              <Card className="mt-6" title={`Compared with ${analytics.comparison.term.name}`} description={analytics.comparison.note ?? 'Changes are in percentage points.'}>
                <Table columns={['Subject', 'Then', 'Now', 'Change (points)']}>
                  {analytics.comparison.subjects.map((s) => (
                    <tr key={s.subject_id}><Cell>{s.subject}</Cell><Cell>{s.previous_average ?? '—'}</Cell><Cell>{s.average ?? '—'}</Cell>
                      <Cell>{s.change_points == null ? '—' : `${s.change_points > 0 ? '▲ +' : s.change_points < 0 ? '▼ ' : ''}${s.change_points}`}</Cell></tr>
                  ))}
                </Table>
              </Card>
            )}
          </>
        )
      )}

      {tab === 'history' && (
        !history || !bs ? <Loading /> : (
          <div className="grid gap-6">
            <Card title="Exports" description="Exports come only from an approved version, and every download is recorded.">
              {bs.current_version === 0 ? <Empty>Nothing to export until the broadsheet is approved.</Empty> : (
                <div className="flex flex-wrap gap-2">
                  <a className="rounded-lg bg-brand px-3.5 py-2 text-sm font-medium text-white" href={`/api/backend/broadsheets/${bs.id}/export?format=pdf`}>PDF (landscape)</a>
                  <a className="rounded-lg border border-line px-3.5 py-2 text-sm" href={`/api/backend/broadsheets/${bs.id}/export?format=pdf&paper=a3`}>PDF on A3</a>
                  <a className="rounded-lg border border-line px-3.5 py-2 text-sm" href={`/api/backend/broadsheets/${bs.id}/export?format=csv`}>Excel (CSV)</a>
                </div>
              )}
            </Card>

            <Card title="Corrections" description="A correction changes nothing until approved; approval creates a new version and keeps the old one.">
              {corrections.length === 0 ? <Empty>No corrections.</Empty> : (
                <Table columns={['Student', 'Subject', 'Reason', 'Requested by', 'Status', '']}>
                  {corrections.map((c) => (
                    <tr key={c.id}>
                      <Cell>{c.student_name}</Cell><Cell>{c.subject_name}</Cell><Cell>{c.reason}</Cell><Cell>{c.requested_by_name}</Cell>
                      <Cell><Badge tone={c.status === 'applied' ? 'green' : c.status === 'rejected' ? 'red' : 'amber'}>{c.status}</Badge></Cell>
                      <Cell>
                        {c.status === 'pending' && bs.can_decide_corrections && (
                          <div className="flex gap-2">
                            <Button variant="secondary" disabled={busy} onClick={() => act(`/broadsheet-corrections/${c.id}/decide`, { approve: true }, 'Applied.')}>Approve</Button>
                            <Button variant="danger" disabled={busy} onClick={() => act(`/broadsheet-corrections/${c.id}/decide`, { approve: false }, 'Rejected.')}>Reject</Button>
                          </div>
                        )}
                      </Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <Card title="Versions">
              {history.versions.length === 0 ? <Empty>No approved version yet.</Empty> : (
                <Table columns={['Version', 'Approved', 'By', 'Integrity', 'Superseded']}>
                  {history.versions.map((v) => (
                    <tr key={v.version}>
                      <Cell>v{v.version}</Cell><Cell>{v.approved_at ? new Date(v.approved_at).toLocaleString('en-NG') : '—'}</Cell><Cell>{v.approved_by}</Cell>
                      <Cell>{v.intact ? <Badge tone="green">✓ Intact</Badge> : <Badge tone="red">✕ Altered</Badge>}</Cell>
                      <Cell>{v.superseded_reason ?? '—'}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <Card title="Approval history">
              <Table columns={['When', 'Action', 'Step', 'By', 'Reason']}>
                {history.events.map((e) => (
                  <tr key={e.id}>
                    <Cell>{new Date(e.created_at).toLocaleString('en-NG')}</Cell>
                    <Cell>{e.action.replace(/_/g, ' ')}</Cell>
                    <Cell>{e.step_role ?? '—'}{e.department ? ` (${e.department})` : ''}</Cell>
                    <Cell>{e.user_name ?? '—'}</Cell>
                    <Cell>{e.reason ?? ''}</Cell>
                  </tr>
                ))}
              </Table>
            </Card>
          </div>
        )
      )}
    </PageShell>
  );
}
