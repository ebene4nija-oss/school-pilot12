'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
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
  Table,
  inputClass,
} from '@/components/Page';

/**
 * Exam timetables.
 *
 * Build a draft paper by paper; the API refuses a class sitting two papers at
 * once, or a teacher invigilating two rooms at once, as you add them. Publish
 * when it is right and it becomes visible to staff, students and parents.
 */

type Timetable = {
  id: number;
  name: string;
  term: string | null;
  status: 'draft' | 'published';
  sittings: number;
  starts_on: string | null;
  ends_on: string | null;
};

type Sitting = {
  id: number;
  class_id: number;
  class: string | null;
  arm_id: number | null;
  arm: string | null;
  subject_id: number;
  subject: string | null;
  paper: string | null;
  exam_date: string;
  start_time: string;
  end_time: string;
  venue: string | null;
  invigilator_id: number | null;
  invigilator: string | null;
};

type ClassOption = { id: number; name: string; arms?: { id: number; name: string }[] };
type Option = { id: number; name: string };
type Term = { id: number; name: string; session_name: string | null };
type StaffMember = { user_id: number; name: string; role: string };

const longDate = (d: string) =>
  new Date(`${d}T00:00:00`).toLocaleDateString('en-NG', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const shortDate = (d: string | null) =>
  d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' }) : '—';

export default function ExamTimetablePage() {
  const [timetables, setTimetables] = useState<Timetable[]>([]);
  const [selected, setSelected] = useState<number | null>(null);
  const [terms, setTerms] = useState<Term[]>([]);
  const [termId, setTermId] = useState('');
  const [name, setName] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Timetable[] }>('/exam-timetables');
      setTimetables(res.data ?? []);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load exam timetables.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: Term[]; current_term_id: number | null }>('/terms')
      .then((res) => {
        setTerms(res.data ?? []);
        if (res.current_term_id) setTermId(String(res.current_term_id));
      })
      .catch(() => setTerms([]));
  }, [load]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    try {
      const res = await fetchApi<{ data: { id: number } }>('/exam-timetables', {
        method: 'POST',
        body: JSON.stringify({ name: name.trim(), term_id: Number(termId) }),
      });
      setName('');
      await load();
      setSelected(res.data.id);
    } catch (err) {
      setError(errorText(err, 'Could not create that timetable.'));
    }
  };

  return (
    <PageShell title="Exam timetable" subtitle="Papers, dates, venues and invigilators — checked for clashes as you build it.">
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : (
        <div className="grid gap-6 xl:grid-cols-[20rem_1fr]">
          <div className="space-y-6">
            <Card title="Timetables">
              {timetables.length === 0 ? (
                <Empty>None yet. Create one below.</Empty>
              ) : (
                <ul className="space-y-2">
                  {timetables.map((t) => (
                    <li key={t.id}>
                      <button
                        onClick={() => setSelected(t.id)}
                        className={`w-full rounded-lg px-3 py-2.5 text-left transition ${
                          selected === t.id ? 'bg-brand text-white' : 'bg-surface-sunken text-ink hover:bg-surface-raised'
                        }`}
                      >
                        <span className="block text-sm font-semibold">{t.name}</span>
                        <span className={`block text-xs ${selected === t.id ? 'text-white/80' : 'text-ink-muted'}`}>
                          {t.term ?? ''} · {t.sittings} paper{t.sittings === 1 ? '' : 's'}
                          {t.starts_on ? ` · ${shortDate(t.starts_on)}–${shortDate(t.ends_on)}` : ''}
                          {t.status === 'draft' ? ' · draft' : ''}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card title="New timetable">
              <form onSubmit={create} className="space-y-3">
                <Field label="Name">
                  <input required value={name} onChange={(e) => setName(e.target.value)} className={inputClass} placeholder="First Term Examination" />
                </Field>
                <Field label="Term">
                  <select required value={termId} onChange={(e) => setTermId(e.target.value)} className={inputClass}>
                    <option value="">Choose…</option>
                    {terms.map((t) => (
                      <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` (${t.session_name})` : ''}</option>
                    ))}
                  </select>
                </Field>
                <Button type="submit">Create</Button>
              </form>
            </Card>
          </div>

          {selected ? (
            <Editor key={selected} id={selected} onChanged={load} onDeleted={() => { setSelected(null); void load(); }} />
          ) : (
            <Card>
              <Empty>Choose a timetable, or create one.</Empty>
            </Card>
          )}
        </div>
      )}
    </PageShell>
  );
}

function Editor({ id, onChanged, onDeleted }: { id: number; onChanged: () => void; onDeleted: () => void }) {
  const [info, setInfo] = useState<{ name: string; status: 'draft' | 'published' } | null>(null);
  const [sittings, setSittings] = useState<Sitting[]>([]);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [subjects, setSubjects] = useState<Option[]>([]);
  const [staff, setStaff] = useState<StaffMember[]>([]);
  const [filterClass, setFilterClass] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const blank = { class_id: '', arm_id: '', subject_id: '', paper: '', exam_date: '', start_time: '09:00', end_time: '11:00', venue: '', invigilator_id: '' };
  const [form, setForm] = useState(blank);
  const [editingId, setEditingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: { name: string; status: 'draft' | 'published' }; sittings: Sitting[] }>(`/exam-timetables/${id}`);
      setInfo(res.data);
      setSittings(res.sittings ?? []);
    } catch (err) {
      setError(errorText(err, 'Could not load that timetable.'));
    }
  }, [id]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: ClassOption[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: Option[] }>('/subjects').then((r) => setSubjects(r.data ?? [])).catch(() => undefined);
    fetchApi<{ data: StaffMember[] }>('/staff/directory')
      .then((r) => setStaff((r.data ?? []).filter((m) => m.role === 'teacher' || m.role === 'school_admin')))
      .catch(() => undefined);
  }, [load]);

  const arms = classes.find((c) => String(c.id) === form.class_id)?.arms ?? [];

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);

    const body = JSON.stringify({
      class_id: Number(form.class_id),
      arm_id: form.arm_id ? Number(form.arm_id) : null,
      subject_id: Number(form.subject_id),
      paper: form.paper.trim() || null,
      exam_date: form.exam_date,
      start_time: form.start_time,
      end_time: form.end_time,
      venue: form.venue.trim() || null,
      invigilator_id: form.invigilator_id ? Number(form.invigilator_id) : null,
    });

    try {
      if (editingId) {
        await fetchApi(`/exam-sittings/${editingId}`, { method: 'PUT', body });
        setNotice('Paper updated.');
      } else {
        await fetchApi(`/exam-timetables/${id}/sittings`, { method: 'POST', body });
        setNotice('Paper added.');
      }
      // Keep class, date and venue: papers are usually entered a class at a time.
      setForm({ ...form, subject_id: '', paper: '', invigilator_id: '' });
      setEditingId(null);
      await load();
      onChanged();
    } catch (err) {
      setError(errorText(err, 'That paper could not be saved.'));
    } finally {
      setBusy(false);
    }
  };

  const startEdit = (s: Sitting) => {
    setEditingId(s.id);
    setForm({
      class_id: String(s.class_id),
      arm_id: s.arm_id ? String(s.arm_id) : '',
      subject_id: String(s.subject_id),
      paper: s.paper ?? '',
      exam_date: s.exam_date,
      start_time: s.start_time,
      end_time: s.end_time,
      venue: s.venue ?? '',
      invigilator_id: s.invigilator_id ? String(s.invigilator_id) : '',
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const remove = async (s: Sitting) => {
    if (!window.confirm(`Remove ${s.subject} for ${s.class}?`)) return;
    try {
      await fetchApi(`/exam-sittings/${s.id}`, { method: 'DELETE' });
      await load();
      onChanged();
    } catch (err) {
      setError(errorText(err, 'Could not remove that paper.'));
    }
  };

  const setStatus = async (status: 'draft' | 'published') => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ message: string }>(`/exam-timetables/${id}`, { method: 'PUT', body: JSON.stringify({ status }) });
      setNotice(res.message);
      await load();
      onChanged();
    } catch (err) {
      setError(errorText(err, 'Could not change that.'));
    } finally {
      setBusy(false);
    }
  };

  const destroy = async () => {
    if (!window.confirm('Delete this draft timetable and all its papers?')) return;
    try {
      await fetchApi(`/exam-timetables/${id}`, { method: 'DELETE' });
      onDeleted();
    } catch (err) {
      setError(errorText(err, 'Could not delete it.'));
    }
  };

  const byDate = useMemo(() => {
    const shown = filterClass ? sittings.filter((s) => String(s.class_id) === filterClass) : sittings;
    const groups = new Map<string, Sitting[]>();
    shown.forEach((s) => groups.set(s.exam_date, [...(groups.get(s.exam_date) ?? []), s]));
    return [...groups.entries()];
  }, [sittings, filterClass]);

  if (!info) return error ? <ErrorNote message={error} /> : <Loading />;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div className="flex items-center gap-3">
          <h2 className="font-headline text-lg font-bold text-brand">{info.name}</h2>
          <Badge tone={info.status === 'published' ? 'green' : 'amber'}>{info.status}</Badge>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => window.print()}>Print</Button>
          {info.status === 'draft' ? (
            <>
              <Button variant="secondary" onClick={destroy}>Delete draft</Button>
              <Button variant="accent" disabled={busy} onClick={() => setStatus('published')}>Publish</Button>
            </>
          ) : (
            <Button variant="secondary" disabled={busy} onClick={() => setStatus('draft')}>Unpublish</Button>
          )}
        </div>
      </div>

      <div className="print:hidden">
        <ErrorNote message={error} />
        {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      </div>

      <div className="print:hidden">
        <Card title={editingId ? 'Edit paper' : 'Add a paper'}>
          <form onSubmit={save} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Class">
              <select required value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value, arm_id: '' })} className={inputClass}>
                <option value="">Choose…</option>
                {classes.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="Arm">
              <select value={form.arm_id} disabled={arms.length === 0} onChange={(e) => setForm({ ...form, arm_id: e.target.value })} className={`${inputClass} disabled:opacity-50`}>
                <option value="">All arms</option>
                {arms.map((a) => (
                  <option key={a.id} value={a.id}>{a.name}</option>
                ))}
              </select>
            </Field>
            <Field label="Subject">
              <select required value={form.subject_id} onChange={(e) => setForm({ ...form, subject_id: e.target.value })} className={inputClass}>
                <option value="">Choose…</option>
                {subjects.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </Field>
            <Field label="Paper (optional)">
              <input value={form.paper} onChange={(e) => setForm({ ...form, paper: e.target.value })} className={inputClass} placeholder="Paper 1 (Objective)" />
            </Field>
            <Field label="Date">
              <input required type="date" value={form.exam_date} onChange={(e) => setForm({ ...form, exam_date: e.target.value })} className={inputClass} />
            </Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="Starts">
                <input required type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} className={inputClass} />
              </Field>
              <Field label="Ends">
                <input required type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} className={inputClass} />
              </Field>
            </div>
            <Field label="Venue (optional)">
              <input value={form.venue} onChange={(e) => setForm({ ...form, venue: e.target.value })} className={inputClass} placeholder="Main hall" />
            </Field>
            <Field label="Invigilator (optional)">
              <select value={form.invigilator_id} onChange={(e) => setForm({ ...form, invigilator_id: e.target.value })} className={inputClass}>
                <option value="">—</option>
                {staff.map((m) => (
                  <option key={m.user_id} value={m.user_id}>{m.name}</option>
                ))}
              </select>
            </Field>
            <div className="flex gap-2 sm:col-span-2 lg:col-span-4">
              <Button type="submit" disabled={busy}>{editingId ? 'Save changes' : 'Add paper'}</Button>
              {editingId && (
                <Button variant="secondary" onClick={() => { setEditingId(null); setForm(blank); }}>Cancel</Button>
              )}
            </div>
          </form>
        </Card>
      </div>

      <Card title="Papers">
        <div className="mb-4 print:hidden">
          <select value={filterClass} onChange={(e) => setFilterClass(e.target.value)} className={`${inputClass} max-w-xs`}>
            <option value="">Every class</option>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        </div>

        {byDate.length === 0 ? (
          <Empty>No papers yet.</Empty>
        ) : (
          <div className="space-y-6">
            {byDate.map(([date, rows]) => (
              <div key={date}>
                <h3 className="mb-2 text-sm font-bold text-ink">{longDate(date)}</h3>
                <Table columns={['Time', 'Class', 'Subject', 'Venue', 'Invigilator', '']}>
                  {rows.map((s) => (
                    <tr key={s.id}>
                      <Cell className="whitespace-nowrap font-mono text-xs">{s.start_time}–{s.end_time}</Cell>
                      <Cell>{[s.class, s.arm].filter(Boolean).join(' ')}</Cell>
                      <Cell className="font-medium text-brand">
                        {s.subject}
                        {s.paper && <span className="block text-xs font-normal text-ink-muted">{s.paper}</span>}
                      </Cell>
                      <Cell>{s.venue ?? '—'}</Cell>
                      <Cell>{s.invigilator ?? '—'}</Cell>
                      <Cell>
                        <div className="flex gap-2 print:hidden">
                          <Button variant="secondary" onClick={() => startEdit(s)}>Edit</Button>
                          <Button variant="secondary" onClick={() => remove(s)}>Remove</Button>
                        </div>
                      </Cell>
                    </tr>
                  ))}
                </Table>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
