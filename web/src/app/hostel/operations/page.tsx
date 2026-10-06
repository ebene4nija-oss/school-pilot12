'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * Hostel operations (TASK 17), alongside the main Hostel page: arrivals,
 * the movement log and its corrections, the duty roster, room inspections,
 * the inventory of issued items, incident follow-up, announcements, and one
 * boarder's whole file. Who may do what is decided per hostel by the API
 * (manager, warden, house parent); a refusal says so.
 *
 * Movements are never edited — a mistake is fixed by a correction that keeps
 * the original and says why.
 */

type Tab = 'arrivals' | 'movements' | 'roster' | 'inspections' | 'inventory' | 'incidents' | 'announce' | 'boarder' | 'staff';
interface Hostel { id: number; name: string }
interface Room { id: number; hostel_id: number; hostel: string; name: string }
interface Movement { id: number; student_id: number; student_name: string | null; type: string; occurred_at: string; notes: string | null; corrects_id: number | null; correction_reason: string | null }
interface Shift { id: number; hostel_id: number; name: string | null; date: string; shift: string; published: boolean; handover_notes: string | null }
interface Inspection { id: number; room_id: number; inspected_at: string; result: string; notes: string | null }
interface Asset { id: number; name: string; code: string | null; quantity: number; condition: string; issuable: boolean; issued: number; open_issues: { id: number; quantity: number; issued_at: string; student_name: string }[] }
interface Incident { id: number; reference: string; title?: string; category?: string; status: string; occurred_at?: string; severity?: string }

const when = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const monday = () => { const d = new Date(); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return d.toISOString().slice(0, 10); };

export default function HostelOperations() {
  const [tab, setTab] = useState<Tab>('arrivals');
  const [hostels, setHostels] = useState<Hostel[]>([]);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [data, setData] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // per-tab inputs
  const [from, setFrom] = useState(monday());
  const [shift, setShift] = useState({ hostel_id: '', user_id: '', date: '', shift: 'night', handover_notes: '' });
  const [staffUsers, setStaffUsers] = useState<{ user_id: number; name: string }[]>([]);
  const [inspect, setInspect] = useState({ room_id: '', result: 'pass', notes: '' });
  const [asset, setAsset] = useState({ hostel_id: '', name: '', code: '', quantity: '1', condition: 'good', issuable: true });
  const [issueTo, setIssueTo] = useState<Record<number, PickedStudent | null>>({});
  const [announce, setAnnounce] = useState({ audience: 'residents', hostel_id: '', title: '', body: '' });
  const [boarder, setBoarder] = useState<PickedStudent | null>(null);
  const [incident, setIncident] = useState<{ incident: Incident & Record<string, unknown>; people: { role: string; name: string | null }[]; history: { action: string; reason: string | null; created_at: string }[] } | null>(null);

  useEffect(() => {
    fetchApi<{ data: Hostel[] }>('/hostel/hostels').then((r) => setHostels(r.data ?? [])).catch(() => undefined);
    fetchApi<{ rooms: Room[] }>('/hostel/structure').then((r) => setRooms(r.rooms ?? [])).catch(() => undefined);
    fetchApi<{ data: { user_id: number; name: string }[] }>('/hostel/staff').then((r) => setStaffUsers(r.data ?? [])).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    setError(null);
    setData(null);
    try {
      if (tab === 'arrivals') setData((await fetchApi<{ data: unknown }>('/hostel/arrivals')).data);
      if (tab === 'movements') setData((await fetchApi<{ data: unknown }>('/hostel/movements')).data);
      if (tab === 'roster') setData((await fetchApi<{ data: unknown }>(`/hostel/roster?from=${from}`)).data);
      if (tab === 'inspections') setData((await fetchApi<{ data: unknown }>('/hostel/inspections')).data);
      if (tab === 'inventory') setData((await fetchApi<{ data: unknown }>('/hostel/assets')).data);
      if (tab === 'incidents') setData((await fetchApi<{ data: unknown }>('/hostel/incidents')).data);
      if (tab === 'staff') setData((await fetchApi<{ data: unknown }>('/hostel/staff')).data);
      if (tab === 'boarder' && boarder) setData(await fetchApi(`/hostel/students/${boarder.id}`));
    } catch (err) {
      setError(errorText(err, 'Could not load this.'));
      setData([]);
    }
  }, [tab, from, boarder]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function act(path: string, method: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const r = await fetchApi<{ message?: string; warning?: string | null }>(path, { method, body: JSON.stringify(body) });
      setNotice([r.message ?? done, r.warning].filter(Boolean).join(' '));
      await load();
      return true;
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
      return false;
    } finally {
      setBusy(false);
    }
  }

  const roomName = (id: number) => rooms.find((r) => r.id === id)?.name ?? `Room ${id}`;
  const rows = <T,>() => (Array.isArray(data) ? (data as T[]) : null);

  return (
    <PageShell title="Hostel operations" subtitle="Day-to-day boarding house work." actions={<Link href="/hostel" className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium hover:bg-surface-sunken">Hostel overview</Link>}>
      <nav className="mb-5 flex flex-wrap gap-2">
        {([['arrivals', 'Arrivals'], ['movements', 'Movement log'], ['roster', 'Duty roster'], ['inspections', 'Inspections'], ['inventory', 'Inventory'], ['incidents', 'Incidents'], ['announce', 'Announce'], ['boarder', 'Boarder file'], ['staff', 'Hostel staff']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setIncident(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {tab === 'arrivals' && (!rows() ? <Loading /> : rows<{ student_id: number; name: string; expected_arrival: string | null; presence: string | null }>()!.length === 0 ? <Empty>No boarders expected.</Empty> : (
        <Card title="Expected arrivals" description="Approved boarders and whether they have checked in. Check them in from the main Hostel page.">
          <Table columns={['Boarder', 'Expected', 'Now']}>
            {rows<{ student_id: number; name: string; expected_arrival: string | null; presence: string | null }>()!.map((r) => <tr key={r.student_id}><Cell className="font-medium">{r.name}</Cell><Cell>{r.expected_arrival ?? '—'}</Cell><Cell><Badge tone={r.presence === 'in_hostel' ? 'green' : 'amber'}>{(r.presence ?? 'not arrived').replaceAll('_', ' ')}</Badge></Cell></tr>)}
          </Table>
        </Card>
      ))}

      {tab === 'movements' && (!rows() ? <Loading /> : (
        <Card title="Movement log" description="Every check-in, departure and return. To fix a wrong time, record a correction — the original stays.">
          {rows<Movement>()!.length === 0 ? <Empty>No movements yet.</Empty> : (
            <Table columns={['When', 'Boarder', 'Event', 'Notes', '']}>
              {rows<Movement>()!.map((m) => (
                <tr key={m.id}>
                  <Cell className="text-xs">{when(m.occurred_at)}</Cell>
                  <Cell className="font-medium">{m.student_name}</Cell>
                  <Cell>{m.type.replaceAll('_', ' ')}{m.corrects_id && <Badge tone="amber">correction</Badge>}</Cell>
                  <Cell className="text-xs text-ink-muted">{m.correction_reason ?? m.notes}</Cell>
                  <Cell>{!m.corrects_id && <button type="button" className="text-sm text-brand underline" onClick={() => {
                    const at = window.prompt('The correct date and time (YYYY-MM-DD HH:MM):', m.occurred_at.slice(0, 16).replace('T', ' '));
                    if (!at) return;
                    const reason = window.prompt('Why is it being corrected?');
                    if (reason && reason.trim().length >= 3) void act(`/hostel/movements/${m.id}/correct`, 'POST', { occurred_at: at, reason: reason.trim() }, 'Correction recorded.');
                  }}>Correct</button>}</Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      ))}

      {tab === 'roster' && (
        <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
          <Card title="Two weeks of duty">
            <div className="mb-3 max-w-xs"><Field label="Starting"><input className={inputClass} type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field></div>
            {!rows() ? <Loading /> : rows<Shift>()!.length === 0 ? <Empty>No shifts planned.</Empty> : (
              <Table columns={['Date', 'Shift', 'Hostel', 'On duty', '']}>
                {rows<Shift>()!.map((s) => <tr key={s.id}><Cell>{s.date.slice(0, 10)}</Cell><Cell className="capitalize">{s.shift}</Cell><Cell>{hostels.find((h) => h.id === s.hostel_id)?.name}</Cell><Cell>{s.name}{s.handover_notes && <span className="block text-xs text-ink-muted">{s.handover_notes}</span>}</Cell><Cell>{s.published ? <Badge tone="green">published</Badge> : <Badge>draft</Badge>}</Cell></tr>)}
              </Table>
            )}
            {hostels.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {hostels.map((h) => <Button key={h.id} variant="secondary" disabled={busy} onClick={() => act('/hostel/roster/publish', 'POST', { hostel_id: h.id, from, to: new Date(new Date(from).getTime() + 13 * 864e5).toISOString().slice(0, 10) }, 'Published.')}>Publish {h.name}</Button>)}
              </div>
            )}
          </Card>
          <Card title="Add a shift" description="Managers plan the roster.">
            <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void act('/hostel/roster', 'POST', { ...shift, hostel_id: Number(shift.hostel_id), user_id: Number(shift.user_id), handover_notes: shift.handover_notes || null }, 'Shift saved.'); }}>
              <Field label="Hostel"><select className={inputClass} required value={shift.hostel_id} onChange={(e) => setShift({ ...shift, hostel_id: e.target.value })}><option value="">Choose…</option>{hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></Field>
              <Field label="Who"><select className={inputClass} required value={shift.user_id} onChange={(e) => setShift({ ...shift, user_id: e.target.value })}><option value="">Choose…</option>{[...new Map(staffUsers.map((u) => [u.user_id, u])).values()].map((u) => <option key={u.user_id} value={u.user_id}>{u.name}</option>)}</select></Field>
              <Field label="Date"><input className={inputClass} type="date" required value={shift.date} onChange={(e) => setShift({ ...shift, date: e.target.value })} /></Field>
              <Field label="Shift"><select className={inputClass} value={shift.shift} onChange={(e) => setShift({ ...shift, shift: e.target.value })}>{['morning', 'afternoon', 'evening', 'night'].map((s) => <option key={s}>{s}</option>)}</select></Field>
              <Field label="Handover notes"><textarea className={inputClass} value={shift.handover_notes} onChange={(e) => setShift({ ...shift, handover_notes: e.target.value })} /></Field>
              <Button type="submit" disabled={busy}>Save shift</Button>
            </form>
          </Card>
        </div>
      )}

      {tab === 'inspections' && (
        <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
          <Card title="Recent inspections">
            {!rows() ? <Loading /> : rows<Inspection>()!.length === 0 ? <Empty>No rooms inspected yet.</Empty> : (
              <Table columns={['When', 'Room', 'Result', 'Notes']}>
                {rows<Inspection>()!.map((i) => <tr key={i.id}><Cell className="text-xs">{when(i.inspected_at)}</Cell><Cell>{roomName(i.room_id)}</Cell><Cell><Badge tone={i.result === 'pass' ? 'green' : i.result === 'attention' ? 'amber' : 'red'}>{i.result}</Badge></Cell><Cell className="text-sm">{i.notes}</Cell></tr>)}
              </Table>
            )}
          </Card>
          <Card title="Inspect a room">
            <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void act(`/hostel/rooms/${inspect.room_id}/inspections`, 'POST', { result: inspect.result, notes: inspect.notes || null }, 'Inspection recorded.').then((ok) => ok && setInspect({ ...inspect, notes: '' })); }}>
              <Field label="Room"><select className={inputClass} required value={inspect.room_id} onChange={(e) => setInspect({ ...inspect, room_id: e.target.value })}><option value="">Choose…</option>{rooms.map((r) => <option key={r.id} value={r.id}>{r.hostel} · {r.name}</option>)}</select></Field>
              <Field label="Result"><select className={inputClass} value={inspect.result} onChange={(e) => setInspect({ ...inspect, result: e.target.value })}><option value="pass">Pass</option><option value="attention">Needs attention</option><option value="fail">Fail</option></select></Field>
              <Field label="Notes"><textarea className={inputClass} value={inspect.notes} onChange={(e) => setInspect({ ...inspect, notes: e.target.value })} /></Field>
              <Button type="submit" disabled={busy}>Record</Button>
            </form>
          </Card>
        </div>
      )}

      {tab === 'inventory' && (
        <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
          <Card title="Items">
            {!rows() ? <Loading /> : rows<Asset>()!.length === 0 ? <Empty>No items recorded.</Empty> : (
              <ul className="divide-y divide-line-soft">
                {rows<Asset>()!.map((a) => (
                  <li key={a.id} className="py-3 text-sm">
                    <p><span className="font-medium">{a.name}</span> {a.code && <span className="font-mono text-xs">{a.code}</span>} · {a.quantity - a.issued} of {a.quantity} in store <Badge tone={a.condition === 'good' ? 'green' : 'amber'}>{a.condition}</Badge></p>
                    {a.open_issues.length > 0 && (
                      <ul className="mt-1 space-y-1 pl-4 text-xs">
                        {a.open_issues.map((i) => (
                          <li key={i.id}>{i.student_name} ×{i.quantity} since {i.issued_at?.slice(0, 10)}{' '}
                            {(['returned', 'lost', 'damaged'] as const).map((st) => <button key={st} type="button" className="ml-1 underline" onClick={() => act(`/hostel/asset-issues/${i.id}/return`, 'POST', { status: st }, `Marked ${st}.`)}>{st}</button>)}
                          </li>
                        ))}
                      </ul>
                    )}
                    {a.issuable && a.issued < a.quantity && (
                      <div className="mt-2 flex max-w-md items-end gap-2">
                        <div className="flex-1"><StudentPicker value={issueTo[a.id] ?? null} onChange={(s) => setIssueTo({ ...issueTo, [a.id]: s })} placeholder="Issue to…" /></div>
                        <Button variant="secondary" disabled={busy || !issueTo[a.id]} onClick={() => act(`/hostel/assets/${a.id}/issue`, 'POST', { student_id: issueTo[a.id]?.id }, 'Issued.').then((ok) => ok && setIssueTo({ ...issueTo, [a.id]: null }))}>Issue</Button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Add an item">
            <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void act('/hostel/assets', 'POST', { ...asset, hostel_id: asset.hostel_id ? Number(asset.hostel_id) : null, quantity: Number(asset.quantity), code: asset.code || null }, 'Item added.').then((ok) => ok && setAsset({ ...asset, name: '', code: '', quantity: '1' })); }}>
              <Field label="Hostel"><select className={inputClass} value={asset.hostel_id} onChange={(e) => setAsset({ ...asset, hostel_id: e.target.value })}><option value="">All</option>{hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></Field>
              <Field label="Item"><input className={inputClass} required maxLength={120} value={asset.name} onChange={(e) => setAsset({ ...asset, name: e.target.value })} placeholder="Mattress" /></Field>
              <Field label="Code (optional)"><input className={inputClass} maxLength={40} value={asset.code} onChange={(e) => setAsset({ ...asset, code: e.target.value })} /></Field>
              <Field label="How many"><input className={inputClass} type="number" min={1} value={asset.quantity} onChange={(e) => setAsset({ ...asset, quantity: e.target.value })} /></Field>
              <Field label="Condition"><select className={inputClass} value={asset.condition} onChange={(e) => setAsset({ ...asset, condition: e.target.value })}>{['good', 'fair', 'poor', 'damaged'].map((c) => <option key={c}>{c}</option>)}</select></Field>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={asset.issuable} onChange={(e) => setAsset({ ...asset, issuable: e.target.checked })} /> Issued to boarders</label>
              <Button type="submit" disabled={busy}>Add</Button>
            </form>
          </Card>
        </div>
      )}

      {tab === 'incidents' && (
        <div className="grid gap-5 lg:grid-cols-[1fr_24rem]">
          <Card title="Incidents" description="Logged on the main Hostel page; followed up here.">
            {!rows() ? <Loading /> : rows<Incident>()!.length === 0 ? <Empty>No incidents.</Empty> : (
              <ul className="divide-y divide-line-soft text-sm">
                {rows<Incident>()!.map((i) => (
                  <li key={i.id} className="flex justify-between gap-2 py-2">
                    <span><span className="font-mono text-xs">{i.reference}</span> {i.title ?? i.category} <Badge tone={['resolved', 'closed'].includes(i.status) ? 'green' : 'amber'}>{i.status.replaceAll('_', ' ')}</Badge></span>
                    <button type="button" className="text-brand underline" onClick={() => fetchApi<typeof incident>(`/hostel/incidents/${i.id}`).then(setIncident).catch((err) => setError(errorText(err, 'Could not open it.')))}>Open</button>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {incident && (
            <Card title={`Incident ${incident.incident.reference}`}>
              <p className="text-sm">{String(incident.incident.description ?? '')}</p>
              {incident.people.length > 0 && <p className="mt-2 text-xs text-ink-muted">{incident.people.map((p) => `${p.name} (${p.role})`).join(', ')}</p>}
              <ul className="mt-3 space-y-1 text-xs">{incident.history.map((h, i) => <li key={i}>{when(h.created_at)} · {h.action}{h.reason ? `: ${h.reason}` : ''}</li>)}</ul>
              <div className="mt-3 flex flex-wrap gap-2">
                {['under_review', 'follow_up_required', 'resolved', 'closed', 'reopened'].map((st) => (
                  <Button key={st} variant="secondary" disabled={busy || incident.incident.status === st} onClick={() => {
                    const reason = window.prompt(`Why move it to "${st.replaceAll('_', ' ')}"?`);
                    if (reason && reason.trim().length >= 3) void act(`/hostel/incidents/${incident.incident.id}`, 'PUT', { status: st, reason: reason.trim() }, 'Updated.').then((ok) => ok && setIncident(null));
                  }}>{st.replaceAll('_', ' ')}</Button>
                ))}
              </div>
              <Button className="mt-2" variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt('Note that the guardian has been told (and how):'); if (reason && reason.trim().length >= 3) void act(`/hostel/incidents/${incident.incident.id}`, 'PUT', { guardian_notified: true, reason: reason.trim() }, 'Recorded.'); }}>Guardian has been told</Button>
            </Card>
          )}
        </div>
      )}

      {tab === 'announce' && (
        <Card title="Send an announcement" description="Shown on phones' lock screens, so keep anything private out of it.">
          <form className="grid max-w-2xl gap-3" onSubmit={(e) => { e.preventDefault(); void act('/hostel/announcements', 'POST', { ...announce, hostel_id: announce.hostel_id ? Number(announce.hostel_id) : null }, 'Sent.').then((ok) => ok && setAnnounce({ ...announce, title: '', body: '' })); }}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="To"><select className={inputClass} value={announce.audience} onChange={(e) => setAnnounce({ ...announce, audience: e.target.value })}><option value="residents">Boarders</option><option value="parents">Boarders’ parents</option><option value="both">Boarders and parents</option><option value="staff">Hostel staff</option></select></Field>
              <Field label="Hostel"><select className={inputClass} value={announce.hostel_id} onChange={(e) => setAnnounce({ ...announce, hostel_id: e.target.value })}><option value="">All hostels</option>{hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></Field>
            </div>
            <Field label="Title"><input className={inputClass} required maxLength={120} value={announce.title} onChange={(e) => setAnnounce({ ...announce, title: e.target.value })} /></Field>
            <Field label="Message"><textarea className={`${inputClass} min-h-[6rem]`} required maxLength={1000} value={announce.body} onChange={(e) => setAnnounce({ ...announce, body: e.target.value })} /></Field>
            <Button type="submit" disabled={busy}>Send</Button>
          </form>
        </Card>
      )}

      {tab === 'boarder' && (
        <div className="space-y-5">
          <div className="max-w-md"><Field label="Boarder"><StudentPicker value={boarder} onChange={setBoarder} /></Field></div>
          {!boarder ? <Empty>Find a boarder.</Empty> : !data || Array.isArray(data) ? (!error && <Loading />) : (() => {
            const b = data as { student: { name: string; class: string | null }; presence: string | null; allocations: { id: number; status: string; starts_on: string | null; ends_on: string | null; hostel: string | null; room: string | null; bed: string | null; end_reason: string | null }[]; movements: { id: number; type: string; occurred_at: string }[]; leave: { id: number; leave_type: string; status: string; depart_at: string }[]; welfare_open: number | null };
            return (
              <div className="grid gap-5 lg:grid-cols-2">
                <Card title={`${b.student.name} · ${b.student.class ?? ''}`} description={b.presence ? `Now: ${b.presence.replaceAll('_', ' ')}` : undefined}>
                  <p className="mb-2 text-sm font-semibold">Beds</p>
                  <ul className="space-y-1 text-sm">
                    {b.allocations.map((a) => (
                      <li key={a.id}>{a.hostel} · {a.room} · {a.bed} <Badge tone={a.status === 'active' ? 'green' : 'slate'}>{a.status}</Badge> <span className="text-xs text-ink-muted">{a.starts_on?.slice(0, 10)} → {a.ends_on?.slice(0, 10) ?? 'now'}{a.end_reason ? ` (${a.end_reason})` : ''}</span>
                        {a.status === 'active' && <button type="button" className="ml-2 text-xs text-negative underline" onClick={() => { const reason = window.prompt('Why is this boarder leaving the bed? (the history is kept)'); if (reason && reason.trim().length >= 3) void act(`/hostel/allocations/${a.id}/end`, 'POST', { reason: reason.trim() }, 'Allocation ended.'); }}>End stay</button>}
                      </li>
                    ))}
                  </ul>
                  {b.welfare_open != null && b.welfare_open > 0 && <p className="mt-3 text-sm"><Badge tone="amber">{b.welfare_open} open welfare record(s)</Badge></p>}
                </Card>
                <Card title="Recent movements and leave">
                  <ul className="space-y-1 text-sm">
                    {b.leave.slice(0, 5).map((l) => <li key={`l${l.id}`}>{l.leave_type} leave from {when(l.depart_at)} <Badge>{l.status.replaceAll('_', ' ')}</Badge></li>)}
                    {b.movements.slice(0, 10).map((m) => <li key={`m${m.id}`} className="text-ink-muted">{when(m.occurred_at)} · {m.type.replaceAll('_', ' ')}</li>)}
                  </ul>
                </Card>
              </div>
            );
          })()}
        </div>
      )}

      {tab === 'staff' && (!rows() ? <Loading /> : (
        <Card title="Who runs each hostel">
          {rows<{ id: number; name: string | null; hostel: string | null; role: string; starts_on: string | null; ends_on: string | null }>()!.length === 0 ? <Empty>No hostel staff assigned.</Empty> : (
            <Table columns={['Name', 'Hostel', 'Role', 'From', 'To']}>
              {rows<{ id: number; name: string | null; hostel: string | null; role: string; starts_on: string | null; ends_on: string | null }>()!.map((s) => <tr key={s.id}><Cell className="font-medium">{s.name}</Cell><Cell>{s.hostel}</Cell><Cell className="capitalize">{s.role.replaceAll('_', ' ')}</Cell><Cell>{s.starts_on?.slice(0, 10)}</Cell><Cell>{s.ends_on?.slice(0, 10) ?? 'now'}</Cell></tr>)}
            </Table>
          )}
        </Card>
      ))}
    </PageShell>
  );
}
