'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Keeping the academic record right (TASK 04):
 *
 *  - Corrections a parent or student has reported in their record, to be
 *    resolved (and corrected through the record's own versioning) or
 *    declined with a reason.
 *  - Which classes have been closed into the permanent record for a term.
 *  - How class and subject names from other schools map onto this school's,
 *    for records received on transfer. The original names and scores are
 *    always kept beside the mapping.
 */

type Tab = 'requests' | 'closures' | 'mappings';
interface Req { id: number; student_id: number; student_name: string | null; admission_number: string | null; raised_by_name: string | null; description: string; status: string; resolution: string | null; created_at: string; resolved_at: string | null }
interface Closure { id: number; class_id: number | null; students: number; from_approved_broadsheet: boolean; created_at: string }
interface Mapping { id: number; kind: 'class' | 'subject'; external_name: string; local_id: number | null; mapping_type: string }

export default function RecordsPage() {
  const [tab, setTab] = useState<Tab>('requests');
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [classes, setClasses] = useState<{ id: number; name: string }[]>([]);
  const [subjects, setSubjects] = useState<{ id: number; name: string }[]>([]);
  const [terms, setTerms] = useState<{ id: number; name: string }[]>([]);

  useEffect(() => {
    fetchApi<{ data: { id: number; name: string }[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string }[] }>('/subjects').then((r) => setSubjects(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string }[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined);
  }, []);

  const done = useCallback((m: string) => { setNotice(m); setError(null); }, []);
  const fail = useCallback((e: unknown, f: string) => { setError(errorText(e, f)); setNotice(null); }, []);

  return (
    <PageShell title="Record requests" subtitle="Corrections families have asked for, term closures, and names from other schools.">
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['requests', 'Correction requests'], ['closures', 'Term closures'], ['mappings', 'Name mappings']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {tab === 'requests' && <Requests onDone={done} onError={fail} />}
      {tab === 'closures' && <Closures terms={terms} classes={classes} onError={fail} />}
      {tab === 'mappings' && <Mappings classes={classes} subjects={subjects} onDone={done} onError={fail} />}
    </PageShell>
  );
}

type H = { onDone: (m: string) => void; onError: (e: unknown, f: string) => void };

function Requests({ onDone, onError }: H) {
  const [status, setStatus] = useState('open');
  const [rows, setRows] = useState<Req[] | null>(null);

  const load = useCallback(async () => {
    try { setRows((await fetchApi<{ data: Req[] }>(`/academic-history/correction-requests${status ? `?status=${status}` : ''}`)).data); } catch (err) { onError(err, 'Could not load requests.'); }
  }, [status, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function resolve(r: Req, outcome: 'resolved' | 'declined') {
    const text = window.prompt(outcome === 'resolved' ? 'What was corrected? (the family can be told this)' : 'Why is this being declined?');
    if (!text || text.trim().length < 3) return;
    try {
      await fetchApi(`/academic-history/correction-requests/${r.id}/resolve`, { method: 'POST', body: JSON.stringify({ status: outcome, resolution: text.trim() }) });
      onDone(outcome === 'resolved' ? 'Marked resolved.' : 'Declined.');
      await load();
    } catch (err) { onError(err, 'Could not save.'); }
  }

  return (
    <Card description="Make the correction itself on the student’s record (Students, or Academic history for results), then mark the request resolved.">
      <div className="mb-4 max-w-xs"><Field label="Show"><select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value)}><option value="open">Open</option><option value="resolved">Resolved</option><option value="declined">Declined</option><option value="">All</option></select></Field></div>
      {!rows ? <Loading /> : rows.length === 0 ? <Empty>No requests.</Empty> : (
        <Table columns={['Student', 'Reported by', 'What is wrong', 'Status', '']}>
          {rows.map((r) => (
            <tr key={r.id}>
              <Cell className="font-medium">{r.student_name}<span className="block text-xs text-ink-muted">{r.admission_number}</span></Cell>
              <Cell>{r.raised_by_name}<span className="block text-xs text-ink-muted">{r.created_at?.slice(0, 10)}</span></Cell>
              <Cell className="max-w-md text-sm">{r.description}{r.resolution && <span className="block text-xs text-ink-muted">Reply: {r.resolution}</span>}</Cell>
              <Cell><Badge tone={r.status === 'resolved' ? 'green' : r.status === 'declined' ? 'slate' : 'amber'}>{r.status}</Badge></Cell>
              <Cell className="space-x-2 whitespace-nowrap">
                {r.status === 'open' && <>
                  <Button variant="secondary" onClick={() => resolve(r, 'resolved')}>Resolved</Button>
                  <Button variant="secondary" onClick={() => resolve(r, 'declined')}>Decline</Button>
                </>}
                <a className="text-sm text-brand underline" href={`/academic-history?student_id=${r.student_id}`}>Record</a>
              </Cell>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  );
}

function Closures({ terms, classes, onError }: { terms: { id: number; name: string }[]; classes: { id: number; name: string }[] } & Pick<H, 'onError'>) {
  const [termId, setTermId] = useState('');
  const [rows, setRows] = useState<Closure[] | null>(null);

  useEffect(() => {
    if (!termId) return;
    fetchApi<{ data: Closure[] }>(`/academic-history/closures?term_id=${termId}`).then((r) => setRows(r.data)).catch((err) => onError(err, 'Could not load closures.'));
  }, [termId, onError]);

  return (
    <Card description="A class’s term is closed into the permanent record from Academic history. Closed figures are frozen; later changes become new versions with a reason.">
      <div className="mb-4 max-w-xs"><Field label="Term"><select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)}><option value="">Choose…</option>{terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Field></div>
      {!termId ? <Empty>Choose a term.</Empty> : !rows ? <Loading /> : rows.length === 0 ? <Empty>No class has been closed for this term yet.</Empty> : (
        <Table columns={['Class', 'Students', 'From', 'Closed']}>
          {rows.map((c) => <tr key={c.id}><Cell className="font-medium">{classes.find((x) => x.id === c.class_id)?.name ?? '—'}</Cell><Cell>{c.students}</Cell><Cell>{c.from_approved_broadsheet ? <Badge tone="green">approved broadsheet</Badge> : <Badge tone="amber">unapproved figures</Badge>}</Cell><Cell className="text-xs">{c.created_at?.slice(0, 16).replace('T', ' ')}</Cell></tr>)}
        </Table>
      )}
    </Card>
  );
}

function Mappings({ classes, subjects, onDone, onError }: { classes: { id: number; name: string }[]; subjects: { id: number; name: string }[] } & H) {
  const [rows, setRows] = useState<Mapping[] | null>(null);
  const [types, setTypes] = useState<string[]>([]);
  const [f, setF] = useState({ kind: 'subject', external_name: '', local_id: '', mapping_type: 'equivalent' });

  const load = useCallback(async () => {
    try { const r = await fetchApi<{ data: Mapping[]; types: string[] }>('/academic-history/mappings'); setRows(r.data); setTypes(r.types); } catch (err) { onError(err, 'Could not load mappings.'); }
  }, [onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const local = f.kind === 'class' ? classes : subjects;
  const name = (m: Mapping) => (m.kind === 'class' ? classes : subjects).find((x) => x.id === m.local_id)?.name ?? '—';

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card description="When a transferred student’s records say “Basic Science” or “Primary 6”, what that is here.">
        {!rows ? <Loading /> : rows.length === 0 ? <Empty>No mappings yet.</Empty> : (
          <Table columns={['Their name', 'Kind', 'Ours', 'Match']}>
            {rows.map((m) => <tr key={m.id}><Cell className="font-medium">{m.external_name}</Cell><Cell className="capitalize">{m.kind}</Cell><Cell>{name(m)}</Cell><Cell><Badge>{m.mapping_type.replaceAll('_', ' ')}</Badge></Cell></tr>)}
          </Table>
        )}
      </Card>
      <Card title="Add or change a mapping">
        <form className="space-y-3" onSubmit={async (e) => {
          e.preventDefault();
          try {
            const r = await fetchApi<{ message: string }>('/academic-history/mappings', { method: 'PUT', body: JSON.stringify({ ...f, local_id: f.local_id ? Number(f.local_id) : null }) });
            onDone(r.message);
            setF({ ...f, external_name: '', local_id: '' });
            await load();
          } catch (err) { onError(err, 'Could not save the mapping.'); }
        }}>
          <Field label="Kind"><select className={inputClass} value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value, local_id: '' })}><option value="subject">Subject</option><option value="class">Class</option></select></Field>
          <Field label="Their name"><input className={inputClass} required maxLength={120} value={f.external_name} onChange={(e) => setF({ ...f, external_name: e.target.value })} /></Field>
          <Field label="Match"><select className={inputClass} value={f.mapping_type} onChange={(e) => setF({ ...f, mapping_type: e.target.value })}>{types.map((t) => <option key={t} value={t}>{t.replaceAll('_', ' ')}</option>)}</select></Field>
          {!['not_offered', 'needs_assessment'].includes(f.mapping_type) && <Field label="Ours"><select className={inputClass} value={f.local_id} onChange={(e) => setF({ ...f, local_id: e.target.value })}><option value="">Choose…</option>{local.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>}
          <Button type="submit">Save</Button>
        </form>
      </Card>
    </div>
  );
}
