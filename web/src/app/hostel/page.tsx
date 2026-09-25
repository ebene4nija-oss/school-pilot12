'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * Hostel rooms and who sleeps in them.
 *
 * Was two hardcoded rooms — "Unity House RM-101, 3 of 4 beds" — with an
 * "+ Assign Student Bed" button that did nothing. Occupancy is now counted from
 * real bed assignments, and the allocation rules the API gained (one student per
 * bed, one bed per student, capacity respected) surface here as errors rather
 * than as silently accepted double-bookings.
 */

interface Room {
  id: number;
  hostel_name: string;
  room_number: string;
  capacity: number;
  occupied: number;
  beds_free: number;
  is_full: boolean;
  is_over_capacity: boolean;
}

interface Assignment {
  id: number;
  bed_number: string;
  room_id: number;
  hostel_name: string;
  room_number: string;
  student_id: number;
  student_name: string;
  admission_number: string | null;
}

/** As `/students` returns one: the user relation is already flattened. */
interface StudentOption {
  id: number;
  admission_number: string | null;
  name?: string | null;
}

export default function HostelPage() {
  const [rooms, setRooms] = useState<Room[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [students, setStudents] = useState<StudentOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [panel, setPanel] = useState<'none' | 'room' | 'bed'>('none');

  const [hostelName, setHostelName] = useState('');
  const [roomNumber, setRoomNumber] = useState('');
  const [capacity, setCapacity] = useState('4');

  const [bedRoomId, setBedRoomId] = useState('');
  const [bedStudentId, setBedStudentId] = useState('');
  const [bedNumber, setBedNumber] = useState('');

  const load = useCallback(async () => {
    const [roomRes, bedRes] = await Promise.all([
      fetchApi<{ data: Room[] }>('/hostel/rooms'),
      fetchApi<{ data: Assignment[] }>('/hostel/bed-assignments'),
    ]);

    setRooms(roomRes.data ?? []);
    setAssignments(bedRes.data ?? []);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        await load();
        const roll = await fetchApi<{ data?: StudentOption[] }>('/students').catch(() => null);
        setStudents(roll?.data ?? []);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load hostel data.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function createRoom(e: React.FormEvent) {
    e.preventDefault();
    await run(async () => {
      await fetchApi('/hostel/rooms', {
        method: 'POST',
        body: JSON.stringify({
          hostel_name: hostelName.trim(),
          room_number: roomNumber.trim(),
          capacity: Number(capacity),
        }),
      });

      setNotice(`${hostelName.trim()} ${roomNumber.trim()} created.`);
      setHostelName('');
      setRoomNumber('');
      setPanel('none');
    });
  }

  async function assignBed(e: React.FormEvent) {
    e.preventDefault();
    await run(async () => {
      await fetchApi('/hostel/assign-bed', {
        method: 'POST',
        body: JSON.stringify({
          room_id: Number(bedRoomId),
          student_id: Number(bedStudentId),
          bed_number: bedNumber.trim(),
        }),
      });

      setNotice('Bed assigned.');
      setBedStudentId('');
      setBedNumber('');
      setPanel('none');
    });
  }

  async function release(assignment: Assignment) {
    await run(async () => {
      await fetchApi(`/hostel/bed-assignments/${assignment.id}`, { method: 'DELETE' });
      setNotice(`${assignment.student_name} released from ${assignment.room_number}.`);
    });
  }

  /** One place for the busy flag, the reload and the error surface. */
  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      await action();
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusy(false);
    }
  }

  const nameOf = (s: StudentOption) => s.name ?? `Student #${s.id}`;

  const beds = rooms.reduce((sum, r) => sum + r.capacity, 0);
  const filled = rooms.reduce((sum, r) => sum + r.occupied, 0);

  return (
    <PageShell
      title="Hostel"
      subtitle="Rooms, bed allocation and occupancy."
      actions={
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setPanel(panel === 'room' ? 'none' : 'room')}>
            Add room
          </Button>
          <Button onClick={() => setPanel(panel === 'bed' ? 'none' : 'bed')} disabled={rooms.length === 0}>
            Assign a bed
          </Button>
        </div>
      }
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-3">
            <StatTile label="Rooms" value={rooms.length} />
            <StatTile label="Beds" value={beds} hint={`${beds - filled} free`} />
            <StatTile
              label="Boarders placed"
              value={filled}
              tone={beds > 0 && filled >= beds ? 'amber' : 'green'}
            />
          </div>

          {panel === 'room' && (
            <Card title="Add a room">
              <form onSubmit={createRoom} className="grid gap-4 sm:grid-cols-3">
                <Field label="Hostel">
                  <input
                    required
                    value={hostelName}
                    onChange={(e) => setHostelName(e.target.value)}
                    className={inputClass}
                    placeholder="e.g. Unity House"
                  />
                </Field>
                <Field label="Room number">
                  <input
                    required
                    value={roomNumber}
                    onChange={(e) => setRoomNumber(e.target.value)}
                    className={inputClass}
                    placeholder="e.g. RM-101"
                  />
                </Field>
                <Field label="Beds">
                  <input
                    required
                    type="number"
                    min={1}
                    max={64}
                    value={capacity}
                    onChange={(e) => setCapacity(e.target.value)}
                    className={inputClass}
                  />
                </Field>
                <div className="sm:col-span-3">
                  <Button type="submit" disabled={busy}>
                    {busy ? 'Saving…' : 'Create room'}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          {panel === 'bed' && (
            <Card title="Assign a bed">
              <form onSubmit={assignBed} className="grid gap-4 sm:grid-cols-3">
                <Field label="Room">
                  <select
                    required
                    value={bedRoomId}
                    onChange={(e) => setBedRoomId(e.target.value)}
                    className={inputClass}
                  >
                    <option value="">Select a room…</option>
                    {rooms.map((room) => (
                      <option key={room.id} value={room.id} disabled={room.is_full}>
                        {room.hostel_name} {room.room_number} — {room.beds_free} free
                        {room.is_full ? ' (full)' : ''}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Student">
                  <select
                    required
                    value={bedStudentId}
                    onChange={(e) => setBedStudentId(e.target.value)}
                    className={inputClass}
                  >
                    <option value="">Select a student…</option>
                    {students.map((s) => (
                      <option key={s.id} value={s.id}>
                        {nameOf(s)}
                        {s.admission_number ? ` (${s.admission_number})` : ''}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Bed">
                  <input
                    required
                    value={bedNumber}
                    onChange={(e) => setBedNumber(e.target.value)}
                    className={inputClass}
                    placeholder="e.g. B1"
                  />
                </Field>
                <div className="sm:col-span-3">
                  <Button type="submit" disabled={busy || !bedRoomId || !bedStudentId}>
                    {busy ? 'Saving…' : 'Assign bed'}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          <Card title="Rooms">
            {rooms.length === 0 ? (
              <Empty>No rooms yet. Add one to start placing boarders.</Empty>
            ) : (
              <Table columns={['Hostel', 'Room', 'Occupancy', 'Status']}>
                {rooms.map((room) => (
                  <tr key={room.id}>
                    <Cell className="font-medium text-brand">{room.hostel_name}</Cell>
                    <Cell className="font-mono text-xs">{room.room_number}</Cell>
                    <Cell>
                      {room.occupied} of {room.capacity} bed(s)
                    </Cell>
                    <Cell>
                      {room.is_over_capacity ? (
                        <Badge tone="red">Over capacity</Badge>
                      ) : room.is_full ? (
                        <Badge tone="amber">Full</Badge>
                      ) : (
                        <Badge tone="green">{room.beds_free} free</Badge>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          <Card title="Boarders">
            {assignments.length === 0 ? (
              <Empty>No students are placed in a bed yet.</Empty>
            ) : (
              <Table columns={['Student', 'Hostel', 'Room', 'Bed', '']}>
                {assignments.map((a) => (
                  <tr key={a.id}>
                    <Cell className="font-medium text-brand">
                      {a.student_name}
                      {a.admission_number && (
                        <span className="block text-xs text-ink-muted">{a.admission_number}</span>
                      )}
                    </Cell>
                    <Cell>{a.hostel_name}</Cell>
                    <Cell className="font-mono text-xs">{a.room_number}</Cell>
                    <Cell>{a.bed_number}</Cell>
                    <Cell className="text-right">
                      <Button variant="secondary" disabled={busy} onClick={() => release(a)}>
                        Release
                      </Button>
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
