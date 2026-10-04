'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * A student's permanent academic record, and the registrar's tools for it:
 * closing a term into locked snapshots, importing older records, issuing
 * transcripts. Past terms come from locked snapshots — nothing here is
 * recomputed from today's settings.
 */

type Tab = 'overview' | 'results' | 'previous' | 'documents';

interface Subject { subject: string; state: string; total: number | null; grade: string | null; components: Record<string, number> | null }
interface TermRow {
  id: number; session: string; term: string; class: string; version: number; provenance: string; provenance_label: string;
  verified: boolean; partial_term: boolean; corrected: boolean; correction_reason: string | null; intact: boolean;
  figures: { subjects: Subject[]; average: number | null; grade: string | null; position: number | null; class_size: number | null; attendance: { rate: number | null } | null; grading_scale: { grade: string; min: number; max: number }[] | null };
}
interface Profile {
  student: { id: number; name: string; admission_number: string; status: string; class: string | null };
  timeline: { session: string; class: string; status: string; outcome: string | null; from: string | null; to: string | null; destination: string | null }[];
  terms: TermRow[];
  sessions: { session: string; terms: number; session_average: number | null }[];
  partial_history: boolean;
  previous_schools: { id: number; school: string; provenance_label: string; verification_status: string; received_at: string }[];
  transfers: { id: number; reference: string; direction: string; status: string; other_school_name: string | null }[];
}
interface Doc { id: number; type: string; reference: string; issued_at: string; revoked_at: string | null; signatory_name: string }
interface Option { id: number; name: string; is_current?: boolean }

const OUTCOME_LABEL: Record<string, string> = { promoted: 'Promoted', repeated: 'Repeated', graduated: 'Graduated', transferred_out: 'Transferred', withdrawn: 'Withdrawn' };

function isAdmin(): boolean {
  return typeof document !== 'undefined' && /(?:^|;\s*)sp_role=school_admin(?:;|$)/.test(document.cookie);
}

export default function AcademicHistoryPage() {
  const [admin] = useState(isAdmin);
  // Opened from the student list with ?student_id=…
  const [picked, setPicked] = useState<PickedStudent | null>(() => {
    if (typeof window === 'undefined') return null;
    const id = Number(new URLSearchParams(window.location.search).get('student_id'));
    return id ? { id, name: null, admission_number: null, class: null } : null;
  });
  const [tab, setTab] = useState<Tab>('overview');
  const [profile, setProfile] = useState<Profile | null>(null);
  const [docs, setDocs] = useState<{ types: Record<string, string>; data: Doc[] } | null>(null);
  const [docType, setDocType] = useState('transcript');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [terms, setTerms] = useState<Option[]>([]);
  const [classes, setClasses] = useState<Option[]>([]);
  const [closeTermId, setCloseTermId] = useState('');
  const [closeClassId, setCloseClassId] = useState('');
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importResult, setImportResult] = useState<{ batch_id: number; valid_rows: number; error_rows: number; errors: { row: number; problems: string[] }[] } | null>(null);

  useEffect(() => {
    if (!admin) return;
    (async () => {
      try {
        const [t, c] = await Promise.all([fetchApi<{ data?: Option[] }>('/terms'), fetchApi<{ data?: Option[] }>('/classes')]);
        setTerms(t.data ?? []);
        setClasses(c.data ?? []);
        setCloseTermId(String((t.data ?? []).find((x) => x.is_current)?.id ?? t.data?.[0]?.id ?? ''));
        setCloseClassId(String(c.data?.[0]?.id ?? ''));
      } catch {
        // The registrar tools simply stay empty.
      }
    })();
  }, [admin]);

  const load = useCallback(async (id: number) => {
    const [p, d] = await Promise.all([
      fetchApi<Profile>(`/students/${id}/academic-history`),
      fetchApi<{ types: Record<string, string>; data: Doc[] }>(`/students/${id}/documents`),
    ]);
    setProfile(p);
    setDocs(d);
  }, []);

  useEffect(() => {
    if (!picked) return;
    (async () => {
      try {
        await load(picked.id);
      } catch (err) {
        setError(errorText(err, 'Could not load this record.'));
      }
    })();
  }, [picked, load]);

  async function post(path: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(res.message ?? done);
      if (picked) await load(picked.id);
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  }

  async function closeTerm(acceptUnapproved: boolean) {
    await post('/academic-history/close-term', { term_id: Number(closeTermId), class_id: Number(closeClassId), accept_unapproved: acceptUnapproved }, 'Term locked.');
  }

  async function previewImport() {
    if (!importFile) return;
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('file', importFile);
      setImportResult(await uploadApi('/academic-history/import/preview', form));
    } catch (err) {
      setError(errorText(err, 'Could not read the file.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell title="Academic history" subtitle="The permanent record: locked term results, progression, previous schools and official documents.">
      <ErrorNote message={error} />
      {notice && <p className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {admin && (
        <div className="mb-6 grid gap-6 lg:grid-cols-2">
          <Card title="Close a term" description="Locks each student's results as issued, from the approved broadsheet. After this, results change only through a versioned correction.">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Term">
                <select className={inputClass} value={closeTermId} onChange={(e) => setCloseTermId(e.target.value)}>
                  {terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </Field>
              <Field label="Class">
                <select className={inputClass} value={closeClassId} onChange={(e) => setCloseClassId(e.target.value)}>
                  {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button variant="accent" disabled={busy || !closeTermId || !closeClassId} onClick={() => closeTerm(false)}>Close and lock term</Button>
              <Button variant="secondary" disabled={busy} onClick={() => {
                if (window.confirm('Lock figures that were never approved? The record will say so.')) closeTerm(true);
              }}>Lock unapproved figures…</Button>
            </div>
          </Card>

          <Card title="Import older records" description="Excel or CSV. Every invalid row is listed and can be downloaded; nothing is dropped silently. Imported terms are marked unverified until checked.">
            <div className="flex flex-wrap items-center gap-2">
              {/* A file download through the API proxy, not a page: a plain link is right. */}
              <a className="text-sm text-brand underline" href={`/api/backend/academic-history/import/template`} download>Download template</a>
              <input type="file" accept=".csv,text/csv" onChange={(e) => setImportFile(e.target.files?.[0] ?? null)} className="text-sm" />
              <Button variant="secondary" disabled={busy || !importFile} onClick={previewImport}>Check file</Button>
            </div>
            {importResult && (
              <div className="mt-3 text-sm">
                <p>{importResult.valid_rows} row(s) ready · {importResult.error_rows} row(s) with problems</p>
                {importResult.error_rows > 0 && (
                  <>
                    <ul className="mt-2 max-h-40 list-disc overflow-y-auto pl-5 text-negative">
                      {importResult.errors.slice(0, 20).map((e) => <li key={e.row}>Row {e.row}: {e.problems.join(' ')}</li>)}
                    </ul>
                    <a className="text-brand underline" href={`/api/backend/academic-history/import/${importResult.batch_id}/errors`}>Download error report</a>
                  </>
                )}
                {importResult.valid_rows > 0 && (
                  <Button className="mt-2" disabled={busy} onClick={async () => {
                    await post(`/academic-history/import/${importResult.batch_id}/commit`, {}, 'Imported.');
                    setImportResult(null);
                  }}>Import {importResult.valid_rows} valid row(s)</Button>
                )}
              </div>
            )}
          </Card>
        </div>
      )}

      <Card className="mb-6" title="Find a student">
        <div className="max-w-md"><StudentPicker value={picked} onChange={(s) => { setPicked(s); setProfile(null); setTab('overview'); }} /></div>
      </Card>

      {picked && !profile && <Loading />}

      {profile && (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <h2 className="font-headline text-lg font-semibold">{profile.student.name}</h2>
            <span className="text-sm text-ink-muted">{profile.student.admission_number} · {profile.student.class ?? 'No class'}</span>
            <Badge tone={profile.student.status === 'active' ? 'green' : 'slate'}>{profile.student.status}</Badge>
            {profile.partial_history && <Badge tone="amber">Partial or unverified history</Badge>}
          </div>

          <nav className="mb-4 flex flex-wrap gap-2">
            {(['overview', 'results', 'previous', 'documents'] as Tab[]).map((t) => (
              <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => setTab(t)}>
                {{ overview: 'Timeline', results: 'Results by term', previous: 'Previous schools & transfers', documents: 'Documents' }[t]}
              </Button>
            ))}
          </nav>

          {tab === 'overview' && (
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Enrolment timeline">
                {profile.timeline.length === 0 ? <Empty>No enrolments recorded.</Empty> : (
                  <ol className="space-y-3 border-l-2 border-line-soft pl-4">
                    {profile.timeline.map((t, i) => (
                      <li key={i}>
                        <p className="text-sm font-semibold">{t.session} — {t.class}</p>
                        <p className="text-xs text-ink-muted">
                          {t.outcome ? OUTCOME_LABEL[t.outcome] ?? t.outcome : t.status === 'active' ? 'In progress' : t.status}
                          {t.destination && ` to ${t.destination}`}{t.from && ` · from ${t.from}`}{t.to && ` to ${t.to}`}
                        </p>
                      </li>
                    ))}
                  </ol>
                )}
              </Card>
              <Card title="Session averages" description="The mean of each session's term averages, from locked records.">
                {profile.sessions.length === 0 ? <Empty>No locked terms yet.</Empty> : (
                  <Table columns={['Session', 'Terms', 'Average']}>
                    {profile.sessions.map((s) => <tr key={s.session}><Cell>{s.session}</Cell><Cell>{s.terms}</Cell><Cell>{s.session_average ?? '—'}</Cell></tr>)}
                  </Table>
                )}
              </Card>
            </div>
          )}

          {tab === 'results' && (
            profile.terms.length === 0 ? <Empty>No locked term results yet. Results appear here once a term is closed.</Empty> : (
              <div className="space-y-6">
                {profile.terms.map((t) => (
                  <Card key={t.id} title={`${t.session} · ${t.term} · ${t.class ?? ''}`}>
                    <div className="mb-3 flex flex-wrap gap-2">
                      <Badge tone={t.verified ? 'green' : 'amber'}>{t.provenance_label}</Badge>
                      {t.corrected && <Badge tone="blue">Corrected · v{t.version}</Badge>}
                      {t.partial_term && <Badge tone="amber">Part of the term</Badge>}
                      {!t.intact && <Badge tone="red">✕ Fingerprint mismatch</Badge>}
                      {!t.verified && admin && (
                        <Button variant="secondary" disabled={busy} onClick={() => post(`/academic-history/snapshots/${t.id}/verify`, { status: 'verified' }, 'Verified.')}>Mark verified</Button>
                      )}
                    </div>
                    {t.correction_reason && <p className="mb-2 text-xs text-ink-muted">Correction: {t.correction_reason}</p>}
                    <Table columns={['Subject', 'Total', 'Grade']}>
                      {t.figures.subjects.map((s) => (
                        <tr key={s.subject}><Cell>{s.subject}</Cell><Cell>{s.state === 'scored' ? s.total : s.state === 'absent' ? 'ABS' : s.state === 'exempt' ? 'EX' : '—'}</Cell><Cell>{s.grade ?? '—'}</Cell></tr>
                      ))}
                    </Table>
                    <p className="mt-2 text-xs text-ink-muted">
                      Average {t.figures.average ?? '—'} · grade {t.figures.grade ?? '—'}
                      {t.figures.position ? ` · position ${t.figures.position} of ${t.figures.class_size}` : ''}
                      {t.figures.attendance?.rate != null ? ` · attendance ${t.figures.attendance.rate}%` : ''}
                      {t.figures.grading_scale ? ` · scale used: ${t.figures.grading_scale.map((b) => `${b.grade} ${b.min}–${b.max}`).join(', ')}` : ' · grading scale not recorded (imported)'}
                    </p>
                  </Card>
                ))}
              </div>
            )
          )}

          {tab === 'previous' && (
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Previous schools" description="Received read-only, with where each record came from.">
                {profile.previous_schools.length === 0 ? <Empty>No records from previous schools.</Empty> : (
                  <Table columns={['School', 'Source', 'Verification']}>
                    {profile.previous_schools.map((p) => <tr key={p.id}><Cell>{p.school}</Cell><Cell>{p.provenance_label}</Cell><Cell><Badge tone={p.verification_status === 'verified' ? 'green' : 'amber'}>{p.verification_status}</Badge></Cell></tr>)}
                  </Table>
                )}
              </Card>
              <Card title="Transfers">
                {profile.transfers.length === 0 ? <Empty>No transfers.</Empty> : (
                  <Table columns={['Reference', 'Direction', 'Other school', 'Status']}>
                    {profile.transfers.map((t) => <tr key={t.id}><Cell><a className="text-brand underline" href={`/transfers?id=${t.id}`}>{t.reference}</a></Cell><Cell>{t.direction}</Cell><Cell>{t.other_school_name ?? '—'}</Cell><Cell>{t.status}</Cell></tr>)}
                  </Table>
                )}
                {admin && profile.student.status === 'active' && (
                  <Button className="mt-3" variant="secondary" disabled={busy} onClick={() => post('/transfers/outgoing', { student_id: profile.student.id, submit: true }, 'Transfer started.')}>Start transfer out</Button>
                )}
              </Card>
            </div>
          )}

          {tab === 'documents' && docs && (
            <Card title="Official documents" description="Built from locked records. Each carries a reference and a QR code that confirms it is genuine and unaltered.">
              {admin && (
                <div className="mb-4 flex flex-wrap gap-2">
                  <select className={`${inputClass} max-w-xs`} value={docType} onChange={(e) => setDocType(e.target.value)}>
                    {Object.entries(docs.types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                  </select>
                  <Button disabled={busy} onClick={() => post(`/students/${profile.student.id}/documents`, { type: docType }, 'Issued.')}>Issue</Button>
                </div>
              )}
              {docs.data.length === 0 ? <Empty>No documents issued.</Empty> : (
                <Table columns={['Reference', 'Type', 'Issued', 'Status', '']}>
                  {docs.data.map((d) => (
                    <tr key={d.id}>
                      <Cell>{d.reference}</Cell><Cell>{docs.types[d.type] ?? d.type}</Cell><Cell>{new Date(d.issued_at).toLocaleDateString('en-NG')}</Cell>
                      <Cell>{d.revoked_at ? <Badge tone="red">Withdrawn</Badge> : <Badge tone="green">Valid</Badge>}</Cell>
                      <Cell>
                        <div className="flex gap-2">
                          <a className="text-sm text-brand underline" href={`/api/backend/academic-documents/${d.id}/pdf`}>PDF</a>
                          {admin && !d.revoked_at && (
                            <button className="text-sm text-negative underline" onClick={() => {
                              const reason = window.prompt('Why is this document being withdrawn?');
                              if (reason) post(`/academic-documents/${d.id}/revoke`, { reason, reissue: window.confirm('Issue a corrected replacement now?') }, 'Withdrawn.');
                            }}>Withdraw</button>
                          )}
                        </div>
                      </Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          )}
        </>
      )}
    </PageShell>
  );
}
