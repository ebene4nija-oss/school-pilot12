'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * A student's life at the school, from the admin's side (TASK 03, 04):
 * the end-of-session rollover, one student's status, class and exit, form
 * teachers for each class, and enrolling a class in its compulsory subjects.
 *
 * Rollover is all or nothing: every decision applies, or none does. A
 * leaver's record becomes a read-only archive. Every change needs a reason
 * and is kept in the record's history.
 */

type Tab = 'rollover' | 'student' | 'teachers' | 'subjects';
interface Opt { id: number; name: string; is_current?: boolean; arms?: { id: number; name: string }[] }
interface Row { student_id: number; student_name: string | null; admission_number: string | null; current_class: string | null; current_arm: string | null; session_average: number | null; proposed_action: string; proposed_class_id: number | null; reason: string | null }
interface Decision { action: string; target_class_id: string; destination_school: string }
interface Placement { class_id: number; class_name: string; arm_id: number | null; arm_name: string | null; student_count: number; assignment_id: number | null; form_teacher: { id: number; name: string } | null; assistant_teacher: { id: number; name: string } | null }

const ACTIONS: Record<string, string> = { promote: 'Promote', repeat: 'Repeat the class', graduate: 'Graduate', transfer_out: 'Transferring out', withdraw: 'Withdraw' };

export default function StudentLifecycle() {
  const [tab, setTab] = useState<Tab>('rollover');
  const [classes, setClasses] = useState<Opt[]>([]);
  const [sessions, setSessions] = useState<Opt[]>([]);
  const [terms, setTerms] = useState<Opt[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const done = useCallback((m: string) => { setNotice(m); setError(null); }, []);
  const fail = useCallback((e: unknown, f: string) => { setError(errorText(e, f)); setNotice(null); }, []);

  useEffect(() => {
    fetchApi<{ data: Opt[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: Opt[] }>('/student-directory/sessions').then((r) => setSessions(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: Opt[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined);
  }, []);

  return (
    <PageShell title="Student lifecycle" subtitle="Moving up at session end, leavers, class moves, form teachers and subjects.">
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['rollover', 'Session rollover'], ['student', 'One student'], ['teachers', 'Form teachers'], ['subjects', 'Subject enrolment']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {tab === 'rollover' && <Rollover classes={classes} sessions={sessions} onDone={done} onError={fail} />}
      {tab === 'student' && <OneStudent classes={classes} onDone={done} onError={fail} />}
      {tab === 'teachers' && <FormTeachers sessions={sessions} onDone={done} onError={fail} />}
      {tab === 'subjects' && <Subjects classes={classes} terms={terms} onDone={done} onError={fail} />}
    </PageShell>
  );
}

type H = { onDone: (m: string) => void; onError: (e: unknown, f: string) => void };

function Rollover({ classes, sessions, onDone, onError }: { classes: Opt[]; sessions: Opt[] } & H) {
  const [pick, setPick] = useState({ from_session_id: '', to_session_id: '', class_id: '', pass_mark: '' });
  const [preview, setPreview] = useState<{ students: Row[]; summary: Record<string, number>; pass_mark: number } | null>(null);
  const [decisions, setDecisions] = useState<Record<number, Decision>>({});
  const [result, setResult] = useState<{ skipped: { student_id: number; reason: string }[] } | null>(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    setBusy(true);
    setResult(null);
    try {
      const p = await fetchApi<{ students: Row[]; summary: Record<string, number>; pass_mark: number }>('/students/rollover/preview', { method: 'POST', body: JSON.stringify({ from_session_id: Number(pick.from_session_id), class_id: pick.class_id ? Number(pick.class_id) : undefined, pass_mark: pick.pass_mark ? Number(pick.pass_mark) : undefined }) });
      setPreview(p);
      setDecisions(Object.fromEntries(p.students.map((s) => [s.student_id, { action: s.proposed_action, target_class_id: s.proposed_class_id ? String(s.proposed_class_id) : '', destination_school: '' }])));
    } catch (err) { onError(err, 'Could not prepare the rollover.'); } finally { setBusy(false); }
  }

  async function commit() {
    if (!preview) return;
    const to = sessions.find((s) => String(s.id) === pick.to_session_id)?.name;
    if (!window.confirm(`Apply ${preview.students.length} decision(s) and move these students into ${to}? This is all or nothing.`)) return;
    setBusy(true);
    try {
      const r = await fetchApi<{ message: string; skipped: { student_id: number; reason: string }[] }>('/students/rollover/commit', {
        method: 'POST',
        body: JSON.stringify({
          from_session_id: Number(pick.from_session_id), to_session_id: Number(pick.to_session_id),
          decisions: preview.students.map((s) => {
            const d = decisions[s.student_id];
            return { student_id: s.student_id, action: d.action, target_class_id: ['promote', 'repeat'].includes(d.action) && d.target_class_id ? Number(d.target_class_id) : undefined, destination_school: d.destination_school || undefined };
          }),
        }),
      });
      onDone(r.message);
      setResult(r);
      setPreview(null);
    } catch (err) { onError(err, 'The rollover did not apply. Nothing was changed.'); } finally { setBusy(false); }
  }

  return (
    <div className="space-y-5">
      <Card description="At the end of a session: each student promoted, repeating, graduating or leaving. The proposal follows session averages against the pass mark; change any of it before applying.">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Field label="From session"><select className={inputClass} value={pick.from_session_id} onChange={(e) => setPick({ ...pick, from_session_id: e.target.value })}><option value="">Choose…</option>{sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          <Field label="Into session"><select className={inputClass} value={pick.to_session_id} onChange={(e) => setPick({ ...pick, to_session_id: e.target.value })}><option value="">Choose…</option>{sessions.filter((s) => String(s.id) !== pick.from_session_id).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          <Field label="Class"><select className={inputClass} value={pick.class_id} onChange={(e) => setPick({ ...pick, class_id: e.target.value })}><option value="">All classes</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
          <Field label="Pass mark"><input className={inputClass} type="number" min={0} max={100} placeholder="school's" value={pick.pass_mark} onChange={(e) => setPick({ ...pick, pass_mark: e.target.value })} /></Field>
          <div className="flex items-end"><Button disabled={busy || !pick.from_session_id} onClick={load}>Prepare</Button></div>
        </div>
      </Card>

      {preview && (
        <>
          <div className="grid gap-4 sm:grid-cols-5">
            {Object.entries(preview.summary).map(([k, v]) => <StatTile key={k} label={k.replaceAll('_', ' ')} value={v} tone={k === 'repeat' ? 'amber' : k === 'without_scores' ? 'red' : 'slate'} />)}
          </div>
          <Card title={`Decisions (pass mark ${preview.pass_mark})`}>
            {preview.students.length === 0 ? <Empty>No students in that session’s enrolment.</Empty> : (
              <Table columns={['Student', 'Now', 'Average', 'Decision', 'Into class / to school']}>
                {preview.students.map((s) => {
                  const d = decisions[s.student_id];
                  return (
                    <tr key={s.student_id}>
                      <Cell className="font-medium">{s.student_name}<span className="block text-xs text-ink-muted">{s.admission_number}</span></Cell>
                      <Cell>{[s.current_class, s.current_arm].filter(Boolean).join(' ')}</Cell>
                      <Cell>{s.session_average ?? <span className="text-ink-muted">no scores</span>}</Cell>
                      <Cell>
                        <select className={inputClass} value={d.action} onChange={(e) => setDecisions({ ...decisions, [s.student_id]: { ...d, action: e.target.value } })}>{Object.entries(ACTIONS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
                        {s.reason && <span className="block text-xs text-ink-muted">{s.reason}</span>}
                      </Cell>
                      <Cell>
                        {['promote', 'repeat'].includes(d.action) && <select className={inputClass} value={d.target_class_id} onChange={(e) => setDecisions({ ...decisions, [s.student_id]: { ...d, target_class_id: e.target.value } })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>}
                        {d.action === 'transfer_out' && <input className={inputClass} placeholder="New school" value={d.destination_school} onChange={(e) => setDecisions({ ...decisions, [s.student_id]: { ...d, destination_school: e.target.value } })} />}
                      </Cell>
                    </tr>
                  );
                })}
              </Table>
            )}
            <Button className="mt-4" variant="accent" disabled={busy || !pick.to_session_id || preview.students.length === 0} onClick={commit}>Apply rollover</Button>
            {!pick.to_session_id && <p className="mt-2 text-xs text-ink-muted">Choose the session they move into. Create it first under Sections & schemes if it does not exist.</p>}
          </Card>
        </>
      )}
      {result && result.skipped.length > 0 && (
        <Card title="Not moved"><ul className="list-disc pl-5 text-sm">{result.skipped.map((s) => <li key={s.student_id}>#{s.student_id}: {s.reason}</li>)}</ul></Card>
      )}
    </div>
  );
}

function OneStudent({ classes, onDone, onError }: { classes: Opt[] } & H) {
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [changes, setChanges] = useState<{ field: string; old_value: string | null; new_value: string | null; reason: string | null; created_at: string; changed_by_name: string | null }[] | null>(null);
  const [move, setMove] = useState({ class_id: '', arm_id: '', reason: '' });
  const [exit, setExit] = useState({ action: 'transfer_out', destination_school: '', left_on: '', remarks: '' });
  const [dup, setDup] = useState({ name: '', date_of_birth: '', guardian_phone: '' });
  const [matches, setMatches] = useState<{ student_id: number; name: string; admission_number: string | null; status: string; reasons: string[] }[] | null>(null);
  const [busy, setBusy] = useState(false);

  const loadChanges = useCallback(async (id: number) => {
    try { setChanges((await fetchApi<{ data: typeof changes }>(`/student-directory/${id}/changes`)).data); } catch { setChanges([]); }
  }, []);

  async function act(path: string, body: unknown) {
    if (!student) return;
    setBusy(true);
    try {
      const r = await fetchApi<{ message: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      onDone(r.message);
      await loadChanges(student.id);
    } catch (err) { onError(err, 'That did not go through.'); } finally { setBusy(false); }
  }

  const arms = classes.find((c) => String(c.id) === move.class_id)?.arms ?? [];

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="Find the student">
        <StudentPicker value={student} onChange={(s) => { setStudent(s); setChanges(null); if (s) void loadChanges(s.id); }} />
        {student && (
          <div className="mt-4 space-y-5">
            <div>
              <p className="mb-2 text-sm font-semibold">Status</p>
              <div className="flex flex-wrap gap-2">
                {['active', 'suspended', 'withdrawn'].map((st) => <Button key={st} variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt(`Why set ${student.name} to ${st}?`); if (reason && reason.trim().length >= 3) void act(`/student-directory/${student.id}/status`, { status: st, reason: reason.trim() }); }}>{st}</Button>)}
              </div>
            </div>
            <form className="grid gap-2 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); void act(`/student-directory/${student.id}/move`, { class_id: Number(move.class_id), arm_id: move.arm_id ? Number(move.arm_id) : null, reason: move.reason }); }}>
              <Field label="Move to class"><select className={inputClass} required value={move.class_id} onChange={(e) => setMove({ ...move, class_id: e.target.value, arm_id: '' })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
              <Field label="Arm"><select className={inputClass} value={move.arm_id} disabled={!arms.length} onChange={(e) => setMove({ ...move, arm_id: e.target.value })}><option value="">—</option>{arms.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select></Field>
              <Field label="Reason"><input className={inputClass} required minLength={3} value={move.reason} onChange={(e) => setMove({ ...move, reason: e.target.value })} /></Field>
              <div className="sm:col-span-3"><Button type="submit" variant="secondary" disabled={busy}>Move</Button></div>
            </form>
            <form className="grid gap-2 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); if (window.confirm(`Record ${student.name} as leaving? Their record becomes a read-only archive.`)) void act(`/students/${student.id}/exit`, { ...exit, destination_school: exit.destination_school || null, left_on: exit.left_on || null, remarks: exit.remarks || null }); }}>
              <Field label="Leaving because"><select className={inputClass} value={exit.action} onChange={(e) => setExit({ ...exit, action: e.target.value })}><option value="transfer_out">Transferring to another school</option><option value="withdraw">Withdrawn</option><option value="graduate">Graduated</option></select></Field>
              <Field label="Left on"><input className={inputClass} type="date" value={exit.left_on} onChange={(e) => setExit({ ...exit, left_on: e.target.value })} /></Field>
              {exit.action === 'transfer_out' && <Field label="New school"><input className={inputClass} value={exit.destination_school} onChange={(e) => setExit({ ...exit, destination_school: e.target.value })} /></Field>}
              <Field label="Remarks"><input className={inputClass} value={exit.remarks} onChange={(e) => setExit({ ...exit, remarks: e.target.value })} /></Field>
              <div className="sm:col-span-2"><Button type="submit" variant="danger" disabled={busy}>Record as leaving</Button></div>
            </form>
          </div>
        )}
      </Card>
      <div className="space-y-5">
        <Card title="Record history" description="Every change to class, status, name or date of birth, with who and why.">
          {!student ? <p className="text-sm text-ink-muted">Choose a student.</p> : !changes ? <Loading /> : changes.length === 0 ? <p className="text-sm text-ink-muted">No changes recorded.</p> : (
            <ul className="space-y-2 text-sm">{changes.map((c, i) => <li key={i}><span className="font-medium">{c.field.replaceAll('_', ' ')}</span>: {c.old_value ?? '—'} → {c.new_value ?? '—'}<span className="block text-xs text-ink-muted">{c.created_at?.slice(0, 16).replace('T', ' ')} · {c.changed_by_name}{c.reason ? ` · ${c.reason}` : ''}</span></li>)}</ul>
          )}
        </Card>
        <Card title="Is this child already on the roll?" description="Check before enrolling a new student. Nothing is merged automatically.">
          <form className="grid gap-2 sm:grid-cols-3" onSubmit={async (e) => { e.preventDefault(); try { setMatches((await fetchApi<{ possible_duplicates: NonNullable<typeof matches> }>('/student-directory/duplicate-check', { method: 'POST', body: JSON.stringify({ name: dup.name, date_of_birth: dup.date_of_birth || null, guardian_phone: dup.guardian_phone || null }) })).possible_duplicates); } catch (err) { onError(err, 'Could not check.'); } }}>
            <Field label="Name"><input className={inputClass} required value={dup.name} onChange={(e) => setDup({ ...dup, name: e.target.value })} /></Field>
            <Field label="Date of birth"><input className={inputClass} type="date" value={dup.date_of_birth} onChange={(e) => setDup({ ...dup, date_of_birth: e.target.value })} /></Field>
            <Field label="Parent's phone"><input className={inputClass} inputMode="tel" value={dup.guardian_phone} onChange={(e) => setDup({ ...dup, guardian_phone: e.target.value })} /></Field>
            <div className="sm:col-span-3"><Button type="submit" variant="secondary">Check</Button></div>
          </form>
          {matches && (matches.length === 0 ? <p className="mt-3 text-sm text-positive">No likely match.</p> : (
            <ul className="mt-3 space-y-1 text-sm">{matches.map((m) => <li key={m.student_id}><span className="font-medium">{m.name}</span> · {m.admission_number} · {m.status}<span className="block text-xs text-ink-muted">{m.reasons.join(', ')}</span></li>)}</ul>
          ))}
        </Card>
      </div>
    </div>
  );
}

function FormTeachers({ sessions, onDone, onError }: { sessions: Opt[] } & H) {
  const [sessionId, setSessionId] = useState('');
  const [rows, setRows] = useState<Placement[] | null>(null);
  const [teachers, setTeachers] = useState<{ id: number; name: string }[]>([]);
  const [edit, setEdit] = useState<Record<string, { form: string; assistant: string }>>({});
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const r = await fetchApi<{ session: { id: number }; data: Placement[] }>(`/class-teachers${sessionId ? `?session_id=${sessionId}` : ''}`);
      setRows(r.data);
      if (!sessionId) setSessionId(String(r.session.id));
    } catch (err) { setRows([]); onError(err, 'Could not load form teachers.'); }
  }, [sessionId, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: { id: number; name: string }[] }>('/users?role=teacher&per_page=100&status=active').then((r) => setTeachers(r.data)).catch(() => undefined);
  }, [load]);

  const key = (p: Placement) => `${p.class_id}:${p.arm_id ?? 'none'}`;

  async function save(p: Placement) {
    const e = edit[key(p)];
    if (!e?.form) return;
    setBusy(true);
    try {
      const r = await fetchApi<{ message?: string }>('/class-teachers', { method: 'POST', body: JSON.stringify({ session_id: Number(sessionId), class_id: p.class_id, arm_id: p.arm_id, form_teacher_id: Number(e.form), assistant_teacher_id: e.assistant ? Number(e.assistant) : null }) });
      onDone(r.message ?? 'Saved.');
      await load();
    } catch (err) { onError(err, 'Could not assign.'); } finally { setBusy(false); }
  }

  return (
    <Card description="The form teacher takes the register, writes the termly remark and is the class’s first contact for parents.">
      <div className="mb-4 max-w-xs"><Field label="Session"><select className={inputClass} value={sessionId} onChange={(e) => setSessionId(e.target.value)}>{sessions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field></div>
      {!rows ? <Loading /> : rows.length === 0 ? <Empty>No classes.</Empty> : (
        <Table columns={['Class', 'Students', 'Form teacher', 'Assistant', '']}>
          {rows.map((p) => {
            const e = edit[key(p)] ?? { form: p.form_teacher ? String(p.form_teacher.id) : '', assistant: p.assistant_teacher ? String(p.assistant_teacher.id) : '' };
            return (
              <tr key={key(p)}>
                <Cell className="font-medium">{[p.class_name, p.arm_name].filter(Boolean).join(' ')}</Cell>
                <Cell>{p.student_count}</Cell>
                <Cell><select className={inputClass} value={e.form} onChange={(ev) => setEdit({ ...edit, [key(p)]: { ...e, form: ev.target.value } })}><option value="">None</option>{teachers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Cell>
                <Cell><select className={inputClass} value={e.assistant} onChange={(ev) => setEdit({ ...edit, [key(p)]: { ...e, assistant: ev.target.value } })}><option value="">None</option>{teachers.filter((t) => String(t.id) !== e.form).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Cell>
                <Cell className="space-x-2 whitespace-nowrap">
                  {edit[key(p)] && <Button variant="secondary" disabled={busy || !e.form} onClick={() => save(p)}>Save</Button>}
                  {p.assignment_id && <button type="button" className="text-xs text-negative underline" onClick={async () => { if (!window.confirm('Remove this assignment?')) return; try { await fetchApi(`/class-teachers/${p.assignment_id}`, { method: 'DELETE' }); onDone('Removed.'); await load(); } catch (err) { onError(err, 'Could not remove it.'); } }}>Remove</button>}
                  {!p.form_teacher && !edit[key(p)] && <Badge tone="amber">unassigned</Badge>}
                </Cell>
              </tr>
            );
          })}
        </Table>
      )}
    </Card>
  );
}

function Subjects({ classes, terms, onDone, onError }: { classes: Opt[]; terms: Opt[] } & H) {
  const [pick, setPick] = useState({ class_id: '', term_id: '' });
  const [busy, setBusy] = useState(false);

  async function run() {
    if (!window.confirm('Enrol every student in this class in its compulsory subjects for the term? Existing enrolments are kept.')) return;
    setBusy(true);
    try {
      const r = await fetchApi<{ message?: string }>('/subjects/bulk-auto-enroll', { method: 'POST', body: JSON.stringify({ class_id: Number(pick.class_id), term_id: Number(pick.term_id) }) });
      onDone(r.message ?? 'Enrolled.');
    } catch (err) { onError(err, 'Could not enrol the class.'); } finally { setBusy(false); }
  }

  return (
    <Card title="Enrol a class in its compulsory subjects" description="Electives are chosen per student on the Subjects page. Compulsory subjects are set per class there too.">
      <div className="grid max-w-xl gap-3 sm:grid-cols-3">
        <Field label="Class"><select className={inputClass} value={pick.class_id} onChange={(e) => setPick({ ...pick, class_id: e.target.value })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        <Field label="Term"><select className={inputClass} value={pick.term_id} onChange={(e) => setPick({ ...pick, term_id: e.target.value })}><option value="">Choose…</option>{terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Field>
        <div className="flex items-end"><Button disabled={busy || !pick.class_id || !pick.term_id} onClick={run}>Enrol class</Button></div>
      </div>
    </Card>
  );
}
