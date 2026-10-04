'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Transfers in and out.
 *
 * Outgoing: consent, clearance, the school's approval chain, then a signed
 * package delivered by one-time code, direct push, secure link or file.
 * Incoming: records arrive read-only, are verified by a person, and the child
 * is admitted beside them — never merged into an existing profile unless a
 * person chooses to link them.
 */

interface Summary {
  id: number; reference: string; direction: string; status: string; status_label: string;
  student_name: string | null; admission_number: string | null; class: string | null;
  other_school: string | null; on_schoolpilot: boolean; requested_transfer_date: string | null;
  partial_term: boolean; current_step: string | null; missing_clearance: string[];
}
interface Detail extends Summary {
  reason: string | null;
  steps: { role: string; label: string; state: string }[];
  consents: { category: string; given: boolean; decided_at: string }[];
  clearance: { item: string; status: string; note: string | null; override_reason: string | null }[];
  events: { action: string; from: string | null; to: string | null; note: string | null; by: string | null; at: string }[];
  package: { hash: string; expires_at: string; usable: boolean; access_count: number } | null;
  documents: { id: number; type: string; reference: string }[];
  received_records: { id: number; school: string; provenance: string; verification_status: string; amends_id: number | null }[];
  code_active: boolean;
}

const TONE: Record<string, 'green' | 'amber' | 'red' | 'slate' | 'blue'> = {
  completed: 'green', approved: 'blue', accepted: 'blue', records_shared: 'blue',
  rejected: 'red', cancelled: 'slate', additional_info_required: 'red',
};

export default function TransfersPage() {
  const [direction, setDirection] = useState<'outgoing' | 'incoming'>('outgoing');
  const [rows, setRows] = useState<Summary[] | null>(null);
  const [counts, setCounts] = useState<Record<string, Record<string, number>>>({});
  const [detail, setDetail] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [secret, setSecret] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([]);

  const [code, setCode] = useState({ code: '', surname: '' });
  const [request, setRequest] = useState({ school_subdomain: '', surname: '', previous_admission_number: '', date_of_birth: '' });
  const [newIn, setNewIn] = useState({ name: '', previous_school: '', previous_class: '', date_of_birth: '' });
  const [admit, setAdmit] = useState({ class_id: '', gender: 'female' });
  const [pushTo, setPushTo] = useState('');

  const loadList = useCallback(async () => {
    const res = await fetchApi<{ data: Summary[]; counts: Record<string, Record<string, number>> }>(`/transfers?direction=${direction}`);
    setRows(res.data);
    setCounts(res.counts ?? {});
  }, [direction]);

  const open = useCallback(async (id: number) => {
    setDetail(await fetchApi<Detail>(`/transfers/${id}`));
  }, []);

  useEffect(() => {
    (async () => {
      try {
        await loadList();
        const c = await fetchApi<{ data?: { id: number; name: string }[] }>('/classes');
        setClasses(c.data ?? []);
        const id = new URLSearchParams(window.location.search).get('id');
        if (id) await open(Number(id));
      } catch (err) {
        setError(errorText(err, 'Could not load transfers.'));
      }
    })();
  }, [loadList, open]);

  async function act(path: string, body: unknown = {}, onSecret?: (r: Record<string, string>) => string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<Record<string, string>>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(res.message ?? 'Done.');
      if (onSecret) setSecret(onSecret(res));
      await loadList();
      if (detail) await open(detail.id);
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  }

  const d = detail;

  return (
    <PageShell title="Transfers" subtitle="Students leaving and joining, with consent, clearance, approval and verified records.">
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {secret && (
        <div className="mb-4 rounded-lg bg-caution-soft px-4 py-3 text-sm" role="status">
          <p className="font-semibold">Copy this now — it will not be shown again:</p>
          <p className="mt-1 break-all font-mono text-base">{secret}</p>
          <Button className="mt-2" variant="secondary" onClick={() => setSecret(null)}>I have copied it</Button>
        </div>
      )}

      <nav className="mb-4 flex gap-2">
        {(['outgoing', 'incoming'] as const).map((dir) => (
          <Button key={dir} variant={direction === dir ? 'primary' : 'secondary'} onClick={() => { setDirection(dir); setDetail(null); }}>
            {dir === 'outgoing' ? 'Outgoing' : 'Incoming'}
            <span className="ml-2 text-xs opacity-75">{Object.values(counts[dir] ?? {}).reduce((a, b) => a + Number(b), 0)}</span>
          </Button>
        ))}
      </nav>

      {direction === 'incoming' && (
        <div className="mb-6 grid gap-6 lg:grid-cols-3">
          <Card title="Redeem a transfer code" description="From a parent whose child is leaving another SchoolPilot school.">
            <div className="space-y-2">
              <input className={inputClass} placeholder="Code, e.g. AB12-CD34-EF56" value={code.code} onChange={(e) => setCode({ ...code, code: e.target.value })} />
              <input className={inputClass} placeholder="Child's surname" value={code.surname} onChange={(e) => setCode({ ...code, surname: e.target.value })} />
              <Button disabled={busy || !code.code || !code.surname} onClick={() => act('/transfers/incoming/redeem', code)}>Receive records</Button>
            </div>
          </Card>
          <Card title="Ask a SchoolPilot school for records" description="The reply never says whether the child was found.">
            <div className="space-y-2">
              <input className={inputClass} placeholder="School address, e.g. riverside" value={request.school_subdomain} onChange={(e) => setRequest({ ...request, school_subdomain: e.target.value })} />
              <input className={inputClass} placeholder="Child's surname" value={request.surname} onChange={(e) => setRequest({ ...request, surname: e.target.value })} />
              <input className={inputClass} placeholder="Previous admission number" value={request.previous_admission_number} onChange={(e) => setRequest({ ...request, previous_admission_number: e.target.value })} />
              <input className={inputClass} type="date" aria-label="Date of birth" value={request.date_of_birth} onChange={(e) => setRequest({ ...request, date_of_birth: e.target.value })} />
              <Button variant="secondary" disabled={busy || !request.school_subdomain || !request.surname} onClick={() => act('/transfers/incoming/request', request)}>Send request</Button>
            </div>
          </Card>
          <Card title="From a school not on SchoolPilot" description="Records are entered or uploaded and stay unverified until checked.">
            <div className="space-y-2">
              <input className={inputClass} placeholder="Child's full name" value={newIn.name} onChange={(e) => setNewIn({ ...newIn, name: e.target.value })} />
              <input className={inputClass} placeholder="Previous school" value={newIn.previous_school} onChange={(e) => setNewIn({ ...newIn, previous_school: e.target.value })} />
              <input className={inputClass} placeholder="Previous class" value={newIn.previous_class} onChange={(e) => setNewIn({ ...newIn, previous_class: e.target.value })} />
              <input className={inputClass} type="date" aria-label="Date of birth" value={newIn.date_of_birth} onChange={(e) => setNewIn({ ...newIn, date_of_birth: e.target.value })} />
              <Button variant="secondary" disabled={busy || !newIn.name || !newIn.previous_school} onClick={() => act('/transfers/incoming', { ...newIn, date_of_birth: newIn.date_of_birth || null })}>Start incoming transfer</Button>
            </div>
          </Card>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[1fr_1.2fr]">
        <Card title={direction === 'outgoing' ? 'Outgoing transfers' : 'Incoming transfers'}>
          {!rows ? <Loading /> : rows.length === 0 ? <Empty>No {direction} transfers.</Empty> : (
            <Table columns={['Reference', 'Student', direction === 'outgoing' ? 'To' : 'From', 'Status']}>
              {rows.map((r) => (
                <tr key={r.id} className={`cursor-pointer ${d?.id === r.id ? 'bg-surface-raised' : ''}`} onClick={() => open(r.id)}>
                  <Cell>{r.reference}</Cell>
                  <Cell>{r.student_name ?? '—'}<span className="block text-xs text-ink-muted">{r.admission_number} {r.class}</span></Cell>
                  <Cell>{r.other_school ?? '—'}{r.on_schoolpilot && <span className="ml-1 text-xs text-info">SchoolPilot</span>}</Cell>
                  <Cell>
                    <Badge tone={TONE[r.status] ?? 'amber'}>{r.status_label}</Badge>
                    {r.current_step && <span className="block text-xs text-ink-muted">{r.current_step}</span>}
                    {r.missing_clearance.length > 0 && <span className="block text-xs text-caution">Clearance: {r.missing_clearance.join(', ')}</span>}
                  </Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        {d && (
          <Card title={`${d.reference} — ${d.student_name ?? ''}`} description={d.reason ?? undefined}>
            <div className="mb-4 flex flex-wrap gap-2">
              <Badge tone={TONE[d.status] ?? 'amber'}>{d.status_label}</Badge>
              {d.partial_term && <Badge tone="amber">Mid-term: partial term data</Badge>}
              {d.package && <Badge tone={d.package.usable ? 'green' : 'red'}>Package {d.package.usable ? `valid until ${new Date(d.package.expires_at).toLocaleDateString('en-NG')}` : 'expired or superseded'}</Badge>}
            </div>

            {d.direction === 'outgoing' && (
              <>
                <ol className="mb-4 flex flex-wrap gap-2">
                  {d.steps.map((s) => (
                    <li key={s.role} className={`rounded-lg px-3 py-1 text-xs ring-1 ${s.state === 'current' ? 'bg-caution-soft ring-accent' : s.state === 'done' ? 'bg-positive-soft ring-positive/30' : 'ring-line-soft'}`}>
                      {s.state === 'done' ? '✓' : s.state === 'current' ? '●' : '○'} {s.label}
                    </li>
                  ))}
                </ol>

                <h3 className="mb-2 text-sm font-semibold">Consent</h3>
                {d.consents.length === 0 ? <p className="mb-4 text-sm text-ink-muted">Waiting for a guardian to respond in their SchoolPilot account.</p> : (
                  <ul className="mb-4 text-sm">{d.consents.map((c, i) => <li key={i}>{c.given ? '✓' : '✕'} {c.category} · {new Date(c.decided_at).toLocaleString('en-NG')}</li>)}</ul>
                )}

                <h3 className="mb-2 text-sm font-semibold">Clearance</h3>
                <Table columns={['Item', 'Status', '']}>
                  {d.clearance.map((c) => (
                    <tr key={c.item}>
                      <Cell>{c.item}</Cell>
                      <Cell><Badge tone={c.status === 'outstanding' ? 'amber' : 'green'}>{c.status}</Badge>{c.override_reason && <span className="block text-xs text-ink-muted">{c.override_reason}</span>}</Cell>
                      <Cell>
                        {c.status === 'outstanding' && ['submitted', 'awaiting_consent', 'clearance_pending'].includes(d.status) && (
                          <div className="flex gap-2">
                            <Button variant="secondary" disabled={busy} onClick={() => act(`/transfers/${d.id}/clearance`, { item: c.item, status: 'cleared' })}>Cleared</Button>
                            <Button variant="secondary" disabled={busy} onClick={() => {
                              const reason = window.prompt('Why release despite this being outstanding? (recorded)');
                              if (reason) act(`/transfers/${d.id}/clearance`, { item: c.item, status: 'overridden', override_reason: reason });
                            }}>Override…</Button>
                          </div>
                        )}
                      </Cell>
                    </tr>
                  ))}
                </Table>

                <div className="mt-4 flex flex-wrap gap-2">
                  {d.status === 'draft' && <Button onClick={() => act(`/transfers/${d.id}/submit`)} disabled={busy}>Submit</Button>}
                  {d.status === 'records_requested' && <Button onClick={() => act(`/transfers/${d.id}/take-up`)} disabled={busy}>Take up this request</Button>}
                  {d.status === 'pending_approval' && <Button variant="accent" onClick={() => act(`/transfers/${d.id}/approve`)} disabled={busy}>Approve: {d.current_step}</Button>}
                  {['approved', 'awaiting_acceptance'].includes(d.status) && (
                    <>
                      <Button variant="accent" disabled={busy} onClick={() => act(`/transfers/${d.id}/code`, {}, (r) => r.code)}>Issue transfer code</Button>
                      <input className={`${inputClass} max-w-[12rem]`} placeholder="SchoolPilot school address" value={pushTo} onChange={(e) => setPushTo(e.target.value)} />
                      <Button variant="secondary" disabled={busy || !pushTo} onClick={() => act(`/transfers/${d.id}/push`, { school_subdomain: pushTo })}>Send directly</Button>
                      <Button variant="secondary" disabled={busy} onClick={() => act(`/transfers/${d.id}/secure-link`, {}, (r) => r.url)}>Secure link</Button>
                      <a className="rounded-lg border border-line px-3.5 py-2 text-sm" href={`/api/backend/transfers/${d.id}/package`}>Download package</a>
                    </>
                  )}
                  {['approved', 'awaiting_acceptance', 'accepted', 'records_shared'].includes(d.status) && (
                    <>
                      <Button variant="secondary" disabled={busy} onClick={() => act(`/transfers/${d.id}/package`)}>Refresh package</Button>
                      <Button disabled={busy} onClick={() => window.confirm('Complete the transfer? The student leaves and their record becomes a read-only archive.') && act(`/transfers/${d.id}/complete`)}>Complete transfer</Button>
                    </>
                  )}
                  {!['completed', 'rejected', 'cancelled'].includes(d.status) && (
                    <Button variant="danger" disabled={busy} onClick={() => {
                      const reason = window.prompt('Reason for rejecting this transfer?');
                      if (reason) act(`/transfers/${d.id}/reject`, { reason });
                    }}>Reject</Button>
                  )}
                </div>

                {d.documents.length > 0 && (
                  <p className="mt-4 text-sm">Documents: {d.documents.map((doc) => (
                    <a key={doc.id} className="mr-3 text-brand underline" href={`/api/backend/academic-documents/${doc.id}/pdf`}>{doc.type.replace('_', ' ')} ({doc.reference})</a>
                  ))}</p>
                )}
              </>
            )}

            {d.direction === 'incoming' && (
              <>
                {d.status === 'awaiting_acceptance' && <Button className="mb-4" variant="accent" disabled={busy} onClick={() => act(`/transfers/${d.id}/accept`)}>Accept records</Button>}
                <h3 className="mb-2 text-sm font-semibold">Received records</h3>
                {d.received_records.length === 0 ? <Empty>Nothing received yet.</Empty> : (
                  <Table columns={['From', 'Source', 'Status', '']}>
                    {d.received_records.map((r) => (
                      <tr key={r.id}>
                        <Cell>{r.school}{r.amends_id && <span className="block text-xs text-ink-muted">Local amendment</span>}</Cell>
                        <Cell>{r.provenance.replace(/_/g, ' ')}</Cell>
                        <Cell><Badge tone={r.verification_status === 'verified' ? 'green' : r.verification_status === 'pending' ? 'amber' : 'red'}>{r.verification_status}</Badge></Cell>
                        <Cell>
                          {r.verification_status === 'pending' && (
                            <div className="flex gap-2">
                              <Button variant="secondary" disabled={busy} onClick={() => act(`/previous-records/${r.id}/verify`, { status: 'verified' })}>Verified</Button>
                              <Button variant="secondary" disabled={busy} onClick={() => {
                                const note = window.prompt('What is missing or inconsistent?');
                                if (note) act(`/previous-records/${r.id}/verify`, { status: 'additional_info_required', note });
                              }}>Needs information</Button>
                            </div>
                          )}
                        </Cell>
                      </tr>
                    ))}
                  </Table>
                )}

                {!['completed', 'rejected', 'cancelled', 'records_requested', 'awaiting_acceptance'].includes(d.status) && (
                  <div className="mt-4 rounded-lg p-3 ring-1 ring-line-soft">
                    <h3 className="mb-2 text-sm font-semibold">Admit</h3>
                    <p className="mb-2 text-xs text-ink-muted">A new admission number and enrolment are created beside the received history, which stays read-only.</p>
                    <div className="grid gap-2 md:grid-cols-3">
                      <Field label="Class">
                        <select className={inputClass} value={admit.class_id} onChange={(e) => setAdmit({ ...admit, class_id: e.target.value })}>
                          <option value="">Choose…</option>
                          {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                      </Field>
                      <Field label="Gender">
                        <select className={inputClass} value={admit.gender} onChange={(e) => setAdmit({ ...admit, gender: e.target.value })}>
                          <option value="female">Female</option><option value="male">Male</option>
                        </select>
                      </Field>
                      <div className="flex items-end">
                        <Button variant="accent" disabled={busy || !admit.class_id} onClick={() => act(`/transfers/${d.id}/admit`, { name: d.student_name, gender: admit.gender, class_id: Number(admit.class_id) })}>Admit student</Button>
                      </div>
                    </div>
                  </div>
                )}
              </>
            )}

            <h3 className="mb-2 mt-6 text-sm font-semibold">History</h3>
            <ol className="space-y-1 text-xs">
              {d.events.map((e, i) => (
                <li key={i}><span className="text-ink-muted">{new Date(e.at).toLocaleString('en-NG')}</span> · {e.action.replace(/_/g, ' ')}{e.to && e.from !== e.to ? ` → ${e.to.replace(/_/g, ' ')}` : ''}{e.by ? ` · ${e.by}` : ''}{e.note ? ` — ${e.note}` : ''}</li>
              ))}
            </ol>
          </Card>
        )}
      </div>
    </PageShell>
  );
}
