'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { useCookieRole } from '@/components/Sidebar';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Table, inputClass } from '@/components/Page';

/**
 * Cover for absent teachers (TASK 07). Teachers on approved leave, or marked
 * absent for the day, have their lessons listed here with the teachers free
 * at that time (subject teachers first). A teacher sees the cover they have
 * been asked to take.
 */

interface Lesson { entry_id: number; class: string | null; subject: string | null; slot: string; start_time: string | null; absent_teacher: string | null; cover: { id: number; teacher: string | null } | null; suggestions: { id: number; name: string; teaches_subject: boolean; lessons_today: number }[] }
interface Sheet { date: string; absent: { id: number; name: string | null }[]; lessons: Lesson[]; recorded_absences: { id: number; name: string; reason: string }[] }
interface Duty { id: number; date: string; class: string | null; subject_name: string | null; slot_name: string; start_time: string | null; covering_for: string | null }
interface Teacher { id: number; name: string }

export default function TimetableCover() {
  const role = useCookieRole();
  const admin = role === 'school_admin';
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [duties, setDuties] = useState<Duty[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [absentee, setAbsentee] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      if (admin) setSheet(await fetchApi<Sheet>(`/timetable/cover?date=${date}`));
      setDuties((await fetchApi<{ data: Duty[] }>('/timetable/my-cover')).data);
    } catch (err) {
      setError(errorText(err, 'Could not load cover.'));
    }
  }, [admin, date]);

  useEffect(() => {
    (async () => {
      await load();
    })();
  }, [load]);

  useEffect(() => {
    if (!admin) return;
    fetchApi<{ data?: { user_id: number; name: string; role: string }[] }>('/staff?role=teacher&per_page=200')
      .then((r) => setTeachers((r.data ?? []).filter((t) => t.role === 'teacher').map((t) => ({ id: t.user_id, name: t.name }))))
      .catch(() => undefined);
  }, [admin]);

  async function post(path: string, body: unknown, done: string) {
    try {
      const res = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(res?.message ?? done);
      setError(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    }
  }

  return (
    <div className="mt-6 space-y-5">
      <ErrorNote message={error} />
      {notice && <p className="rounded-lg bg-positive-soft px-4 py-2 text-sm text-positive">{notice}</p>}

      {duties.length > 0 && (
        <Card title="My cover lessons">
          <ul className="space-y-1 text-sm">
            {duties.map((d) => <li key={d.id}>{d.date} · {d.start_time?.slice(0, 5) ?? d.slot_name} · {d.class} {d.subject_name}{d.covering_for && <span className="text-ink-muted"> (for {d.covering_for})</span>}</li>)}
          </ul>
        </Card>
      )}

      {admin && (
        <Card title="Cover for absent teachers" description="Teachers on approved leave or marked absent appear automatically. Suggestions are free at that time; subject teachers come first.">
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <Field label="Date"><input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <Field label="Mark a teacher absent">
              <select className={inputClass} value={absentee} onChange={(e) => setAbsentee(e.target.value)}>
                <option value="">Choose…</option>
                {teachers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </Field>
            <Button variant="secondary" disabled={!absentee} onClick={() => { post('/timetable/absences', { teacher_id: Number(absentee), date, reason: 'sick' }, 'Absence recorded.'); setAbsentee(''); }}>Mark absent</Button>
          </div>
          {sheet && (sheet.lessons.length === 0 ? <Empty>No lessons need cover on {sheet.date}.</Empty> : (
            <Table columns={['Lesson', 'Teacher away', 'Cover']}>
              {sheet.lessons.map((l) => (
                <tr key={l.entry_id}>
                  <Cell>{l.start_time?.slice(0, 5) ?? l.slot} · {l.class} {l.subject}</Cell>
                  <Cell>{l.absent_teacher}</Cell>
                  <Cell>
                    {l.cover ? (
                      <span className="flex items-center gap-2"><Badge tone="green">{l.cover.teacher}</Badge><button type="button" className="text-xs text-ink-muted underline" onClick={() => post(`/timetable/substitutions/${l.cover!.id}/cancel`, {}, 'Cover cancelled.')}>cancel</button></span>
                    ) : l.suggestions.length === 0 ? <Badge tone="red">Nobody free</Badge> : (
                      <select className={inputClass} value="" aria-label="Choose cover teacher" onChange={(e) => e.target.value && post('/timetable/substitutions', { date, entry_id: l.entry_id, substitute_teacher_id: Number(e.target.value) }, 'Cover arranged.')}>
                        <option value="">Choose cover…</option>
                        {l.suggestions.map((s) => <option key={s.id} value={s.id}>{s.name}{s.teaches_subject ? ' (teaches this)' : ''} · {s.lessons_today} lessons today</option>)}
                      </select>
                    )}
                  </Cell>
                </tr>
              ))}
            </Table>
          ))}
        </Card>
      )}
    </div>
  );
}
