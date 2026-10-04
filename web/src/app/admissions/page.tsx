'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { ApiError, errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Admissions: application → review → test and interview → decision → offer →
 * acceptance → enrolment. Applicants are not students until enrolment, which
 * stops on a likely duplicate until someone confirms it is a different child.
 */

interface Row { id: number; reference: string; section: string; applicant_name: string | null; status: string; status_label: string; intended_class: string | null; offered_class: string | null; offered_class_id: number | null; offer_expires_at: string | null; source: string; purged: boolean; student_id: number | null }
interface Detail extends Row {
  date_of_birth: string | null; gender: string | null; previous_school: string | null; previous_class: string | null;
  guardians: { name: string; phone: string; email?: string; relationship?: string }[];
  documents: { id: number; type: string; label: string; status: string; note: string | null }[];
  assessments: { id: number; kind: string; scheduled_at: string | null; location: string | null; score: number | null; max_score: number | null; notes: string | null }[];
  events: { action: string; to: string | null; by: string | null; note: string | null; at: string }[];
  possible_duplicates: { student_id: number; name: string; admission_number: string; reasons: string[] }[];
}
interface Option { id: number; name: string }

const TONE: Record<string, 'green' | 'amber' | 'red' | 'slate' | 'blue'> = {
  enrolled: 'green', accepted: 'blue', offered: 'blue', rejected: 'red', declined: 'slate', expired: 'slate', withdrawn: 'slate', waitlisted: 'amber',
};

export default function AdmissionsPage() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [counts, setCounts] = useState<Record<string, Record<string, number>>>({});
  const [statuses, setStatuses] = useState<Record<string, string>>({});
  const [status, setStatus] = useState('');
  const [detail, setDetail] = useState<Detail | null>(null);
  const [classes, setClasses] = useState<Option[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState({ section: 'primary', applicant_name: '', date_of_birth: '', gender: 'female', previous_school: '', previous_class: '', intended_class_id: '', guardian_name: '', guardian_phone: '', relationship: 'mother' });
  const [offerClass, setOfferClass] = useState('');
  const [duplicates, setDuplicates] = useState<Detail['possible_duplicates'] | null>(null);

  const load = useCallback(async () => {
    const res = await fetchApi<{ data: Row[]; counts: Record<string, Record<string, number>>; statuses: Record<string, string> }>(`/admissions${status ? `?status=${status}` : ''}`);
    setRows(res.data);
    setCounts(res.counts);
    setStatuses(res.statuses);
  }, [status]);

  const open = useCallback(async (id: number) => {
    setDuplicates(null);
    const d = await fetchApi<Detail>(`/admissions/${id}`);
    setDetail(d);
    setOfferClass(String(d.offered_class_id ?? ''));
  }, []);

  useEffect(() => {
    (async () => {
      try {
        await load();
        setClasses((await fetchApi<{ data?: Option[] }>('/classes')).data ?? []);
      } catch (err) {
        setError(errorText(err, 'Could not load admissions.'));
      }
    })();
  }, [load]);

  async function act(path: string, body: unknown = {}, method = 'POST') {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method, body: JSON.stringify(body) });
      setNotice(res.message ?? 'Saved.');
      await load();
      if (detail) await open(detail.id);
    } catch (err) {
      if (err instanceof ApiError && (err.payload as { code?: string })?.code === 'possible_duplicate') {
        setDuplicates((err.payload as { possible_duplicates: Detail['possible_duplicates'] }).possible_duplicates);
      }
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  }

  const total = (section: string) => Object.values(counts[section] ?? {}).reduce((a, b) => a + Number(b), 0);

  return (
    <PageShell title="Admissions" subtitle="Applicants become students only at enrolment. Unaccepted applications are cleared after the school's retention period."
      actions={<Button variant="accent" onClick={() => setShowNew(!showNew)}>{showNew ? 'Close form' : 'New application'}</Button>}>
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="mb-4 flex flex-wrap gap-3 text-sm">
        {['nursery', 'primary', 'secondary'].map((s) => <span key={s} className="rounded-lg bg-surface px-3 py-2 ring-1 ring-line-soft capitalize">{s}: {total(s)}</span>)}
      </div>

      {showNew && (
        <Card className="mb-6" title="New application" description="Only what a decision needs. Health details are collected at enrolment, not now.">
          <div className="grid gap-3 md:grid-cols-3">
            <Field label="Section"><select className={inputClass} value={form.section} onChange={(e) => setForm({ ...form, section: e.target.value })}><option value="nursery">Nursery</option><option value="primary">Primary</option><option value="secondary">Secondary</option></select></Field>
            <Field label="Child's full name"><input className={inputClass} value={form.applicant_name} onChange={(e) => setForm({ ...form, applicant_name: e.target.value })} /></Field>
            <Field label="Date of birth"><input type="date" className={inputClass} value={form.date_of_birth} onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })} /></Field>
            <Field label="Gender"><select className={inputClass} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}><option value="female">Female</option><option value="male">Male</option></select></Field>
            <Field label="Previous school"><input className={inputClass} value={form.previous_school} onChange={(e) => setForm({ ...form, previous_school: e.target.value })} /></Field>
            <Field label="Class applied for"><select className={inputClass} value={form.intended_class_id} onChange={(e) => setForm({ ...form, intended_class_id: e.target.value })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="Guardian name"><input className={inputClass} value={form.guardian_name} onChange={(e) => setForm({ ...form, guardian_name: e.target.value })} /></Field>
            <Field label="Guardian phone"><input className={inputClass} value={form.guardian_phone} onChange={(e) => setForm({ ...form, guardian_phone: e.target.value })} placeholder="0803 123 4567" /></Field>
            <Field label="Relationship"><select className={inputClass} value={form.relationship} onChange={(e) => setForm({ ...form, relationship: e.target.value })}><option value="mother">Mother</option><option value="father">Father</option><option value="guardian">Guardian</option></select></Field>
          </div>
          <Button className="mt-3" disabled={busy || !form.applicant_name || !form.date_of_birth || !form.guardian_phone} onClick={async () => {
            await act('/admissions', {
              section: form.section, applicant_name: form.applicant_name, date_of_birth: form.date_of_birth, gender: form.gender,
              previous_school: form.previous_school || null, intended_class_id: form.intended_class_id ? Number(form.intended_class_id) : null,
              guardians: [{ name: form.guardian_name || 'Guardian', phone: form.guardian_phone, relationship: form.relationship }],
            });
            setShowNew(false);
          }}>Save application</Button>
        </Card>
      )}

      <div className="grid gap-6 xl:grid-cols-[1fr_1.2fr]">
        <Card title="Applications">
          <select className={`${inputClass} mb-3 max-w-xs`} aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            {Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          {!rows ? <Loading /> : rows.length === 0 ? <Empty>No applications.</Empty> : (
            <Table columns={['Reference', 'Applicant', 'Section', 'Status']}>
              {rows.map((r) => (
                <tr key={r.id} className={`cursor-pointer ${detail?.id === r.id ? 'bg-surface-raised' : ''}`} onClick={() => open(r.id)}>
                  <Cell>{r.reference}</Cell><Cell>{r.purged ? <span className="text-ink-faint">Details removed</span> : r.applicant_name}</Cell>
                  <Cell className="capitalize">{r.section}</Cell><Cell><Badge tone={TONE[r.status] ?? 'amber'}>{r.status_label}</Badge></Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        {detail && (
          <Card title={`${detail.reference} — ${detail.applicant_name ?? 'details removed'}`} description={`${detail.section} · applied for ${detail.intended_class ?? 'no class chosen'} · ${detail.source === 'online' ? 'online form' : 'entered by staff'}`}>
            <p className="mb-3 text-sm">{detail.date_of_birth} · {detail.gender} · from {detail.previous_school ?? '—'}</p>
            <p className="mb-4 text-sm">Guardian: {detail.guardians?.map((g) => `${g.name} (${g.phone})`).join(', ')}</p>

            <h3 className="mb-2 text-sm font-semibold">Documents</h3>
            <ul className="mb-4 space-y-1 text-sm">
              {detail.documents.map((d) => (
                <li key={d.id} className="flex flex-wrap items-center gap-2">
                  <Badge tone={d.status === 'verified' ? 'green' : d.status === 'rejected' ? 'red' : 'amber'}>{d.status === 'verified' ? '✓' : d.status === 'rejected' ? '✕' : '○'} {d.status}</Badge> {d.label}
                  {d.status !== 'verified' && <button className="text-brand underline" disabled={busy} onClick={() => act(`/admissions/${detail.id}/documents`, { type: d.type, status: 'verified' })}>mark verified</button>}
                </li>
              ))}
            </ul>

            <h3 className="mb-2 text-sm font-semibold">Entrance test and interview</h3>
            <ul className="mb-2 text-sm">{detail.assessments.map((a) => (
              <li key={a.id}>{a.kind.replace('_', ' ')} · {a.scheduled_at ? new Date(a.scheduled_at).toLocaleString('en-NG') : 'unscheduled'} · {a.score != null ? `${a.score}/${a.max_score}` : <button className="text-brand underline" onClick={() => { const s = window.prompt('Score out of 100?'); if (s) act(`/admission-assessments/${a.id}`, { score: Number(s), max_score: 100 }, 'PUT'); }}>record result</button>}</li>
            ))}</ul>
            <div className="mb-4 flex gap-2">
              <Button variant="secondary" disabled={busy} onClick={() => act(`/admissions/${detail.id}/assessments`, { kind: 'entrance_test' })}>Add entrance test</Button>
              <Button variant="secondary" disabled={busy} onClick={() => act(`/admissions/${detail.id}/assessments`, { kind: 'interview' })}>Add interview</Button>
            </div>

            <div className="flex flex-wrap gap-2">
              {detail.status === 'submitted' && <Button variant="secondary" disabled={busy} onClick={() => act(`/admissions/${detail.id}/review`)}>Start review</Button>}
              {['submitted', 'under_review', 'assessment', 'waitlisted'].includes(detail.status) && (
                <>
                  <select className={`${inputClass} max-w-[10rem]`} aria-label="Offer class" value={offerClass} onChange={(e) => setOfferClass(e.target.value)}>
                    <option value="">Class to offer…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select>
                  <Button variant="accent" disabled={busy || !offerClass} onClick={() => act(`/admissions/${detail.id}/decision`, { decision: 'offer', class_id: Number(offerClass) })}>Offer a place</Button>
                  <Button variant="secondary" disabled={busy} onClick={() => act(`/admissions/${detail.id}/decision`, { decision: 'waitlist' })}>Waitlist</Button>
                  <Button variant="danger" disabled={busy} onClick={() => { const reason = window.prompt('Reason for not admitting (recorded)?'); if (reason) act(`/admissions/${detail.id}/decision`, { decision: 'reject', reason }); }}>Do not admit</Button>
                </>
              )}
              {['offered', 'accepted', 'enrolled'].includes(detail.status) && <a className="rounded-lg border border-line px-3.5 py-2 text-sm" href={`/api/backend/admissions/${detail.id}/offer-letter`}>Offer letter</a>}
              {detail.status === 'offered' && (
                <>
                  <Button disabled={busy} onClick={() => act(`/admissions/${detail.id}/response`, { accepted: true })}>Parent accepted</Button>
                  <Button variant="secondary" disabled={busy} onClick={() => act(`/admissions/${detail.id}/response`, { accepted: false })}>Parent declined</Button>
                </>
              )}
              {detail.status === 'accepted' && <Button variant="accent" disabled={busy} onClick={() => act(`/admissions/${detail.id}/enrol`)}>Enrol</Button>}
            </div>

            {duplicates && duplicates.length > 0 && (
              <div className="mt-4 rounded-lg bg-caution-soft p-3 text-sm">
                <p className="font-semibold">These existing students may be the same child:</p>
                <ul className="mt-1 list-disc pl-5">{duplicates.map((d) => <li key={d.student_id}>{d.name} ({d.admission_number}) — {d.reasons.join(', ')}</li>)}</ul>
                <Button className="mt-2" variant="secondary" disabled={busy} onClick={() => act(`/admissions/${detail.id}/enrol`, { confirm_not_duplicate: true })}>It is a different child — enrol</Button>
              </div>
            )}

            <h3 className="mb-2 mt-6 text-sm font-semibold">History</h3>
            <ol className="space-y-1 text-xs">{detail.events.map((e, i) => <li key={i}>{new Date(e.at).toLocaleString('en-NG')} · {e.action.replace(/_/g, ' ')}{e.by ? ` · ${e.by}` : ''}{e.note ? ` — ${e.note}` : ''}</li>)}</ol>
          </Card>
        )}
      </div>
    </PageShell>
  );
}
