'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import { useCookieRole } from '@/components/Sidebar';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * The transport office: which students ride the bus (and so pay the bus
 * fee), billing that fee onto their term invoices, and keeping each bus on
 * the road — services, repairs, fuel, breakdowns and its papers.
 *
 * Bus costs stay here; they are not posted to the school's expenses. Live
 * positions are on Bus tracking.
 */

interface Bus {
  id: number; bus_number: string; plate_number: string | null; capacity: number | null; driver_name: string; driver_phone: string;
  route_name: string | null; status: 'in_service' | 'off_road'; odometer_km: number | null; riders: number;
  last_service_on: string | null; next_service_on: string | null; next_service_km: number | null;
  next_document: { kind: string; expires_on: string; days_left: number } | null;
  fuel_cost_per_km: number | null; costs_this_year: number;
}
interface Rider { id: number; student_id: number; name: string | null; admission_number: string | null; class_name: string | null; bus_id: number | null; bus_number: string | null; pickup_point: string | null; started_on: string | null }
interface Log { id: number; kind: string; logged_on: string; description: string | null; garage: string | null; cost: string | null; odometer_km: number | null; litres: string | null; next_service_on: string | null; next_service_km: number | null; back_in_service_at: string | null }
interface Doc { id: number; bus_id: number; bus_number?: string | null; kind: string; reference: string | null; expires_on: string; days_left?: number; notes: string | null }
interface Charge { id: number; student_id: number; student_name: string | null; term_id: number; amount: string; status: string }

const TABS = [
  { key: 'fleet', label: 'Buses & upkeep' },
  { key: 'riders', label: 'Riders' },
  { key: 'fees', label: 'Bus fees' },
  { key: 'papers', label: 'Papers' },
] as const;
type Tab = (typeof TABS)[number]['key'];

const KIND_LABEL: Record<string, string> = { service: 'Service', repair: 'Repair', fuel: 'Fuel', breakdown: 'Breakdown' };
const DOC_LABEL: Record<string, string> = { insurance: 'Insurance', roadworthiness: 'Roadworthiness', vehicle_licence: 'Vehicle licence', driver_licence: "Driver's licence", other: 'Other' };
const day = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const today = () => new Date().toISOString().slice(0, 10);

export default function TransportPage() {
  const admin = useCookieRole() !== 'bursar';
  const [tab, setTab] = useState<Tab>('fleet');
  const [buses, setBuses] = useState<Bus[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const loadFleet = useCallback(async () => {
    try {
      setBuses((await fetchApi<{ data: Bus[] }>('/transport/fleet')).data);
    } catch (err) {
      setError(errorText(err, 'Could not load the buses.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await loadFleet();
    })();
  }, [loadFleet]);

  async function send<T>(path: string, method: 'POST' | 'PUT' | 'DELETE', body: unknown, done: string): Promise<T | null> {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const r = await fetchApi<T & { message?: string }>(path, { method, body: body === null ? undefined : JSON.stringify(body) });
      setNotice(r.message ?? done);
      return r;
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
      return null;
    } finally {
      setBusy(false);
    }
  }

  const offRoad = (buses ?? []).filter((b) => b.status === 'off_road').length;
  const papersSoon = (buses ?? []).filter((b) => b.next_document && b.next_document.days_left <= 30).length;
  const riders = (buses ?? []).reduce((t, b) => t + b.riders, 0);

  return (
    <PageShell
      title="Transport"
      subtitle="Who rides the bus and pays for it, and each bus's upkeep. Bus costs are kept here, separate from school expenses."
      actions={<Link href="/bus-tracking"><Button variant="secondary">Live bus tracking</Button></Link>}
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {buses && (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatTile label="Buses" value={buses.length} hint={offRoad ? `${offRoad} off the road` : 'all in service'} tone={offRoad ? 'red' : 'slate'} />
          <StatTile label="Riders" value={riders} hint="students on the bus list" />
          <StatTile label="Papers due" value={papersSoon} tone={papersSoon ? 'amber' : 'slate'} hint="expiring within 30 days" />
          <StatTile label="Bus costs this year" value={nairaAmount(buses.reduce((t, b) => t + b.costs_this_year, 0))} hint="fuel, services and repairs" />
        </div>
      )}

      <nav aria-label="Transport sections" className="mb-6 flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button key={t.key} type="button" aria-current={tab === t.key ? 'page' : undefined} onClick={() => setTab(t.key)}
            className={`rounded-full px-4 py-2 text-sm font-medium transition ${tab === t.key ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'}`}>
            {t.label}
          </button>
        ))}
      </nav>

      {tab === 'fleet' && <Fleet buses={buses} admin={admin} busy={busy} send={send} reload={loadFleet} />}
      {tab === 'riders' && <Riders buses={buses ?? []} admin={admin} busy={busy} send={send} reload={loadFleet} />}
      {tab === 'fees' && <Fees admin={admin} busy={busy} send={send} />}
      {tab === 'papers' && <Papers buses={buses ?? []} admin={admin} busy={busy} send={send} reload={loadFleet} />}
    </PageShell>
  );
}

type Send = <T>(path: string, method: 'POST' | 'PUT' | 'DELETE', body: unknown, done: string) => Promise<T | null>;

// ------------------------------------------------------------------ fleet & upkeep

function Fleet({ buses, admin, busy, send, reload }: { buses: Bus[] | null; admin: boolean; busy: boolean; send: Send; reload: () => Promise<void> }) {
  const [open, setOpen] = useState<number | null>(null);

  if (!buses) return <Loading />;
  if (buses.length === 0) return <Card><Empty>No buses yet. Register one on <Link className="underline" href="/bus-tracking">Bus tracking</Link>.</Empty></Card>;

  return (
    <div className="space-y-4">
      {buses.map((b) => (
        <Card key={b.id}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="font-headline text-lg font-semibold text-brand">
                {b.bus_number}{b.plate_number && <span className="ml-2 text-sm font-normal text-ink-muted">{b.plate_number}</span>}
              </h2>
              <p className="text-sm text-ink-muted">
                {[b.route_name, `${b.driver_name} · ${b.driver_phone}`, `${b.riders}${b.capacity ? ` / ${b.capacity}` : ''} riders`, b.odometer_km != null && `${b.odometer_km.toLocaleString('en-NG')} km`].filter(Boolean).join(' · ')}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={b.status === 'off_road' ? 'red' : 'green'}>{b.status === 'off_road' ? 'Off the road' : 'In service'}</Badge>
              {b.status === 'off_road' && admin && (
                <Button disabled={busy} onClick={() => { const tell = window.confirm('Bus back in service. Tell the parents of its riders?'); void send(`/transport/buses/${b.id}/back-in-service`, 'POST', { tell_parents: tell }, 'Back in service.').then(() => reload()); }}>Back in service</Button>
              )}
              <Button variant="secondary" onClick={() => setOpen(open === b.id ? null : b.id)}>{open === b.id ? 'Close' : 'Upkeep record'}</Button>
            </div>
          </div>
          <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-4">
            <div><dt className="text-xs text-ink-faint">Last service</dt><dd>{day(b.last_service_on)}</dd></div>
            <div><dt className="text-xs text-ink-faint">Next service</dt><dd>{[b.next_service_on && day(b.next_service_on), b.next_service_km && `${b.next_service_km.toLocaleString('en-NG')} km`].filter(Boolean).join(' or ') || '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Papers next due</dt><dd className={b.next_document && b.next_document.days_left <= 30 ? 'font-semibold text-negative' : ''}>{b.next_document ? `${DOC_LABEL[b.next_document.kind]} · ${b.next_document.days_left < 0 ? 'expired' : `${b.next_document.days_left} days`}` : '—'}</dd></div>
            <div><dt className="text-xs text-ink-faint">Fuel cost</dt><dd>{b.fuel_cost_per_km != null ? `${nairaAmount(b.fuel_cost_per_km)} per km` : '—'}</dd></div>
          </dl>
          {open === b.id && <Upkeep bus={b} admin={admin} busy={busy} send={send} reload={reload} />}
        </Card>
      ))}
    </div>
  );
}

const blankLog = { kind: 'service', logged_on: today(), description: '', garage: '', cost: '', odometer_km: '', litres: '', next_service_on: '', next_service_km: '', tell_parents: true };

function Upkeep({ bus, admin, busy, send, reload }: { bus: Bus; admin: boolean; busy: boolean; send: Send; reload: () => Promise<void> }) {
  const [logs, setLogs] = useState<Log[] | null>(null);
  const [form, setForm] = useState(blankLog);

  const load = useCallback(async () => {
    try {
      setLogs((await fetchApi<{ data: Log[] }>(`/transport/buses/${bus.id}/logs`)).data);
    } catch {
      setLogs([]);
    }
  }, [bus.id]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    const num = (v: string) => (v === '' ? null : Number(v));
    const r = await send('/transport/buses/' + bus.id + '/logs', 'POST', {
      kind: form.kind, logged_on: form.logged_on, description: form.description || null, garage: form.garage || null,
      cost: num(form.cost), odometer_km: num(form.odometer_km), litres: num(form.litres),
      next_service_on: form.next_service_on || null, next_service_km: num(form.next_service_km), tell_parents: form.tell_parents,
    }, 'Saved.');
    if (r) {
      setForm({ ...blankLog, kind: form.kind });
      await load();
      await reload();
    }
  }

  const k = form.kind;

  return (
    <div className="mt-5 grid gap-5 border-t border-line-soft pt-5 xl:grid-cols-[1fr_22rem]">
      <div>
        {!logs ? <Loading /> : logs.length === 0 ? <Empty>Nothing recorded for this bus yet.</Empty> : (
          <Table columns={['Date', 'What', 'Cost', 'Odometer', '']}>
            {logs.map((l) => (
              <tr key={l.id}>
                <Cell>{day(l.logged_on)}</Cell>
                <Cell>
                  <Badge tone={l.kind === 'breakdown' ? 'red' : l.kind === 'fuel' ? 'blue' : 'slate'}>{KIND_LABEL[l.kind]}</Badge>
                  <span className="mt-1 block text-sm">{l.description ?? (l.litres ? `${Number(l.litres)} litres` : '')}{l.garage && <span className="text-ink-muted"> · {l.garage}</span>}</span>
                  {l.kind === 'service' && (l.next_service_on || l.next_service_km) && <span className="block text-xs text-ink-muted">Next: {[l.next_service_on && day(l.next_service_on), l.next_service_km && `${l.next_service_km.toLocaleString('en-NG')} km`].filter(Boolean).join(' or ')}</span>}
                  {l.kind === 'breakdown' && <span className="block text-xs text-ink-muted">{l.back_in_service_at ? `Back ${day(l.back_in_service_at)}` : 'Still off the road'}</span>}
                </Cell>
                <Cell>{l.cost ? nairaAmount(Number(l.cost)) : '—'}</Cell>
                <Cell>{l.odometer_km != null ? `${l.odometer_km.toLocaleString('en-NG')} km` : '—'}</Cell>
                <Cell>{admin && <button type="button" className="text-xs text-negative underline" disabled={busy} onClick={() => { if (window.confirm('Remove this entry?')) void send(`/transport/logs/${l.id}`, 'DELETE', null, 'Removed.').then(() => load()); }}>Remove</button>}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </div>
      {admin && (
        <form className="space-y-3" onSubmit={(e) => void save(e)}>
          <p className="text-sm font-semibold">Add to the record</p>
          <div className="grid grid-cols-2 gap-3">
            <Field label="What">
              <select className={inputClass} value={k} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                {Object.entries(KIND_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
            <Field label="Date"><input type="date" className={inputClass} max={today()} required value={form.logged_on} onChange={(e) => setForm({ ...form, logged_on: e.target.value })} /></Field>
          </div>
          <Field label={k === 'breakdown' ? 'What happened' : k === 'fuel' ? 'Note (optional)' : 'Work done'}>
            <input className={inputClass} maxLength={500} required={k !== 'fuel'} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder={k === 'breakdown' ? 'e.g. Gearbox failed on the way back' : k === 'service' ? 'e.g. Oil and filters' : ''} />
          </Field>
          {k !== 'breakdown' && k !== 'fuel' && <Field label="Garage (optional)"><input className={inputClass} maxLength={120} value={form.garage} onChange={(e) => setForm({ ...form, garage: e.target.value })} /></Field>}
          <div className="grid grid-cols-2 gap-3">
            <Field label={k === 'fuel' ? 'Cost (₦)' : 'Cost (₦, optional)'}><input type="number" min={0} className={inputClass} required={k === 'fuel'} value={form.cost} onChange={(e) => setForm({ ...form, cost: e.target.value })} /></Field>
            <Field label="Odometer (km)"><input type="number" min={0} className={inputClass} value={form.odometer_km} onChange={(e) => setForm({ ...form, odometer_km: e.target.value })} /></Field>
          </div>
          {k === 'fuel' && <Field label="Litres"><input type="number" min={0.1} step={0.1} className={inputClass} required value={form.litres} onChange={(e) => setForm({ ...form, litres: e.target.value })} /></Field>}
          {k === 'service' && (
            <div className="grid grid-cols-2 gap-3">
              <Field label="Next service by"><input type="date" className={inputClass} value={form.next_service_on} onChange={(e) => setForm({ ...form, next_service_on: e.target.value })} /></Field>
              <Field label="or at (km)"><input type="number" min={1} className={inputClass} value={form.next_service_km} onChange={(e) => setForm({ ...form, next_service_km: e.target.value })} /></Field>
            </div>
          )}
          {k === 'breakdown' && (
            <label className="flex items-start gap-2 text-sm">
              <input type="checkbox" className="mt-1" checked={form.tell_parents} onChange={(e) => setForm({ ...form, tell_parents: e.target.checked })} />
              <span>Tell the parents of this bus&apos;s riders<span className="block text-xs text-ink-muted">So nobody waits at a pickup point for a bus that isn&apos;t coming.</span></span>
            </label>
          )}
          <Button type="submit" variant={k === 'breakdown' ? 'danger' : 'primary'} disabled={busy}>{k === 'breakdown' ? 'Mark off the road' : 'Save'}</Button>
        </form>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ riders

function Riders({ buses, admin, busy, send, reload }: { buses: Bus[]; admin: boolean; busy: boolean; send: Send; reload: () => Promise<void> }) {
  const [rows, setRows] = useState<Rider[] | null>(null);
  const [busFilter, setBusFilter] = useState('');
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [busId, setBusId] = useState('');
  const [pickup, setPickup] = useState('');

  const load = useCallback(async () => {
    try {
      setRows((await fetchApi<{ data: Rider[] }>(`/transport/riders${busFilter ? `?bus_id=${busFilter}` : ''}`)).data);
    } catch {
      setRows([]);
    }
  }, [busFilter]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    if (!student) return;
    const r = await send('/transport/riders', 'POST', { student_id: student.id, bus_id: busId ? Number(busId) : null, pickup_point: pickup || null }, 'Added.');
    if (r) {
      setStudent(null);
      setPickup('');
      await load();
      await reload();
    }
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_22rem]">
      <Card>
        <div className="mb-4 max-w-xs">
          <Field label="Bus">
            <select className={inputClass} value={busFilter} onChange={(e) => setBusFilter(e.target.value)}>
              <option value="">All buses</option>
              {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number}</option>)}
            </select>
          </Field>
        </div>
        {!rows ? <Loading /> : rows.length === 0 ? <Empty>No students on the bus list.</Empty> : (
          <Table columns={['Student', 'Bus', 'Pickup point', '']}>
            {rows.map((r) => (
              <tr key={r.id}>
                <Cell className="font-medium">{r.name}<span className="block text-xs text-ink-muted">{[r.admission_number, r.class_name, r.started_on && `since ${day(r.started_on)}`].filter(Boolean).join(' · ')}</span></Cell>
                <Cell>
                  {admin ? (
                    <select aria-label={`Bus for ${r.name}`} className={`${inputClass} py-1`} value={r.bus_id ?? ''} disabled={busy} onChange={(e) => void send(`/transport/riders/${r.id}`, 'PUT', { bus_id: e.target.value ? Number(e.target.value) : null }, 'Moved.').then(() => Promise.all([load(), reload()]))}>
                      <option value="">Not assigned</option>
                      {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number}</option>)}
                    </select>
                  ) : (r.bus_number ?? '—')}
                </Cell>
                <Cell>{r.pickup_point ?? '—'}</Cell>
                <Cell>{admin && <Button variant="secondary" disabled={busy} onClick={() => { if (window.confirm(`Take ${r.name} off the bus? They won't be billed for later terms.`)) void send(`/transport/riders/${r.id}/end`, 'POST', {}, 'Removed.').then(() => Promise.all([load(), reload()])); }}>Stop riding</Button>}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      {admin && (
        <Card title="Add a rider" description="Only students on this list pay the bus fee.">
          <form className="space-y-3" onSubmit={(e) => void add(e)}>
            <Field label="Student"><StudentPicker value={student} onChange={setStudent} /></Field>
            <Field label="Bus">
              <select className={inputClass} value={busId} onChange={(e) => setBusId(e.target.value)}>
                <option value="">Not assigned yet</option>
                {buses.map((b) => <option key={b.id} value={b.id} disabled={!!b.capacity && b.riders >= b.capacity}>{b.bus_number}{b.capacity ? ` (${b.riders}/${b.capacity})` : ''}</option>)}
              </select>
            </Field>
            <Field label="Pickup point (optional)"><input className={inputClass} maxLength={120} value={pickup} onChange={(e) => setPickup(e.target.value)} placeholder="A landmark, e.g. Ikeja under-bridge" /></Field>
            <Button type="submit" disabled={busy || !student}>Add to the bus</Button>
          </form>
        </Card>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ fees

function Fees({ admin, busy, send }: { admin: boolean; busy: boolean; send: Send }) {
  const [fee, setFee] = useState('');
  const [saved, setSaved] = useState<number | null>(null);
  const [terms, setTerms] = useState<{ id: number; name: string; session_name?: string | null }[]>([]);
  const [termId, setTermId] = useState('');
  const [charges, setCharges] = useState<Charge[] | null>(null);

  useEffect(() => {
    fetchApi<{ termly_fee: string }>('/transport/settings').then((s) => { setSaved(Number(s.termly_fee)); setFee(String(Number(s.termly_fee) || '')); }).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string; session_name?: string | null }[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined);
  }, []);

  const loadCharges = useCallback(async () => {
    try {
      setCharges((await fetchApi<{ data: Charge[] }>(`/transport/charges${termId ? `?term_id=${termId}` : ''}`)).data);
    } catch {
      setCharges([]);
    }
  }, [termId]);

  useEffect(() => {
    void (async () => {
      await loadCharges();
    })();
  }, [loadCharges]);

  const live = (charges ?? []).filter((c) => c.status !== 'void');
  const pending = live.filter((c) => c.status === 'pending').length;

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_22rem]">
      <Card>
        <div className="mb-4 max-w-xs">
          <Field label="Term">
            <select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)}>
              <option value="">All terms</option>
              {terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}
            </select>
          </Field>
        </div>
        {!charges ? <Loading /> : (
          <>
            <div className="mb-4 grid gap-4 sm:grid-cols-2">
              <StatTile label="Bus fees charged" value={nairaAmount(live.reduce((t, c) => t + Number(c.amount), 0))} hint={`${live.length} student(s)`} />
              <StatTile label="Not yet on an invoice" value={pending} tone={pending ? 'amber' : 'slate'} hint={pending ? 'generate the term invoices, then bill again' : undefined} />
            </div>
            {charges.length === 0 ? <Empty>No bus fees billed yet.</Empty> : (
              <Table columns={['Student', 'Amount', 'Status']}>
                {charges.map((c) => (
                  <tr key={c.id}>
                    <Cell className="font-medium">{c.student_name ?? `#${c.student_id}`}</Cell>
                    <Cell>{nairaAmount(Number(c.amount))}</Cell>
                    <Cell><Badge tone={c.status === 'posted' ? 'green' : c.status === 'pending' ? 'amber' : 'slate'}>{c.status === 'posted' ? 'on invoice' : c.status}</Badge></Cell>
                  </tr>
                ))}
              </Table>
            )}
          </>
        )}
      </Card>
      <Card title="Bus fee">
        <div className="space-y-4">
          {admin ? (
            <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); void send<{ termly_fee: string }>('/transport/settings', 'PUT', { termly_fee: Number(fee) }, 'Saved.').then((r) => r && setSaved(Number(r.termly_fee))); }}>
              <Field label="Termly bus fee per rider (₦)"><input type="number" min={0} className={inputClass} value={fee} onChange={(e) => setFee(e.target.value)} /></Field>
              <p className="text-xs text-ink-muted">The same for every rider. Students not on the bus list are never charged.</p>
              <Button type="submit" variant="secondary" disabled={busy}>Save fee</Button>
            </form>
          ) : <p className="text-sm">Termly bus fee: <b>{saved ? nairaAmount(saved) : 'not set'}</b></p>}
          <div className="border-t border-line-soft pt-4">
            <p className="mb-1 text-sm font-semibold">Bill a term</p>
            <p className="mb-2 text-xs text-ink-muted">Adds the bus fee to each rider&apos;s term invoice. Safe to run again: nobody is billed twice.</p>
            <Button disabled={busy || !termId || !saved} onClick={() => { if (window.confirm('Add this term’s bus fee to every rider’s invoice?')) void send('/transport/bill', 'POST', { term_id: Number(termId) }, 'Billed.').then(() => loadCharges()); }}>
              Bill {terms.find((t) => String(t.id) === termId)?.name ?? 'the chosen term'}
            </Button>
            {!termId && <p className="mt-1 text-xs text-ink-muted">Choose the term on the left first.</p>}
          </div>
        </div>
      </Card>
    </div>
  );
}

// ------------------------------------------------------------------ papers

function Papers({ buses, admin, busy, send, reload }: { buses: Bus[]; admin: boolean; busy: boolean; send: Send; reload: () => Promise<void> }) {
  const [docs, setDocs] = useState<Doc[] | null>(null);
  const [form, setForm] = useState({ bus_id: '', kind: 'insurance', reference: '', expires_on: '' });

  const load = useCallback(async () => {
    try {
      setDocs((await fetchApi<{ data: Doc[] }>('/transport/documents')).data);
    } catch {
      setDocs([]);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const r = await send(`/transport/buses/${form.bus_id}/documents`, 'POST', { kind: form.kind, reference: form.reference || null, expires_on: form.expires_on }, 'Saved.');
    if (r) {
      setForm({ ...form, reference: '', expires_on: '' });
      await load();
      await reload();
    }
  }

  function renew(d: Doc) {
    const next = window.prompt(`New expiry date for ${DOC_LABEL[d.kind]} on ${d.bus_number} (YYYY-MM-DD):`, '');
    if (!next) return;
    void send(`/transport/documents/${d.id}`, 'PUT', { expires_on: next }, 'Renewed.').then(() => Promise.all([load(), reload()]));
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_22rem]">
      <Card description="Admins are reminded before anything expires.">
        {!docs ? <Loading /> : docs.length === 0 ? <Empty>No papers recorded yet.</Empty> : (
          <Table columns={['Bus', 'Paper', 'Expires', '']}>
            {docs.map((d) => {
              const left = d.days_left ?? 0;
              return (
                <tr key={d.id}>
                  <Cell className="font-medium">{d.bus_number}</Cell>
                  <Cell>{DOC_LABEL[d.kind]}{d.reference && <span className="block text-xs text-ink-muted">{d.reference}</span>}</Cell>
                  <Cell>
                    {day(d.expires_on)}
                    <span className={`block text-xs ${left < 0 ? 'font-semibold text-negative' : left <= 30 ? 'text-caution' : 'text-ink-muted'}`}>{left < 0 ? `expired ${-left} days ago` : `${left} days left`}</span>
                  </Cell>
                  <Cell>{admin && (
                    <div className="flex gap-2">
                      <Button variant="secondary" disabled={busy} onClick={() => renew(d)}>Renewed</Button>
                      <button type="button" className="text-xs text-negative underline" onClick={() => { if (window.confirm('Remove this paper?')) void send(`/transport/documents/${d.id}`, 'DELETE', null, 'Removed.').then(() => load()); }}>Remove</button>
                    </div>
                  )}</Cell>
                </tr>
              );
            })}
          </Table>
        )}
      </Card>
      {admin && (
        <Card title="Add a paper">
          <form className="space-y-3" onSubmit={(e) => void add(e)}>
            <Field label="Bus">
              <select className={inputClass} required value={form.bus_id} onChange={(e) => setForm({ ...form, bus_id: e.target.value })}>
                <option value="">Choose a bus</option>
                {buses.map((b) => <option key={b.id} value={b.id}>{b.bus_number}</option>)}
              </select>
            </Field>
            <Field label="Paper">
              <select className={inputClass} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                {Object.entries(DOC_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
            <Field label="Number / reference (optional)"><input className={inputClass} maxLength={80} value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} /></Field>
            <Field label="Expires on"><input type="date" className={inputClass} required value={form.expires_on} onChange={(e) => setForm({ ...form, expires_on: e.target.value })} /></Field>
            <Button type="submit" disabled={busy}>Save</Button>
          </form>
        </Card>
      )}
    </div>
  );
}
