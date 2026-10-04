'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, Field, Table, inputClass } from '@/components/Page';
import { roleFromCookie } from '@/components/Sidebar';

/**
 * Where lessons happen (TASK 07). Admins keep the list of classrooms, labs and
 * halls; a teacher picks where each of their lessons is held — every week,
 * or for one day. A venue another class has at that time is refused.
 */

interface Venue { id: number; name: string; kind: string; capacity: number | null; class_id: number | null; shared: boolean; active: boolean }
interface Lesson { id: number; class_name: string | null; day_of_week: string; slot_name: string; start_time: string | null; subject_name: string | null; venue_name: string | null; is_break: boolean | number }
interface Option { id: number; name: string }

const KINDS = ['classroom', 'lab', 'hall', 'library', 'field', 'other'];
const DAY_ORDER = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export default function TimetableVenues() {
  const [admin, setAdmin] = useState(false);
  const [venues, setVenues] = useState<Venue[]>([]);
  const [lessons, setLessons] = useState<Lesson[]>([]);
  const [classes, setClasses] = useState<Option[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', kind: 'classroom', capacity: '', class_id: '', shared: false });
  const [moving, setMoving] = useState<{ lesson: Lesson; free: Venue[]; date: string; venue: string } | null>(null);

  const fetchAll = useCallback(async (isAdmin: boolean) => {
    const [v, l, c] = await Promise.all([
      fetchApi<{ data: Venue[] }>('/venues'),
      fetchApi<{ entries: Lesson[] }>('/timetable/view?mine=1'),
      isAdmin ? fetchApi<{ data?: Option[] }>('/classes') : Promise.resolve({ data: [] as Option[] }),
    ]);
    return { venues: v.data, lessons: l.entries.filter((e) => !e.is_break), classes: c.data ?? [] };
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const isAdmin = roleFromCookie() === 'school_admin';
      try {
        const d = await fetchAll(isAdmin);
        if (!cancelled) { setAdmin(isAdmin); setVenues(d.venues); setLessons(d.lessons); setClasses(d.classes); }
      } catch (err) {
        if (!cancelled) setError(errorText(err, 'Could not load venues.'));
      }
    })();
    return () => { cancelled = true; };
  }, [fetchAll]);

  async function run(fn: () => Promise<string>) {
    setError(null);
    setNotice(null);
    try {
      setNotice(await fn());
      const d = await fetchAll(admin);
      setVenues(d.venues);
      setLessons(d.lessons);
    } catch (err) {
      setError(errorText(err, 'That did not work.'));
    }
  }

  async function startMove(lesson: Lesson) {
    try {
      const free = (await fetchApi<{ data: Venue[] }>(`/timetable/entries/${lesson.id}/free-venues`)).data;
      setMoving({ lesson, free, date: '', venue: '' });
    } catch (err) {
      setError(errorText(err, 'Could not load free venues.'));
    }
  }

  async function changeDate(date: string) {
    if (!moving) return;
    const free = (await fetchApi<{ data: Venue[] }>(`/timetable/entries/${moving.lesson.id}/free-venues${date ? `?date=${date}` : ''}`)).data;
    setMoving({ ...moving, date, free });
  }

  const sorted = [...lessons].sort((a, b) => DAY_ORDER.indexOf(a.day_of_week) - DAY_ORDER.indexOf(b.day_of_week) || (a.start_time ?? a.slot_name).localeCompare(b.start_time ?? b.slot_name));

  return (
    <div className="mt-6 space-y-6">
      {error && <p role="alert" className="rounded-lg bg-negative-soft px-4 py-3 text-sm text-negative">{error}</p>}
      {notice && <p role="status" className="rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {lessons.length > 0 && (
        <Card title="Where my lessons are" description="A lesson with no venue set is in the class's own room.">
          <Table columns={['Day', 'Period', 'Class', 'Subject', 'Venue', '']}>
            {sorted.map((l) => (
              <tr key={l.id}>
                <Cell>{l.day_of_week}</Cell>
                <Cell>{l.slot_name}{l.start_time && <span className="block text-xs text-ink-muted">{l.start_time.slice(0, 5)}</span>}</Cell>
                <Cell>{l.class_name}</Cell>
                <Cell>{l.subject_name}</Cell>
                <Cell>{l.venue_name ?? <span className="text-ink-faint">not set</span>}</Cell>
                <Cell><Button variant="secondary" onClick={() => startMove(l)}>Change</Button></Cell>
              </tr>
            ))}
          </Table>

          {moving && (
            <form
              className="mt-4 grid gap-3 rounded-lg border border-line-soft p-4 sm:grid-cols-3"
              onSubmit={(e) => {
                e.preventDefault();
                const { lesson, date, venue } = moving;
                run(async () => {
                  const venueId = venue ? Number(venue) : null;
                  const res = date
                    ? await fetchApi<{ message: string }>(`/timetable/entries/${lesson.id}/venue-changes`, { method: 'POST', body: JSON.stringify({ date, venue_id: venueId }) })
                    : await fetchApi<{ message: string }>(`/timetable/entries/${lesson.id}/venue`, { method: 'PUT', body: JSON.stringify({ venue_id: venueId }) });
                  setMoving(null);
                  return res.message;
                });
              }}
            >
              <p className="text-sm sm:col-span-3">{moving.lesson.subject_name}, {moving.lesson.class_name}, {moving.lesson.day_of_week} {moving.lesson.slot_name}</p>
              <Field label="When">
                <input type="date" value={moving.date} onChange={(e) => changeDate(e.target.value)} className={inputClass} />
                <span className="text-xs text-ink-muted">{moving.date ? 'That day only' : 'Leave empty for every week'}</span>
              </Field>
              <Field label="Venue (free at that time)">
                <select value={moving.venue} onChange={(e) => setMoving({ ...moving, venue: e.target.value })} className={inputClass}>
                  <option value="">{moving.date ? 'Usual venue' : "Class's own room"}</option>
                  {moving.free.map((v) => <option key={v.id} value={v.id}>{v.name}{v.shared ? ' (shared)' : ''}</option>)}
                </select>
              </Field>
              <div className="flex items-end gap-2">
                <Button type="submit">Save</Button>
                <Button type="button" variant="secondary" onClick={() => setMoving(null)}>Cancel</Button>
              </div>
            </form>
          )}
        </Card>
      )}

      <Card title="Venues" description="Classrooms, labs, halls and fields. Two classes can't be in one venue at once unless it is shared.">
        {venues.length === 0 ? <Empty>No venues yet.{admin ? ' Add the school’s rooms below.' : ''}</Empty> : (
          <Table columns={['Venue', 'Kind', 'Capacity', 'Home room of', '']}>
            {venues.map((v) => (
              <tr key={v.id}>
                <Cell className="font-medium">{v.name}</Cell>
                <Cell>{v.kind}</Cell>
                <Cell>{v.capacity ?? '—'}</Cell>
                <Cell>{classes.find((c) => c.id === v.class_id)?.name ?? (v.class_id ? `Class ${v.class_id}` : '—')}</Cell>
                <Cell>{v.shared && <Badge tone="blue">shared</Badge>} {!v.active && <Badge>not in use</Badge>}</Cell>
              </tr>
            ))}
          </Table>
        )}

        {admin && (
          <form
            className="mt-4 grid gap-3 sm:grid-cols-5"
            onSubmit={(e) => {
              e.preventDefault();
              run(async () => {
                await fetchApi('/venues', {
                  method: 'POST',
                  body: JSON.stringify({ name: form.name.trim(), kind: form.kind, capacity: form.capacity ? Number(form.capacity) : null, class_id: form.class_id ? Number(form.class_id) : null, shared: form.shared }),
                });
                setForm({ name: '', kind: 'classroom', capacity: '', class_id: '', shared: false });
                return 'Venue added.';
              });
            }}
          >
            <Field label="Name"><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="e.g. Chemistry Lab" className={inputClass} /></Field>
            <Field label="Kind">
              <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })} className={inputClass}>{KINDS.map((k) => <option key={k}>{k}</option>)}</select>
            </Field>
            <Field label="Capacity"><input type="number" min={1} value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} className={inputClass} /></Field>
            <Field label="Home room of">
              <select value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })} className={inputClass}>
                <option value="">None</option>
                {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <div className="flex items-end gap-3">
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.shared} onChange={(e) => setForm({ ...form, shared: e.target.checked })} /> Shared</label>
              <Button type="submit" disabled={!form.name.trim()}>Add</Button>
            </div>
          </form>
        )}
      </Card>
    </div>
  );
}
