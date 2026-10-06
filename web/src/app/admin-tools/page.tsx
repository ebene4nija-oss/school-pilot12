'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * The school admin's remaining tools, each backed by an API that had no
 * screen: the NDPA data export, the school calendar parents see, staff
 * records (leave allowances, employment history, pay and status history),
 * students' achievements, ID card renewals and the verification log, and
 * the school-wide progress picture.
 */

type Tab = 'progress' | 'calendar' | 'staff' | 'achievements' | 'cards' | 'export';
const LEAVE = ['annual', 'sick', 'maternity', 'compassionate', 'study'];
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

export default function AdminTools() {
  const [tab, setTab] = useState<Tab>('progress');
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const done = useCallback((m: string) => { setNotice(m); setError(null); }, []);
  const fail = useCallback((e: unknown, f: string) => { setError(errorText(e, f)); setNotice(null); }, []);

  return (
    <PageShell title="Admin tools" subtitle="Calendar, staff records, achievements, ID card checks, school progress and your data export.">
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['progress', 'School progress'], ['calendar', 'Calendar'], ['staff', 'Staff records'], ['achievements', 'Achievements'], ['cards', 'ID card checks'], ['export', 'Data export']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {tab === 'progress' && <SchoolProgress onError={fail} />}
      {tab === 'calendar' && <Calendar onDone={done} onError={fail} />}
      {tab === 'staff' && <StaffRecords onDone={done} onError={fail} />}
      {tab === 'achievements' && <Achievements onDone={done} onError={fail} />}
      {tab === 'cards' && <CardChecks onError={fail} />}
      {tab === 'export' && <DataExport onDone={done} onError={fail} />}
    </PageShell>
  );
}

type H = { onDone: (m: string) => void; onError: (e: unknown, f: string) => void };

/** Load once per `key`; `reload` fetches again. */
function useLoad<T>(fn: () => Promise<T>, onError: H['onError'], key: string) {
  const [data, setData] = useState<T | null>(null);
  const [tick, setTick] = useState(0);
  const latest = useRef(fn);
  useEffect(() => { latest.current = fn; });
  useEffect(() => {
    let live = true;
    latest.current().then((d) => { if (live) setData(d); }).catch((err) => onError(err, 'Could not load this.'));
    return () => { live = false; };
  }, [key, tick, onError]);
  const reload = useCallback(async () => { setTick((t) => t + 1); }, []);
  return [data, reload] as const;
}

function SchoolProgress({ onError }: Pick<H, 'onError'>) {
  const [terms, setTerms] = useState<{ id: number; name: string }[]>([]);
  const [termId, setTermId] = useState('');
  useEffect(() => { fetchApi<{ data: { id: number; name: string }[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined); }, []);
  type A = { classes: { class_id: number; class: string; students: number; average: number | null; pass_rate: number | null; at_risk: number; provisional: boolean }[]; subjects: { subject: string; entered: number; pass_rate: number | null }[]; top: { name?: string; student?: string; class?: string; average: number }[]; bottom: { name?: string; student?: string; class?: string; average: number }[]; at_risk_total: number; interventions: Record<string, number>; note: string };
  const [a] = useLoad<A>(() => fetchApi<A>(`/analytics/school-progress${termId ? `?term_id=${termId}` : ''}`), onError, termId);

  return (
    <div className="space-y-5">
      <div className="max-w-xs"><Field label="Term"><select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)}><option value="">Current term</option>{terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Field></div>
      {!a ? <Loading /> : (
        <>
          <p className="text-sm text-ink-muted">{a.note}</p>
          <div className="grid gap-4 sm:grid-cols-3">
            <StatTile label="Students flagged for support" value={a.at_risk_total} tone={a.at_risk_total ? 'amber' : 'green'} />
            <StatTile label="Support plans ongoing" value={a.interventions.ongoing ?? 0} tone="blue" />
            <StatTile label="Support plans completed" value={a.interventions.completed ?? 0} tone="green" />
          </div>
          <Card title="Classes">
            <Table columns={['Class', 'Students', 'Average', 'Pass rate', 'Flagged']}>
              {a.classes.map((c) => <tr key={c.class_id}><Cell className="font-medium">{c.class}{c.provisional && <Badge>provisional</Badge>}</Cell><Cell>{c.students}</Cell><Cell>{c.average ?? '—'}</Cell><Cell>{c.pass_rate != null ? `${c.pass_rate}%` : '—'}</Cell><Cell>{c.at_risk ? <Badge tone="amber">{c.at_risk}</Badge> : 0}</Cell></tr>)}
            </Table>
          </Card>
          <div className="grid gap-5 lg:grid-cols-3">
            <Card title="Subjects, weakest first">
              <ul className="space-y-1 text-sm">{a.subjects.map((s) => <li key={s.subject}>{s.subject}: <span className="font-medium">{s.pass_rate ?? '—'}%</span> <span className="text-xs text-ink-muted">of {s.entered}</span></li>)}</ul>
            </Card>
            <Card title="Highest averages"><ol className="list-decimal space-y-1 pl-5 text-sm">{a.top.map((s, i) => <li key={i}>{s.name ?? s.student} <span className="text-ink-muted">· {s.class} · {s.average}</span></li>)}</ol></Card>
            <Card title="Most in need of support"><ol className="list-decimal space-y-1 pl-5 text-sm">{a.bottom.map((s, i) => <li key={i}>{s.name ?? s.student} <span className="text-ink-muted">· {s.class} · {s.average}</span></li>)}</ol></Card>
          </div>
        </>
      )}
    </div>
  );
}

function Calendar({ onDone, onError }: H) {
  type Ev = { id: number; title: string; description: string | null; category: string; start_date: string; end_date: string | null; is_public: boolean };
  const [events, reload] = useLoad<Ev[]>(async () => (await fetchApi<{ data: Ev[] }>('/calendar/events')).data, onError, '');
  const [f, setF] = useState({ title: '', category: 'academic', start_date: '', end_date: '', description: '', is_public: true });

  async function save(e: React.FormEvent) {
    e.preventDefault();
    try {
      await fetchApi('/calendar/events', { method: 'POST', body: JSON.stringify({ ...f, end_date: f.end_date || null, description: f.description || null }) });
      onDone('Added to the calendar.');
      setF({ ...f, title: '', start_date: '', end_date: '', description: '' });
      await reload();
    } catch (err) { onError(err, 'Could not add it.'); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card description="Public entries appear in parents’ feed: resumption, exams, holidays, sports day, PTA meetings.">
        {!events ? <Loading /> : events.length === 0 ? <Empty>Nothing on the calendar.</Empty> : (
          <Table columns={['Dates', 'Event', 'Kind', '']}>
            {events.map((ev) => (
              <tr key={ev.id}>
                <Cell className="whitespace-nowrap">{ev.start_date.slice(0, 10)}{ev.end_date && ev.end_date !== ev.start_date ? ` → ${ev.end_date.slice(0, 10)}` : ''}</Cell>
                <Cell className="font-medium">{ev.title}{!ev.is_public && <Badge>staff only</Badge>}{ev.description && <span className="block text-xs text-ink-muted">{ev.description}</span>}</Cell>
                <Cell className="capitalize">{ev.category === 'ptm' ? 'PTA meeting' : ev.category}</Cell>
                <Cell><button type="button" className="text-sm text-negative underline" onClick={async () => { if (!window.confirm('Remove this event?')) return; try { await fetchApi(`/calendar/events/${ev.id}`, { method: 'DELETE' }); onDone('Removed.'); await reload(); } catch (err) { onError(err, 'Could not remove it.'); } }}>Remove</button></Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      <Card title="Add an event">
        <form className="space-y-3" onSubmit={save}>
          <Field label="Title"><input className={inputClass} required value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder="First term resumes" /></Field>
          <Field label="Kind"><select className={inputClass} value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })}><option value="academic">Academic</option><option value="exam">Exams</option><option value="holiday">Holiday</option><option value="sports">Sports</option><option value="ptm">PTA meeting</option></select></Field>
          <Field label="From"><input className={inputClass} type="date" required value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} /></Field>
          <Field label="To (optional)"><input className={inputClass} type="date" min={f.start_date} value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} /></Field>
          <Field label="Details"><textarea className={inputClass} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.is_public} onChange={(e) => setF({ ...f, is_public: e.target.checked })} /> Parents can see it</label>
          <Button type="submit">Add</Button>
        </form>
      </Card>
    </div>
  );
}

function StaffRecords({ onDone, onError }: H) {
  type S = { id: number; name: string | null; staff_number: string | null; designation: string | null; employment_status: string | null; basic_monthly: number };
  const [staff] = useLoad<S[]>(async () => (await fetchApi<{ data: S[] }>('/hr/staff-pay')).data, onError, '');
  const [pick, setPick] = useState<S | null>(null);
  type Rec = { leave_balances: Record<string, { days_allowed: number | null; days_taken: number }>; employment_history: { employer: string; role: string; start_year: number; end_year: number | null }[] };
  type Hist = { status_events: { from: string | null; to: string; note: string | null; created_at: string; by: string | null }[]; salary_history: { basic_monthly: string | number; effective_from: string; note: string | null }[]; loans: { id: number; principal?: number; balance?: number; status: string }[] };
  const [rec, setRec] = useState<Rec | null>(null);
  const [hist, setHist] = useState<Hist | null>(null);
  const [alloc, setAlloc] = useState<Record<string, string>>({});
  const [job, setJob] = useState({ employer: '', role: '', start_year: '', end_year: '' });

  async function open(s: S) {
    setPick(s);
    try {
      const [r, h] = await Promise.all([fetchApi<Rec>(`/hr/staff/${s.id}/employment-record`), fetchApi<Hist>(`/hr/staff/${s.id}/history`)]);
      setRec(r); setHist(h);
      setAlloc(Object.fromEntries(LEAVE.map((t) => [t, r.leave_balances[t]?.days_allowed != null ? String(r.leave_balances[t].days_allowed) : ''])));
    } catch (err) { onError(err, 'Could not open the record.'); }
  }

  async function post(path: string, method: string, body: unknown, msg: string) {
    if (!pick) return;
    try { await fetchApi(path, { method, body: JSON.stringify(body) }); onDone(msg); await open(pick); } catch (err) { onError(err, 'That did not go through.'); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[18rem_1fr]">
      <Card title="Staff">
        {!staff ? <Loading /> : (
          <ul className="space-y-1 text-sm">{staff.map((s) => <li key={s.id}><button type="button" className={`w-full rounded px-2 py-1 text-left hover:bg-surface-sunken ${pick?.id === s.id ? 'bg-surface-sunken font-medium' : ''}`} onClick={() => open(s)}>{s.name}<span className="block text-xs text-ink-muted">{[s.designation, s.employment_status].filter(Boolean).join(' · ')}</span></button></li>)}</ul>
        )}
      </Card>
      {!pick || !rec || !hist ? <Empty>Choose a member of staff.</Empty> : (
        <div className="grid gap-5 xl:grid-cols-2">
          <Card title="Leave allowance per year" description="Days. Leave blank to use the school default.">
            <form className="grid gap-2 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void post(`/hr/staff/${pick.id}/leave-allocation`, 'PUT', { allocations: Object.fromEntries(Object.entries(alloc).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)])) }, 'Leave allowance saved.'); }}>
              {LEAVE.map((t) => <Field key={t} label={`${t} (taken ${rec.leave_balances[t]?.days_taken ?? 0})`}><input className={inputClass} type="number" min={0} max={365} value={alloc[t] ?? ''} onChange={(e) => setAlloc({ ...alloc, [t]: e.target.value })} /></Field>)}
              <div className="sm:col-span-2"><Button type="submit" variant="secondary">Save</Button></div>
            </form>
          </Card>
          <Card title="Previous employment">
            <ul className="mb-3 space-y-1 text-sm">{rec.employment_history.map((h, i) => <li key={i}>{h.role}, {h.employer} ({h.start_year}–{h.end_year ?? 'now'})</li>)}{rec.employment_history.length === 0 && <li className="text-ink-muted">None recorded.</li>}</ul>
            <form className="grid gap-2 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void post(`/hr/staff/${pick.id}/employment-history`, 'POST', { ...job, start_year: Number(job.start_year), end_year: job.end_year ? Number(job.end_year) : null }, 'Added.').then(() => setJob({ employer: '', role: '', start_year: '', end_year: '' })); }}>
              <Field label="Employer"><input className={inputClass} required value={job.employer} onChange={(e) => setJob({ ...job, employer: e.target.value })} /></Field>
              <Field label="Role"><input className={inputClass} required value={job.role} onChange={(e) => setJob({ ...job, role: e.target.value })} /></Field>
              <Field label="From (year)"><input className={inputClass} type="number" min={1950} required value={job.start_year} onChange={(e) => setJob({ ...job, start_year: e.target.value })} /></Field>
              <Field label="To (year)"><input className={inputClass} type="number" min={1950} value={job.end_year} onChange={(e) => setJob({ ...job, end_year: e.target.value })} /></Field>
              <div className="sm:col-span-2"><Button type="submit" variant="secondary">Add</Button></div>
            </form>
          </Card>
          <Card title="Pay history">
            {hist.salary_history.length === 0 ? <p className="text-sm text-ink-muted">No salary set. Set it on Payroll.</p> : <ul className="space-y-1 text-sm">{hist.salary_history.map((h, i) => <li key={i}>{nairaAmount(Number(h.basic_monthly))} a month from {h.effective_from?.slice(0, 10)}{h.note && <span className="text-ink-muted"> · {h.note}</span>}</li>)}</ul>}
            {hist.loans.length > 0 && <p className="mt-2 text-sm">Loans: {hist.loans.map((l) => `${nairaAmount(Number(l.balance ?? 0))} (${l.status})`).join(', ')}</p>}
          </Card>
          <Card title="Status history">
            {hist.status_events.length === 0 ? <p className="text-sm text-ink-muted">No changes.</p> : <ul className="space-y-1 text-sm">{hist.status_events.map((e, i) => <li key={i}>{e.created_at?.slice(0, 10)}: {e.from ?? '—'} → {e.to}<span className="text-xs text-ink-muted"> · {e.by}{e.note ? ` · ${e.note}` : ''}</span></li>)}</ul>}
          </Card>
        </div>
      )}
    </div>
  );
}

function Achievements({ onDone, onError }: H) {
  type A = { id: number; achievement_type: string; title: string; description: string | null; date_achieved: string | null; verified_at: string | null };
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [list, setList] = useState<A[] | null>(null);
  const [f, setF] = useState({ achievement_type: 'academic', title: '', description: '', date_achieved: '' });

  const load = useCallback(async (id: number) => {
    try { setList((await fetchApi<{ data: A[] }>(`/students/${id}/portfolio`)).data); } catch (err) { onError(err, 'Could not load achievements.'); }
  }, [onError]);

  async function post(path: string, body: unknown, msg: string) {
    if (!student) return;
    try { await fetchApi(path, { method: 'POST', body: JSON.stringify(body) }); onDone(msg); await load(student.id); } catch (err) { onError(err, 'That did not go through.'); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="A student’s achievements" description="Shown to the student and their parents. Verify an entry once the evidence is checked.">
        <StudentPicker value={student} onChange={(s) => { setStudent(s); setList(null); if (s) void load(s.id); }} />
        {student && (!list ? <Loading /> : list.length === 0 ? <p className="mt-3 text-sm text-ink-muted">None recorded.</p> : (
          <ul className="mt-3 space-y-2 text-sm">{list.map((a) => <li key={a.id}><span className="font-medium">{a.title}</span> <Badge tone="blue">{a.achievement_type}</Badge> {a.verified_at ? <Badge tone="green">verified</Badge> : <button type="button" className="text-xs text-brand underline" onClick={() => post(`/portfolio/${a.id}/verify`, {}, 'Verified.')}>Verify</button>}{a.description && <span className="block text-xs text-ink-muted">{a.description}</span>}</li>)}</ul>
        ))}
      </Card>
      {student && (
        <Card title="Record an achievement">
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void post(`/students/${student.id}/portfolio`, { ...f, description: f.description || null, date_achieved: f.date_achieved || null }, 'Recorded.').then(() => setF({ ...f, title: '', description: '' })); }}>
            <Field label="Kind"><select className={inputClass} value={f.achievement_type} onChange={(e) => setF({ ...f, achievement_type: e.target.value })}>{['academic', 'sports', 'arts', 'leadership', 'community'].map((t) => <option key={t}>{t}</option>)}</select></Field>
            <Field label="Title"><input className={inputClass} required value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder="1st place, state spelling bee" /></Field>
            <Field label="Date"><input className={inputClass} type="date" value={f.date_achieved} onChange={(e) => setF({ ...f, date_achieved: e.target.value })} /></Field>
            <Field label="Details"><textarea className={inputClass} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
            <Button type="submit">Record</Button>
          </form>
        </Card>
      )}
    </div>
  );
}

function CardChecks({ onError }: Pick<H, 'onError'>) {
  const [renewals] = useLoad(async () => (await fetchApi<{ days: number; data: { id: number; serial: string; holder_id: number; expires_on: string; status: string }[] }>('/id-cards/renewals')), onError, '');
  const [log] = useLoad(async () => (await fetchApi<{ data: { outcome: string; created_at: string; serial: string | null; checked_by: string | null }[] }>('/id-cards/verification-log')).data, onError, '');
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="Cards due for renewal" description={renewals ? `Expiring in the next ${renewals.days} days with no newer card. Renew them from ID cards.` : undefined}>
        {!renewals ? <Loading /> : renewals.data.length === 0 ? <p className="text-sm text-ink-muted">None due.</p> : <ul className="space-y-1 text-sm">{renewals.data.map((c) => <li key={c.id}><span className="font-mono text-xs">{c.serial}</span> · expires {c.expires_on} <Badge>{c.status}</Badge></li>)}</ul>}
      </Card>
      <Card title="Verification log" description="Every time a card was checked at the gate or scanned.">
        {!log ? <Loading /> : log.length === 0 ? <p className="text-sm text-ink-muted">No checks yet.</p> : <ul className="space-y-1 text-xs">{log.map((l, i) => <li key={i}>{when(l.created_at)} · <span className="font-mono">{l.serial ?? 'unknown card'}</span> · <Badge tone={l.outcome === 'valid' ? 'green' : 'red'}>{l.outcome}</Badge> {l.checked_by && <span className="text-ink-muted">by {l.checked_by}</span>}</li>)}</ul>}
      </Card>
    </div>
  );
}

function DataExport({ onDone, onError }: H) {
  type E = { id: number; status: string; includes_medical: boolean; row_counts: Record<string, number> | null; file_size: number | null; requested_by: string | null; requested_at: string; completed_at: string | null; expires_at: string | null; downloadable: boolean; error: string | null };
  const [list, reload] = useLoad<E[]>(async () => (await fetchApi<{ data: E[] }>('/admin/exports')).data, onError, '');
  const [medical, setMedical] = useState(false);

  async function request() {
    if (!window.confirm(medical ? 'Export all school data INCLUDING health records? Keep the file somewhere secure; it is personal data under the NDPA.' : 'Export all school data? Keep the file somewhere secure.')) return;
    try { await fetchApi('/admin/exports', { method: 'POST', body: JSON.stringify({ include_medical: medical }) }); onDone('Export started. It appears below when ready.'); await reload(); } catch (err) { onError(err, 'Could not start the export.'); }
  }

  return (
    <Card title="Your school’s data" description="Everything SchoolPilot holds for your school, as a downloadable file — for your own records, or to move to another system. The link expires.">
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={medical} onChange={(e) => setMedical(e.target.checked)} /> Include health records (allergies, medical notes)</label>
      <Button className="mt-3" onClick={request}>Request an export</Button>
      <div className="mt-5">
        {!list ? <Loading /> : list.length === 0 ? <p className="text-sm text-ink-muted">No exports yet.</p> : (
          <Table columns={['Requested', 'By', 'Status', 'Size', '']}>
            {list.map((x) => (
              <tr key={x.id}>
                <Cell className="text-xs">{when(x.requested_at)}</Cell>
                <Cell>{x.requested_by}{x.includes_medical && <Badge tone="amber">with health</Badge>}</Cell>
                <Cell><Badge tone={x.status === 'completed' ? 'green' : x.status === 'failed' ? 'red' : 'amber'}>{x.status}</Badge>{x.error && <span className="block text-xs text-negative">{x.error}</span>}</Cell>
                <Cell className="text-xs">{x.file_size ? `${(x.file_size / 1_048_576).toFixed(1)} MB` : '—'}</Cell>
                <Cell>{x.downloadable ? <a className="text-sm text-brand underline" href={`/api/backend/admin/exports/${x.id}/download`}>Download</a> : x.expires_at && new Date(x.expires_at) < new Date() ? <span className="text-xs text-ink-muted">expired</span> : null}</Cell>
              </tr>
            ))}
          </Table>
        )}
        <Button variant="secondary" className="mt-3" onClick={() => void reload()}>Refresh</Button>
      </div>
    </Card>
  );
}
