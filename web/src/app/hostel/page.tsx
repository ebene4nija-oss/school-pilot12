'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * Hostel management: who is in which bed, who is in the hostel right now,
 * roll call, leave, maintenance and welfare.
 *
 * The rules live in the API and surface here as errors: a bed needs an
 * approved boarding enrolment, a bed holds one student, capacity is only
 * exceeded with a written admin override, and an approved leave is not a
 * departure until the student is signed out. A warden sees their own hostel;
 * welfare and incident records are for managers, matrons and the nurse.
 */

type Tab = 'overview' | 'rooms' | 'residents' | 'rollcall' | 'leave' | 'maintenance' | 'welfare';

interface Hostel { id: number; name: string; gender: string | null; status: string }
interface Bed { id: number; label: string; status: string; occupancy: string | null }
interface Room { id: number; hostel_id: number; hostel: string; block: string | null; floor: string | null; name: string; capacity: number; physical_beds: number; usable_beds: number; occupied: number; reserved: number; available: number; status: string; over_capacity: boolean; beds: Bed[] }
interface Resident { allocation_id: number; allocation_status: string; student_id: number; name: string; admission_number: string | null; class: string | null; hostel_id: number; hostel: string; room: string; block: string | null; bed_id: number; bed: string; presence: string }
interface Enrollment { id: number; student_id: number; student_name: string | null; class: string | null; boarding_category: string; status: string; expected_arrival: string | null; has_bed: boolean }
interface Dashboard {
  cards: { total_capacity: number; physical_capacity: number; occupied: number; available: number; occupancy_rate: number | null; expected_arrivals: number; on_leave: number; overdue_returns: number; open_incidents: number | null; open_maintenance: number };
  alerts: { kind: string; text: string }[];
  out_of_service_beds: number;
}
interface Session { id: number; name: string; time: string }
interface Rollcall { rollcall: { id: number; status: string }; students: { student_id: number; name: string | null; status: string; note: string | null }[]; counts: Record<string, number>; unmarked: number }
interface Leave { id: number; student_id: number; student_name: string | null; leave_type: string; reason: string; depart_at: string; return_at: string; status: string; guardian_consent: string | null; overdue: boolean }
interface Maintenance { id: number; reference: string; hostel_id: number; bed_id: number | null; category: string; description: string; priority: string; status: string; overdue: boolean }
interface Welfare { id: number; student_name: string | null; category: string; description: string; priority: string; status: string; created_at: string }
interface Incident { id: number; reference: string; category: string; occurred_at: string; status: string; people: number }

const PRESENCE: Record<string, { tone: 'green' | 'amber' | 'red' | 'slate' | 'blue'; label: string }> = {
  in_hostel: { tone: 'green', label: 'In hostel' }, on_leave: { tone: 'blue', label: 'On leave' },
  checked_out: { tone: 'slate', label: 'Checked out' }, not_arrived: { tone: 'amber', label: 'Not arrived' },
};
const ROLL = ['present', 'absent', 'on_leave', 'school_activity', 'medical', 'not_expected'];
const when = (iso: string) => new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const words = (s: string) => s.replaceAll('_', ' ');

export default function HostelPage() {
  const [tab, setTab] = useState<Tab>('overview');
  const [hostels, setHostels] = useState<Hostel[]>([]);
  const [dash, setDash] = useState<Dashboard | null>(null);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [residents, setResidents] = useState<Resident[]>([]);
  const [enrollments, setEnrollments] = useState<Enrollment[]>([]);
  const [sessions, setSessions] = useState<Session[]>([]);
  const [rollcall, setRollcall] = useState<Rollcall | null>(null);
  const [leave, setLeave] = useState<Leave[]>([]);
  const [maintenance, setMaintenance] = useState<Maintenance[]>([]);
  const [welfare, setWelfare] = useState<Welfare[] | null>(null);
  const [incidents, setIncidents] = useState<Incident[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [search, setSearch] = useState('');

  const [roomForm, setRoomForm] = useState({ hostel_id: '', name: '', block: '', capacity: '4' });
  const [newHostel, setNewHostel] = useState('');
  const [enrolStudent, setEnrolStudent] = useState<PickedStudent | null>(null);
  const [enrolForm, setEnrolForm] = useState({ boarding_category: 'full', expected_arrival: '' });
  const [allot, setAllot] = useState({ student_id: '', bed_id: '', override_reason: '' });
  const [rcForm, setRcForm] = useState({ session_id: '', hostel_id: '', newName: '', newTime: '21:00' });
  const [leaveStudent, setLeaveStudent] = useState<PickedStudent | null>(null);
  const [leaveForm, setLeaveForm] = useState({ leave_type: 'weekend', reason: '', depart_at: '', return_at: '' });
  const [mForm, setMForm] = useState({ hostel_id: '', bed_id: '', category: 'furniture', description: '', priority: 'normal' });
  const [wStudent, setWStudent] = useState<PickedStudent | null>(null);
  const [wForm, setWForm] = useState({ category: 'student_concern', description: '', priority: 'normal' });
  const [iForm, setIForm] = useState({ category: 'conflict', occurred_at: '', description: '' });

  const load = useCallback(async () => {
    const hostelRes = await fetchApi<{ data: Hostel[] }>('/hostel/hostels');
    setHostels(hostelRes.data);
    if (tab === 'overview') setDash(await fetchApi('/hostel/dashboard'));
    if (tab === 'rooms' || tab === 'residents' || tab === 'maintenance') setRooms((await fetchApi<{ rooms: Room[] }>('/hostel/structure')).rooms);
    if (tab === 'residents') {
      const [r, e] = await Promise.all([
        fetchApi<{ data: Resident[] }>(`/hostel/residents${search ? `?search=${encodeURIComponent(search)}` : ''}`),
        fetchApi<{ data: Enrollment[] }>('/hostel/enrollments'),
      ]);
      setResidents(r.data);
      setEnrollments(e.data);
    }
    if (tab === 'rollcall') setSessions((await fetchApi<{ data: Session[] }>('/hostel/rollcall-sessions')).data);
    if (tab === 'leave') setLeave((await fetchApi<{ data: Leave[] }>('/hostel/leave')).data);
    if (tab === 'maintenance') setMaintenance((await fetchApi<{ data: Maintenance[] }>('/hostel/maintenance')).data);
    if (tab === 'welfare') {
      // Restricted lists: a 403 means "not yours to read", not a broken page.
      try { setWelfare((await fetchApi<{ data: Welfare[] }>('/hostel/welfare')).data); } catch { setWelfare(null); }
      try { setIncidents((await fetchApi<{ data: Incident[] }>('/hostel/incidents')).data); } catch { setIncidents(null); }
    }
  }, [tab, search]);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        await load();
        setError(null);
      } catch (err) {
        setError(errorText(err, 'Could not load the hostel.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function act(path: string, body: unknown, done: string, method: 'POST' | 'PUT' = 'POST') {
    setBusy(true);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string }>(path, { method, body: JSON.stringify(body ?? {}) });
      setNotice(res?.message ?? done);
      setError(null);
      await load();
      return res;
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
      return null;
    } finally {
      setBusy(false);
    }
  }

  const freeBeds = rooms.flatMap((r) => r.beds.filter((b) => b.status === 'available' && !b.occupancy).map((b) => ({ id: b.id, label: `${r.hostel} · ${r.name} · ${b.label}` })));
  const awaitingBed = enrollments.filter((e) => ['approved', 'active'].includes(e.status) && !e.has_bed);

  async function openRollcall() {
    setBusy(true);
    try {
      setRollcall(await fetchApi<Rollcall>('/hostel/rollcalls/open', { method: 'POST', body: JSON.stringify({ session_id: Number(rcForm.session_id), hostel_id: Number(rcForm.hostel_id) }) }));
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not open roll call.'));
    } finally {
      setBusy(false);
    }
  }

  async function markRoll(studentId: number, status: string) {
    if (!rollcall) return;
    const reason = rollcall.rollcall.status === 'submitted' ? window.prompt('This roll call is submitted. Reason for the correction?') : null;
    if (rollcall.rollcall.status === 'submitted' && !reason) return;
    try {
      await fetchApi(`/hostel/rollcalls/${rollcall.rollcall.id}/marks`, { method: 'PUT', body: JSON.stringify({ marks: [{ student_id: studentId, status }], correction_reason: reason ?? undefined }) });
      setRollcall(await fetchApi<Rollcall>(`/hostel/rollcalls/${rollcall.rollcall.id}`));
    } catch (err) {
      setError(errorText(err, 'Could not save the mark.'));
    }
  }

  const tabs: [Tab, string][] = [['overview', 'Overview'], ['rooms', 'Rooms & beds'], ['residents', 'Residents'], ['rollcall', 'Roll call'], ['leave', 'Leave'], ['maintenance', 'Maintenance'], ['welfare', 'Welfare']];

  return (
    <PageShell title="Hostel" subtitle="Beds, presence, roll call, leave and welfare for boarders.">
      <nav className="mb-5 flex flex-wrap gap-2" aria-label="Hostel sections">
        {tabs.map(([key, label]) => (
          <Button key={key} variant={tab === key ? 'primary' : 'secondary'} onClick={() => { setTab(key); setNotice(null); }}>{label}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-4 py-2 text-sm text-positive">{notice}</p>}
      {loading && <Loading />}

      {!loading && tab === 'overview' && dash && (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Occupied" value={`${dash.cards.occupied} / ${dash.cards.total_capacity}`} hint={dash.cards.occupancy_rate !== null ? `${dash.cards.occupancy_rate}% of usable beds` : 'No usable beds yet'} />
            <StatTile label="Free beds" value={dash.cards.available} tone="green" hint={dash.out_of_service_beds ? `${dash.out_of_service_beds} out of service` : undefined} />
            <StatTile label="On leave" value={dash.cards.on_leave} tone="blue" hint={`${dash.cards.expected_arrivals} expected this week`} />
            <StatTile label="Overdue returns" value={dash.cards.overdue_returns} tone={dash.cards.overdue_returns ? 'red' : 'slate'} hint={`${dash.cards.open_maintenance} open repairs${dash.cards.open_incidents !== null ? ` · ${dash.cards.open_incidents} open incidents` : ''}`} />
          </div>
          <Card title="Needs attention">
            {dash.alerts.length === 0 ? <Empty>Nothing needs attention right now.</Empty> : (
              <ul className="space-y-2 text-sm">
                {dash.alerts.map((a, i) => (
                  <li key={i} className="flex items-start gap-2"><Badge tone={a.kind === 'overdue_return' ? 'red' : 'amber'}>{words(a.kind)}</Badge><span>{a.text}</span></li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Reports" description="CSV downloads for the current hostel records.">
            <div className="flex flex-wrap gap-2">
              {['occupancy', 'residents', 'on_leave', 'overdue', 'maintenance'].map((k) => (
                <a key={k} className="rounded-lg border border-line px-3 py-2 text-sm hover:bg-surface-sunken" href={`/api/backend/hostel/reports/${k}`}>{words(k)}</a>
              ))}
            </div>
          </Card>
        </div>
      )}

      {!loading && tab === 'rooms' && (
        <div className="space-y-5">
          <Card title="Add a room" description="Beds B1, B2… are created to the room's capacity. Admins add hostels.">
            <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); act(`/hostel/hostels/${roomForm.hostel_id}/rooms`, { name: roomForm.name, block: roomForm.block || null, capacity: Number(roomForm.capacity) }, 'Room added.'); }}>
              <Field label="Hostel"><select className={inputClass} required value={roomForm.hostel_id} onChange={(e) => setRoomForm({ ...roomForm, hostel_id: e.target.value })}><option value="">Choose…</option>{hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></Field>
              <Field label="Block"><input className={inputClass} value={roomForm.block} onChange={(e) => setRoomForm({ ...roomForm, block: e.target.value })} /></Field>
              <Field label="Room"><input className={inputClass} required value={roomForm.name} onChange={(e) => setRoomForm({ ...roomForm, name: e.target.value })} /></Field>
              <Field label="Capacity"><input className={inputClass} type="number" min={1} max={64} value={roomForm.capacity} onChange={(e) => setRoomForm({ ...roomForm, capacity: e.target.value })} /></Field>
              <div className="flex items-end"><Button disabled={busy}>Add room</Button></div>
            </form>
            <form className="mt-4 flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); act('/hostel/hostels', { name: newHostel }, 'Hostel added.'); setNewHostel(''); }}>
              <Field label="New hostel (admins)"><input className={inputClass} value={newHostel} onChange={(e) => setNewHostel(e.target.value)} required /></Field>
              <Button variant="secondary" disabled={busy}>Add hostel</Button>
            </form>
          </Card>
          {rooms.length === 0 ? <Empty>No rooms yet.</Empty> : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {rooms.map((r) => (
                <Card key={r.id} title={`${r.hostel} · ${r.block ? `${r.block} ` : ''}${r.name}`} description={`${r.occupied} of ${r.usable_beds} usable beds taken${r.reserved ? `, ${r.reserved} reserved` : ''}`}>
                  {r.over_capacity && <p className="mb-2"><Badge tone="red">Over approved capacity</Badge></p>}
                  <ul className="flex flex-wrap gap-2">
                    {r.beds.map((b) => (
                      <li key={b.id}>
                        <button
                          type="button"
                          title={b.status === 'available' ? 'Mark out of service' : 'Return to service'}
                          disabled={busy || b.occupancy === 'active'}
                          onClick={() => {
                            const note = b.status === 'available' ? window.prompt(`Why is bed ${b.label} out of service?`) : '';
                            if (note === null) return;
                            act(`/hostel/beds/${b.id}/status`, { status: b.status === 'available' ? 'out_of_service' : 'available', note }, 'Bed updated.');
                          }}
                          className={`rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ${b.status !== 'available' ? 'bg-negative-soft text-negative ring-negative/30' : b.occupancy === 'active' ? 'bg-brand text-white ring-brand' : b.occupancy === 'reserved' ? 'bg-caution-soft text-caution ring-accent/30' : 'bg-surface text-ink ring-line'}`}
                        >
                          {b.label}{b.status !== 'available' ? ' ✕' : b.occupancy === 'active' ? ' ●' : b.occupancy === 'reserved' ? ' ◐' : ' ○'}
                        </button>
                      </li>
                    ))}
                  </ul>
                </Card>
              ))}
            </div>
          )}
          <p className="text-xs text-ink-muted">● occupied · ◐ reserved · ○ free · ✕ out of service. Click a free bed to take it out of service.</p>
        </div>
      )}

      {!loading && tab === 'residents' && (
        <div className="space-y-5">
          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Boarding enrolment" description="A student needs an approved enrolment before a bed.">
              <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (enrolStudent) act('/hostel/enrollments', { student_id: enrolStudent.id, boarding_category: enrolForm.boarding_category, expected_arrival: enrolForm.expected_arrival || null }, 'Enrolment submitted.'); }}>
                <Field label="Student"><StudentPicker value={enrolStudent} onChange={setEnrolStudent} /></Field>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Boarding"><select className={inputClass} value={enrolForm.boarding_category} onChange={(e) => setEnrolForm({ ...enrolForm, boarding_category: e.target.value })}>{['full', 'weekly', 'flexible', 'day_services'].map((c) => <option key={c} value={c}>{words(c)}</option>)}</select></Field>
                  <Field label="Expected arrival"><input className={inputClass} type="date" value={enrolForm.expected_arrival} onChange={(e) => setEnrolForm({ ...enrolForm, expected_arrival: e.target.value })} /></Field>
                </div>
                <Button disabled={busy || !enrolStudent}>Submit enrolment</Button>
              </form>
            </Card>
            <Card title="Allocate a bed" description="Over capacity needs an admin and a written reason.">
              <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); act('/hostel/allocations', { student_id: Number(allot.student_id), bed_id: Number(allot.bed_id), override_reason: allot.override_reason || undefined }, 'Bed allocated.'); }}>
                <Field label="Approved boarder"><select className={inputClass} required value={allot.student_id} onChange={(e) => setAllot({ ...allot, student_id: e.target.value })}><option value="">{awaitingBed.length ? 'Choose…' : 'Nobody is waiting for a bed'}</option>{awaitingBed.map((e) => <option key={e.id} value={e.student_id}>{e.student_name} {e.class ? `(${e.class})` : ''}</option>)}</select></Field>
                <Field label="Free bed"><select className={inputClass} required value={allot.bed_id} onChange={(e) => setAllot({ ...allot, bed_id: e.target.value })}><option value="">Choose…</option>{freeBeds.map((b) => <option key={b.id} value={b.id}>{b.label}</option>)}</select></Field>
                <Field label="Override reason (only if over capacity)"><input className={inputClass} value={allot.override_reason} onChange={(e) => setAllot({ ...allot, override_reason: e.target.value })} /></Field>
                <Button disabled={busy}>Allocate</Button>
              </form>
            </Card>
          </div>

          {enrollments.some((e) => ['submitted', 'under_review', 'waitlisted'].includes(e.status)) && (
            <Card title="Enrolments awaiting a decision">
              <Table columns={['Student', 'Boarding', 'Status', '']}>
                {enrollments.filter((e) => ['submitted', 'under_review', 'waitlisted'].includes(e.status)).map((e) => (
                  <tr key={e.id}>
                    <Cell>{e.student_name}<span className="block text-xs text-ink-muted">{e.class}</span></Cell>
                    <Cell>{words(e.boarding_category)}</Cell>
                    <Cell><Badge tone="amber">{words(e.status)}</Badge></Cell>
                    <Cell className="space-x-2 text-right">
                      <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/enrollments/${e.id}/decision`, { decision: 'approve' }, 'Approved.')}>Approve</Button>
                      {e.status !== 'waitlisted' && <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/enrollments/${e.id}/decision`, { decision: 'waitlist' }, 'Waitlisted.')}>Waitlist</Button>}
                      <Button variant="danger" disabled={busy} onClick={() => act(`/hostel/enrollments/${e.id}/decision`, { decision: 'reject', note: window.prompt('Reason (shared with the family)?') ?? '' }, 'Declined.')}>Decline</Button>
                    </Cell>
                  </tr>
                ))}
              </Table>
            </Card>
          )}

          <Card title="Residents">
            <input className={`${inputClass} mb-4 max-w-sm`} placeholder="Search name, admission no. or room…" defaultValue={search} onKeyDown={(e) => { if (e.key === 'Enter') setSearch((e.target as HTMLInputElement).value); }} />
            {residents.length === 0 ? <Empty>No residents.</Empty> : (
              <Table columns={['Student', 'Bed', 'Presence', '']}>
                {residents.map((r) => {
                  const p = PRESENCE[r.presence] ?? { tone: 'slate' as const, label: words(r.presence) };
                  return (
                    <tr key={r.allocation_id}>
                      <Cell>{r.name}<span className="block text-xs text-ink-muted">{r.admission_number} · {r.class}</span></Cell>
                      <Cell>{r.hostel} · {r.block ? `${r.block} ` : ''}{r.room} · {r.bed}{r.allocation_status === 'reserved' && <span className="ml-1"><Badge tone="amber">reserved</Badge></span>}</Cell>
                      <Cell><Badge tone={p.tone}>{p.label}</Badge></Cell>
                      <Cell className="space-x-2 whitespace-nowrap text-right">
                        {r.presence === 'in_hostel'
                          ? <Button variant="secondary" disabled={busy} onClick={() => act('/hostel/check-out', { student_id: r.student_id, ends_stay: false }, 'Checked out.')}>Check out</Button>
                          : r.presence !== 'on_leave' && <Button variant="secondary" disabled={busy} onClick={() => act('/hostel/check-in', { student_ids: [r.student_id] }, 'Checked in.')}>Check in</Button>}
                        <Button variant="secondary" disabled={busy} onClick={() => {
                          const bed = window.prompt(`Move ${r.name} to which bed?\n${freeBeds.map((b) => `${b.id}: ${b.label}`).join('\n')}\n\nType the number:`);
                          const reason = bed ? window.prompt('Reason for the move?') : null;
                          if (bed && reason) act(`/hostel/allocations/${r.allocation_id}/move`, { bed_id: Number(bed), reason }, 'Moved. The earlier allocation stays in the history.');
                        }}>Move</Button>
                        <Button variant="danger" disabled={busy} onClick={() => { if (window.confirm(`End ${r.name}'s stay? The bed is freed; the history is kept.`)) act('/hostel/check-out', { student_id: r.student_id, ends_stay: true }, 'Stay ended.'); }}>End stay</Button>
                      </Cell>
                    </tr>
                  );
                })}
              </Table>
            )}
          </Card>
        </div>
      )}

      {!loading && tab === 'rollcall' && (
        <div className="space-y-5">
          <Card title="Take roll call" description="Students on approved leave are pre-marked. Anyone still unconfirmed at submission is flagged.">
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Session"><select className={inputClass} value={rcForm.session_id} onChange={(e) => setRcForm({ ...rcForm, session_id: e.target.value })}><option value="">Choose…</option>{sessions.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.time})</option>)}</select></Field>
              <Field label="Hostel"><select className={inputClass} value={rcForm.hostel_id} onChange={(e) => setRcForm({ ...rcForm, hostel_id: e.target.value })}><option value="">Choose…</option>{hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></Field>
              <div className="flex items-end"><Button disabled={busy || !rcForm.session_id || !rcForm.hostel_id} onClick={openRollcall}>Open</Button></div>
            </div>
            <form className="mt-4 flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); act('/hostel/rollcall-sessions', { name: rcForm.newName, time: rcForm.newTime, days: [1, 2, 3, 4, 5, 6, 7] }, 'Session added.'); }}>
              <Field label="New session"><input className={inputClass} placeholder="Night roll call" required value={rcForm.newName} onChange={(e) => setRcForm({ ...rcForm, newName: e.target.value })} /></Field>
              <Field label="Time"><input className={inputClass} type="time" value={rcForm.newTime} onChange={(e) => setRcForm({ ...rcForm, newTime: e.target.value })} /></Field>
              <Button variant="secondary" disabled={busy}>Add session</Button>
            </form>
          </Card>
          {rollcall && (
            <Card title={`Roll call · ${words(rollcall.rollcall.status)}`} description={`${rollcall.unmarked} still unconfirmed. ${rollcall.rollcall.status === 'submitted' ? 'Changes now are corrections and need a reason.' : ''}`}>
              <Table columns={['Student', 'Mark']}>
                {rollcall.students.map((s) => (
                  <tr key={s.student_id}>
                    <Cell>{s.name}</Cell>
                    <Cell>
                      <div className="flex flex-wrap gap-1">
                        {ROLL.map((st) => (
                          <button key={st} type="button" onClick={() => markRoll(s.student_id, st)} className={`rounded-md px-2 py-1 text-xs ring-1 ${s.status === st ? (st === 'absent' ? 'bg-negative text-white ring-negative' : 'bg-brand text-white ring-brand') : 'ring-line hover:bg-surface-sunken'}`}>{words(st)}</button>
                        ))}
                        {s.status === 'unconfirmed' && <Badge tone="amber">unconfirmed</Badge>}
                      </div>
                    </Cell>
                  </tr>
                ))}
              </Table>
              {rollcall.rollcall.status !== 'submitted' && (
                <Button className="mt-4" variant="accent" disabled={busy} onClick={async () => {
                  try {
                    const res = await fetchApi<Rollcall & { not_accounted_for: number }>(`/hostel/rollcalls/${rollcall.rollcall.id}/submit`, { method: 'POST' });
                    setRollcall(res);
                    setNotice(res.not_accounted_for ? `Submitted. ${res.not_accounted_for} student(s) not accounted for — the hostel manager has been alerted.` : 'Submitted. Everyone is accounted for.');
                  } catch (err) { setError(errorText(err, 'Could not submit.')); }
                }}>Submit roll call</Button>
              )}
            </Card>
          )}
        </div>
      )}

      {!loading && tab === 'leave' && (
        <div className="space-y-5">
          <Card title="Record a leave request" description="Approval is not departure: sign the student out when they actually leave.">
            <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); if (leaveStudent) act('/hostel/leave', { ...leaveForm, student_id: leaveStudent.id }, 'Leave request recorded.'); }}>
              <Field label="Student"><StudentPicker value={leaveStudent} onChange={setLeaveStudent} /></Field>
              <Field label="Type"><select className={inputClass} value={leaveForm.leave_type} onChange={(e) => setLeaveForm({ ...leaveForm, leave_type: e.target.value })}>{['weekend', 'medical', 'outing', 'family', 'other'].map((t) => <option key={t}>{t}</option>)}</select></Field>
              <Field label="Leaves"><input className={inputClass} type="datetime-local" required value={leaveForm.depart_at} onChange={(e) => setLeaveForm({ ...leaveForm, depart_at: e.target.value })} /></Field>
              <Field label="Returns"><input className={inputClass} type="datetime-local" required value={leaveForm.return_at} onChange={(e) => setLeaveForm({ ...leaveForm, return_at: e.target.value })} /></Field>
              <Field label="Reason"><input className={inputClass} required value={leaveForm.reason} onChange={(e) => setLeaveForm({ ...leaveForm, reason: e.target.value })} /></Field>
              <div className="flex items-end"><Button disabled={busy || !leaveStudent}>Record</Button></div>
            </form>
          </Card>
          <Card title="Leave">
            {leave.length === 0 ? <Empty>No leave requests.</Empty> : (
              <Table columns={['Student', 'When', 'Status', '']}>
                {leave.map((l) => (
                  <tr key={l.id}>
                    <Cell>{l.student_name}<span className="block text-xs text-ink-muted">{l.leave_type} · {l.reason}</span></Cell>
                    <Cell className="text-xs">{when(l.depart_at)} → {when(l.return_at)}</Cell>
                    <Cell>
                      <Badge tone={l.overdue ? 'red' : l.status === 'approved' ? 'green' : l.status === 'departed' ? 'blue' : l.status === 'rejected' ? 'slate' : 'amber'}>{l.overdue ? 'overdue' : words(l.status)}</Badge>
                      {l.guardian_consent && <span className="ml-1 text-xs text-ink-muted">consent: {l.guardian_consent}</span>}
                    </Cell>
                    <Cell className="space-x-2 whitespace-nowrap text-right">
                      {['submitted', 'awaiting_consent'].includes(l.status) && <>
                        <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/leave/${l.id}/decision`, { approve: true }, 'Approved.')}>Approve</Button>
                        <Button variant="danger" disabled={busy} onClick={() => act(`/hostel/leave/${l.id}/decision`, { approve: false }, 'Declined.')}>Decline</Button>
                      </>}
                      {l.status === 'approved' && <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/leave/${l.id}/depart`, { collected_by: window.prompt('Collected by?') ?? undefined }, 'Signed out.')}>Sign out</Button>}
                      {l.status === 'departed' && <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/leave/${l.id}/return`, {}, 'Signed back in.')}>Returned</Button>}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}

      {!loading && tab === 'maintenance' && (
        <div className="space-y-5">
          <Card title="Report a problem" description="A report does not take a bed out of service; a manager decides that.">
            <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); act('/hostel/maintenance', { ...mForm, hostel_id: Number(mForm.hostel_id), bed_id: mForm.bed_id ? Number(mForm.bed_id) : null }, 'Reported.'); }}>
              <Field label="Hostel"><select className={inputClass} required value={mForm.hostel_id} onChange={(e) => setMForm({ ...mForm, hostel_id: e.target.value, bed_id: '' })}><option value="">Choose…</option>{hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></Field>
              <Field label="Bed (optional)"><select className={inputClass} value={mForm.bed_id} onChange={(e) => setMForm({ ...mForm, bed_id: e.target.value })}><option value="">—</option>{rooms.filter((r) => String(r.hostel_id) === mForm.hostel_id).flatMap((r) => r.beds.map((b) => <option key={b.id} value={b.id}>{r.name} · {b.label}</option>))}</select></Field>
              <Field label="Category"><select className={inputClass} value={mForm.category} onChange={(e) => setMForm({ ...mForm, category: e.target.value })}>{['plumbing', 'electrical', 'furniture', 'bedding', 'building', 'security', 'cleaning', 'other'].map((c) => <option key={c}>{c}</option>)}</select></Field>
              <Field label="Priority"><select className={inputClass} value={mForm.priority} onChange={(e) => setMForm({ ...mForm, priority: e.target.value })}>{['low', 'normal', 'high', 'urgent'].map((c) => <option key={c}>{c}</option>)}</select></Field>
              <Field label="What is wrong"><input className={inputClass} required minLength={5} value={mForm.description} onChange={(e) => setMForm({ ...mForm, description: e.target.value })} /></Field>
              <div className="flex items-end"><Button disabled={busy}>Report</Button></div>
            </form>
          </Card>
          <Card title="Repairs">
            {maintenance.length === 0 ? <Empty>No repair requests.</Empty> : (
              <Table columns={['Ref', 'Problem', 'Status', '']}>
                {maintenance.map((m) => (
                  <tr key={m.id}>
                    <Cell className="font-mono text-xs">{m.reference}</Cell>
                    <Cell>{m.description}<span className="block text-xs text-ink-muted">{m.category} · {m.priority}</span></Cell>
                    <Cell><Badge tone={m.overdue ? 'red' : m.status === 'verified' ? 'green' : 'amber'}>{m.overdue ? `overdue · ${words(m.status)}` : words(m.status)}</Badge></Cell>
                    <Cell className="space-x-2 whitespace-nowrap text-right">
                      {m.bed_id && !['completed', 'verified', 'cancelled'].includes(m.status) && <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/maintenance/${m.id}`, { take_out_of_service: true }, 'Bed taken out of service.', 'PUT')}>Bed out of service</Button>}
                      {!['completed', 'verified', 'cancelled'].includes(m.status) && <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/maintenance/${m.id}`, { status: 'completed' }, 'Marked complete.', 'PUT')}>Complete</Button>}
                      {m.status === 'completed' && <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/maintenance/${m.id}/verify`, {}, 'Verified. The bed is back in service.')}>Verify</Button>}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}

      {!loading && tab === 'welfare' && (
        <div className="space-y-5">
          <div className="grid gap-5 lg:grid-cols-2">
            <Card title="Record a welfare concern" description="Any hostel staff member can record one. Reading them is restricted.">
              <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (wStudent) act('/hostel/welfare', { ...wForm, student_id: wStudent.id }, 'Recorded. Only managers, matrons and the nurse can read it.'); }}>
                <Field label="Student"><StudentPicker value={wStudent} onChange={setWStudent} /></Field>
                <Field label="Kind"><select className={inputClass} value={wForm.category} onChange={(e) => setWForm({ ...wForm, category: e.target.value })}>{['welfare_check', 'student_concern', 'guardian_contact', 'referral', 'follow_up'].map((c) => <option key={c} value={c}>{words(c)}</option>)}</select></Field>
                <Field label="What you noticed"><textarea className={inputClass} rows={3} required minLength={5} value={wForm.description} onChange={(e) => setWForm({ ...wForm, description: e.target.value })} /></Field>
                <Button disabled={busy || !wStudent}>Record</Button>
              </form>
            </Card>
            <Card title="Report an incident" description="Factual account; the reference is issued automatically.">
              <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); act('/hostel/incidents', iForm, 'Incident reported.'); }}>
                <Field label="Category"><select className={inputClass} value={iForm.category} onChange={(e) => setIForm({ ...iForm, category: e.target.value })}>{['safety', 'property_damage', 'conflict', 'rule_breach', 'missing_property', 'welfare', 'unauthorised_absence', 'other'].map((c) => <option key={c} value={c}>{words(c)}</option>)}</select></Field>
                <Field label="When"><input className={inputClass} type="datetime-local" required value={iForm.occurred_at} onChange={(e) => setIForm({ ...iForm, occurred_at: e.target.value })} /></Field>
                <Field label="What happened"><textarea className={inputClass} rows={3} required minLength={10} value={iForm.description} onChange={(e) => setIForm({ ...iForm, description: e.target.value })} /></Field>
                <Button disabled={busy}>Report</Button>
              </form>
            </Card>
          </div>
          {welfare === null ? <Empty>Welfare and incident records are visible to hostel managers, matrons and the nurse.</Empty> : (
            <>
              <Card title="Welfare records">
                {welfare.length === 0 ? <Empty>None recorded.</Empty> : (
                  <Table columns={['Student', 'Concern', 'Status']}>
                    {welfare.map((w) => (
                      <tr key={w.id}>
                        <Cell>{w.student_name}<span className="block text-xs text-ink-muted">{when(w.created_at)}</span></Cell>
                        <Cell>{w.description}<span className="block text-xs text-ink-muted">{words(w.category)} · {w.priority}</span></Cell>
                        <Cell>{w.status === 'open' ? <Button variant="secondary" disabled={busy} onClick={() => act(`/hostel/welfare/${w.id}`, { status: 'closed', action_taken: window.prompt('Action taken?') ?? '' }, 'Closed.', 'PUT')}>Close</Button> : <Badge>closed</Badge>}</Cell>
                      </tr>
                    ))}
                  </Table>
                )}
              </Card>
              <Card title="Incidents">
                {!incidents || incidents.length === 0 ? <Empty>None reported.</Empty> : (
                  <Table columns={['Ref', 'Category', 'When', 'Status']}>
                    {incidents.map((i) => (
                      <tr key={i.id}><Cell className="font-mono text-xs">{i.reference}</Cell><Cell>{words(i.category)}</Cell><Cell className="text-xs">{when(i.occurred_at)}</Cell><Cell><Badge tone={['resolved', 'closed'].includes(i.status) ? 'green' : 'amber'}>{words(i.status)}</Badge></Cell></tr>
                    ))}
                  </Table>
                )}
              </Card>
            </>
          )}
        </div>
      )}
    </PageShell>
  );
}
