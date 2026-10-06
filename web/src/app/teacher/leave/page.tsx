'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * A teacher's own HR (TASK 19, 20): leave balances, requesting and
 * withdrawing leave, the employment record the school holds, and the
 * professional details on their teacher profile. Employment terms (rating,
 * contract, staff number) are the school office's to set.
 */

interface Leave { id: number; leave_type: string; start_date: string; end_date: string; days_requested: number; reason: string | null; status: string; decision_note: string | null; decider: { name: string } | null }
interface Record_ { name: string; designation: string | null; employment_date: string | null; qualifications: string[] | null; employment_history: { employer: string; role: string; start_year: number; end_year: number | null }[]; leave_balances: Record<string, { days_allowed: number | null; days_taken: number; days_remaining: number | null }> }
interface Profile { qualification: string | null; specialization: string | null; years_of_experience: number | null; certifications: string[] | null; subjects_taught: string[] | null; employee_id: string | null; contract_type: string | null; date_joined: string | null }

const TYPES = ['annual', 'sick', 'maternity', 'compassionate', 'study'];
const TONE: Record<string, 'green' | 'amber' | 'red' | 'slate'> = { approved: 'green', pending: 'amber', rejected: 'red', cancelled: 'slate' };

export default function TeacherLeave() {
  const [me, setMe] = useState<number | null>(null);
  const [leave, setLeave] = useState<Leave[] | null>(null);
  const [record, setRecord] = useState<Record_ | null>(null);
  const [profile, setProfile] = useState<Profile | null>(null);
  const [form, setForm] = useState({ leave_type: 'annual', start_date: '', end_date: '', reason: '' });
  const [pf, setPf] = useState({ qualification: '', specialization: '', years_of_experience: '', certifications: '', subjects_taught: '' });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setLeave((await fetchApi<{ data: Leave[] }>('/hr/leave?per_page=50')).data);
    } catch (err) {
      setLeave([]);
      setError(errorText(err, 'Could not load your leave.'));
    }
    fetchApi<Record_>('/hr/staff/me/employment-record').then(setRecord).catch(() => undefined);
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ id: number }>('/user').then((u) => {
      setMe(u.id);
      return fetchApi<{ data: Profile }>(`/teachers/${u.id}/profile`);
    }).then((r) => {
      setProfile(r.data);
      setPf({ qualification: r.data.qualification ?? '', specialization: r.data.specialization ?? '', years_of_experience: r.data.years_of_experience != null ? String(r.data.years_of_experience) : '', certifications: (r.data.certifications ?? []).join('\n'), subjects_taught: (r.data.subjects_taught ?? []).join('\n') });
    }).catch(() => undefined);
  }, [load]);

  async function act(fn: () => Promise<{ message?: string }>, fallback: string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const r = await fn();
      setNotice(r.message ?? 'Saved.');
      await load();
    } catch (err) {
      setError(errorText(err, fallback));
    } finally {
      setBusy(false);
    }
  }

  const lines = (v: string) => v.split('\n').map((x) => x.trim()).filter(Boolean);

  return (
    <PageShell title="My leave & record" subtitle="Ask for leave, and see what the school holds about your employment.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {record && (
        <div className="mb-5 grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
          {Object.entries(record.leave_balances).map(([t, b]) => (
            <StatTile key={t} label={`${t} leave`} value={b.days_remaining ?? '—'} hint={b.days_allowed != null ? `of ${b.days_allowed} days · ${b.days_taken} taken` : `${b.days_taken} taken this year`} tone={b.days_remaining === 0 ? 'red' : 'slate'} />
          ))}
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <Card title="My requests">
          {!leave ? <Loading /> : leave.length === 0 ? <Empty>No leave requested.</Empty> : (
            <Table columns={['Type', 'Dates', 'Days', 'Status', '']}>
              {leave.map((l) => (
                <tr key={l.id}>
                  <Cell className="capitalize">{l.leave_type}</Cell>
                  <Cell>{l.start_date.slice(0, 10)} → {l.end_date.slice(0, 10)}{l.reason && <span className="block text-xs text-ink-muted">{l.reason}</span>}</Cell>
                  <Cell>{l.days_requested}</Cell>
                  <Cell><Badge tone={TONE[l.status] ?? 'slate'}>{l.status}</Badge>{l.decision_note && <span className="block text-xs text-ink-muted">{l.decider?.name}: {l.decision_note}</span>}</Cell>
                  <Cell>{l.status === 'pending' && <button type="button" className="text-sm text-negative underline" onClick={() => { if (window.confirm('Withdraw this request?')) void act(() => fetchApi(`/hr/leave/${l.id}/cancel`, { method: 'POST', body: '{}' }), 'Could not withdraw it.'); }}>Withdraw</button>}</Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>
        <Card title="Ask for leave">
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void act(() => fetchApi('/hr/leave', { method: 'POST', body: JSON.stringify({ ...form, reason: form.reason || null }) }).then((r) => { setForm({ ...form, start_date: '', end_date: '', reason: '' }); return r as { message?: string }; }), 'Could not send the request.'); }}>
            <Field label="Type"><select className={inputClass} value={form.leave_type} onChange={(e) => setForm({ ...form, leave_type: e.target.value })}>{TYPES.map((t) => <option key={t} value={t}>{t}</option>)}</select></Field>
            <Field label="From"><input className={inputClass} type="date" required value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} /></Field>
            <Field label="To"><input className={inputClass} type="date" required min={form.start_date} value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} /></Field>
            <Field label="Reason (optional)"><textarea className={inputClass} maxLength={1000} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
            <Button type="submit" disabled={busy}>Send request</Button>
          </form>
        </Card>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <Card title="Employment record" description="Kept by the school office.">
          {!record ? <p className="text-sm text-ink-muted">No staff record is linked to your account yet.</p> : (
            <dl className="space-y-2 text-sm">
              <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Role</dt><dd>{record.designation ?? '—'}</dd></div>
              <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Employed since</dt><dd>{record.employment_date ?? '—'}</dd></div>
              {profile && <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Staff number · contract</dt><dd>{[profile.employee_id, profile.contract_type?.replaceAll('_', ' ')].filter(Boolean).join(' · ') || '—'}</dd></div>}
              {record.employment_history.length > 0 && <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Previous employment</dt><dd><ul>{record.employment_history.map((h, i) => <li key={i}>{h.role}, {h.employer} ({h.start_year}–{h.end_year ?? 'now'})</li>)}</ul></dd></div>}
            </dl>
          )}
        </Card>
        <Card title="My teaching profile" description="Your qualifications and specialism, as the school sees them.">
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (!me) return; void act(() => fetchApi(`/teachers/${me}/profile`, { method: 'PUT', body: JSON.stringify({ qualification: pf.qualification || null, specialization: pf.specialization || null, years_of_experience: pf.years_of_experience ? Number(pf.years_of_experience) : null, certifications: lines(pf.certifications), subjects_taught: lines(pf.subjects_taught) }) }), 'Could not save your profile.'); }}>
            <Field label="Highest qualification"><input className={inputClass} value={pf.qualification} onChange={(e) => setPf({ ...pf, qualification: e.target.value })} placeholder="B.Sc. (Ed) Mathematics" /></Field>
            <Field label="Specialism"><input className={inputClass} value={pf.specialization} onChange={(e) => setPf({ ...pf, specialization: e.target.value })} /></Field>
            <Field label="Years teaching"><input className={inputClass} type="number" min={0} max={60} value={pf.years_of_experience} onChange={(e) => setPf({ ...pf, years_of_experience: e.target.value })} /></Field>
            <Field label="Certifications (one per line, e.g. TRCN)"><textarea className={inputClass} value={pf.certifications} onChange={(e) => setPf({ ...pf, certifications: e.target.value })} /></Field>
            <Field label="Subjects you can teach (one per line)"><textarea className={inputClass} value={pf.subjects_taught} onChange={(e) => setPf({ ...pf, subjects_taught: e.target.value })} /></Field>
            <Button type="submit" variant="secondary" disabled={busy || !me}>Save</Button>
          </form>
        </Card>
      </div>
    </PageShell>
  );
}
