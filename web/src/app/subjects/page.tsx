'use client';

import React, { useCallback, useEffect, useState } from 'react';
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
 * Subjects: what the school teaches, which classes take what, and who teaches it.
 *
 * Subjects could be created only from the setup wizard, and nothing showed
 * the class offerings or teacher assignments the API already stored — so
 * "who takes JSS 2 Mathematics?" had no answer in the portal.
 */

type Subject = {
  id: number;
  name: string;
  code: string | null;
  category: 'core' | 'elective';
  department: string | null;
  is_active: boolean;
};

type ClassOption = { id: number; name: string };

type ClassSubject = { id: number; name: string; code: string | null; is_compulsory: boolean };

type Assignment = {
  id: number;
  subject_id: number;
  subject_name: string;
  teacher_id: number;
  teacher_name: string;
  class_id: number | null;
  class_name: string | null;
};

type StaffMember = { user_id: number; name: string; role: string };

type Tab = 'subjects' | 'classes' | 'teachers';

const DEPARTMENTS = ['general', 'science', 'arts', 'commercial'];

export default function SubjectsPage() {
  const [tab, setTab] = useState<Tab>('subjects');
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadSubjects = useCallback(async () => {
    try {
      const [subs, cls] = await Promise.all([
        fetchApi<{ data: Subject[] }>('/subjects?include_inactive=1'),
        fetchApi<{ data: ClassOption[] }>('/classes'),
      ]);
      setSubjects(subs.data ?? []);
      setClasses(cls.data ?? []);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load subjects.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await loadSubjects();
    })();
  }, [loadSubjects]);

  const active = subjects.filter((s) => s.is_active);

  return (
    <PageShell title="Subjects" subtitle="What the school teaches, which classes take it, and who teaches it.">
      <ErrorNote message={error} />

      <div role="tablist" className="mb-6 flex flex-wrap gap-2">
        {([
          ['subjects', `Subjects (${active.length})`],
          ['classes', 'Subjects per class'],
          ['teachers', 'Who teaches what'],
        ] as [Tab, string][]).map(([id, label]) => (
          <button
            key={id}
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`rounded-lg px-3.5 py-2 text-sm font-medium transition ${
              tab === id ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {loading ? (
        <Loading />
      ) : (
        <>
          {tab === 'subjects' && <SubjectList subjects={subjects} onChanged={loadSubjects} />}
          {tab === 'classes' && <ClassSubjects classes={classes} subjects={active} />}
          {tab === 'teachers' && <Teachers classes={classes} subjects={active} />}
        </>
      )}
    </PageShell>
  );
}

function SubjectList({ subjects, onChanged }: { subjects: Subject[]; onChanged: () => void }) {
  const blank = { name: '', code: '', category: 'core', department: 'general' };
  const [form, setForm] = useState(blank);
  const [editing, setEditing] = useState<number | null>(null);
  const [edit, setEdit] = useState(blank);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await fetchApi('/subjects', {
        method: 'POST',
        body: JSON.stringify({ ...form, code: form.code.trim() || null, name: form.name.trim() }),
      });
      setForm(blank);
      onChanged();
    } catch (err) {
      setError(errorText(err, 'Could not add that subject.'));
    } finally {
      setBusy(false);
    }
  };

  const save = async (id: number, body: Record<string, unknown>) => {
    setBusy(true);
    setError(null);

    try {
      await fetchApi(`/subjects/${id}`, { method: 'PUT', body: JSON.stringify(body) });
      setEditing(null);
      onChanged();
    } catch (err) {
      setError(errorText(err, 'Could not save that subject.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-6">
      <Card title="Add a subject">
        <form onSubmit={create} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <div className="lg:col-span-2">
            <Field label="Name">
              <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} placeholder="e.g. Basic Science" />
            </Field>
          </div>
          <Field label="Code (optional)">
            <input value={form.code} maxLength={10} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} className={inputClass} placeholder="BSC" />
          </Field>
          <Field label="Type">
            <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className={inputClass}>
              <option value="core">Core</option>
              <option value="elective">Elective</option>
            </select>
          </Field>
          <Field label="Department">
            <select value={form.department} onChange={(e) => setForm({ ...form, department: e.target.value })} className={inputClass}>
              {DEPARTMENTS.map((d) => (
                <option key={d} value={d} className="capitalize">{d[0].toUpperCase() + d.slice(1)}</option>
              ))}
            </select>
          </Field>
          <div className="sm:col-span-2 lg:col-span-5">
            <Button type="submit" disabled={busy}>Add subject</Button>
          </div>
        </form>
      </Card>

      <ErrorNote message={error} />

      <Card title="All subjects" description="A subject that is no longer offered is switched off, not deleted, so past results keep it.">
        {subjects.length === 0 ? (
          <Empty>No subjects yet.</Empty>
        ) : (
          <Table columns={['Subject', 'Code', 'Type', 'Department', 'Status', '']}>
            {subjects.map((s) =>
              editing === s.id ? (
                <tr key={s.id}>
                  <Cell>
                    <input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} className={inputClass} />
                  </Cell>
                  <Cell>
                    <input value={edit.code} maxLength={10} onChange={(e) => setEdit({ ...edit, code: e.target.value.toUpperCase() })} className={`${inputClass} w-24`} />
                  </Cell>
                  <Cell>
                    <select value={edit.category} onChange={(e) => setEdit({ ...edit, category: e.target.value })} className={inputClass}>
                      <option value="core">Core</option>
                      <option value="elective">Elective</option>
                    </select>
                  </Cell>
                  <Cell>
                    <select value={edit.department} onChange={(e) => setEdit({ ...edit, department: e.target.value })} className={inputClass}>
                      {DEPARTMENTS.map((d) => (
                        <option key={d} value={d}>{d[0].toUpperCase() + d.slice(1)}</option>
                      ))}
                    </select>
                  </Cell>
                  <Cell>{null}</Cell>
                  <Cell>
                    <div className="flex gap-2">
                      <Button disabled={busy} onClick={() => save(s.id, { name: edit.name.trim(), code: edit.code.trim() || null, category: edit.category, department: edit.department })}>
                        Save
                      </Button>
                      <Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                    </div>
                  </Cell>
                </tr>
              ) : (
                <tr key={s.id} className={s.is_active ? '' : 'opacity-60'}>
                  <Cell className="font-medium text-brand">{s.name}</Cell>
                  <Cell className="font-mono text-xs">{s.code ?? '—'}</Cell>
                  <Cell className="capitalize">{s.category}</Cell>
                  <Cell className="capitalize">{s.department ?? '—'}</Cell>
                  <Cell>
                    <Badge tone={s.is_active ? 'green' : 'slate'}>{s.is_active ? 'Offered' : 'Switched off'}</Badge>
                  </Cell>
                  <Cell>
                    <div className="flex gap-2">
                      <Button
                        variant="secondary"
                        onClick={() => {
                          setEditing(s.id);
                          setEdit({ name: s.name, code: s.code ?? '', category: s.category, department: s.department ?? 'general' });
                        }}
                      >
                        Edit
                      </Button>
                      <Button variant="secondary" disabled={busy} onClick={() => save(s.id, { is_active: !s.is_active })}>
                        {s.is_active ? 'Switch off' : 'Switch on'}
                      </Button>
                    </div>
                  </Cell>
                </tr>
              ),
            )}
          </Table>
        )}
      </Card>
    </div>
  );
}

function ClassSubjects({ classes, subjects }: { classes: ClassOption[]; subjects: Subject[] }) {
  const [classId, setClassId] = useState(classes[0] ? String(classes[0].id) : '');
  const [offered, setOffered] = useState<ClassSubject[]>([]);
  const [picked, setPicked] = useState<number[]>([]);
  const [compulsory, setCompulsory] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (id: string) => {
    if (!id) return;
    try {
      const res = await fetchApi<{ compulsory: ClassSubject[]; elective: ClassSubject[] }>(`/classes/${id}/subjects`);
      setOffered([...res.compulsory, ...res.elective]);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load that class.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load(classId);
    })();
  }, [load, classId]);

  const add = async () => {
    if (picked.length === 0) return;
    setBusy(true);
    setError(null);

    try {
      await fetchApi('/classes/subjects', {
        method: 'POST',
        body: JSON.stringify({ class_id: Number(classId), subjects: picked.map((id) => ({ subject_id: id, is_compulsory: compulsory })) }),
      });
      setPicked([]);
      await load(classId);
    } catch (err) {
      setError(errorText(err, 'Could not add those subjects.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (subjectId: number) => {
    setBusy(true);
    try {
      await fetchApi(`/classes/${classId}/subjects/${subjectId}`, { method: 'DELETE' });
      await load(classId);
    } catch (err) {
      setError(errorText(err, 'Could not remove that subject.'));
    } finally {
      setBusy(false);
    }
  };

  if (classes.length === 0) return <Empty>No classes yet. Set them up in the setup checklist first.</Empty>;

  const notOffered = subjects.filter((s) => !offered.some((o) => o.id === s.id));

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Card title="Offered in this class">
        <select value={classId} onChange={(e) => setClassId(e.target.value)} className={`${inputClass} mb-4 max-w-xs`}>
          {classes.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </select>
        <ErrorNote message={error} />
        {offered.length === 0 ? (
          <Empty>This class offers no subjects yet.</Empty>
        ) : (
          <Table columns={['Subject', 'Type', '']}>
            {offered.map((s) => (
              <tr key={s.id}>
                <Cell className="font-medium text-brand">{s.name}</Cell>
                <Cell>
                  <Badge tone={s.is_compulsory ? 'blue' : 'amber'}>{s.is_compulsory ? 'Compulsory' : 'Elective'}</Badge>
                </Cell>
                <Cell>
                  <Button variant="secondary" disabled={busy} onClick={() => remove(s.id)}>Remove</Button>
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card title="Add subjects to this class">
        {notOffered.length === 0 ? (
          <Empty>Every subject is already offered here.</Empty>
        ) : (
          <>
            <div className="mb-4 grid gap-2 sm:grid-cols-2">
              {notOffered.map((s) => (
                <label key={s.id} className="flex items-center gap-2 text-sm text-ink">
                  <input
                    type="checkbox"
                    className="h-4 w-4 accent-brand"
                    checked={picked.includes(s.id)}
                    onChange={(e) => setPicked((p) => (e.target.checked ? [...p, s.id] : p.filter((x) => x !== s.id)))}
                  />
                  {s.name}
                </label>
              ))}
            </div>
            <div className="flex flex-wrap items-center gap-4">
              <select value={compulsory ? '1' : '0'} onChange={(e) => setCompulsory(e.target.value === '1')} className={`${inputClass} max-w-[12rem]`}>
                <option value="1">As compulsory</option>
                <option value="0">As electives</option>
              </select>
              <Button disabled={busy || picked.length === 0} onClick={add}>
                Add {picked.length || ''} subject{picked.length === 1 ? '' : 's'}
              </Button>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}

function Teachers({ classes, subjects }: { classes: ClassOption[]; subjects: Subject[] }) {
  const [rows, setRows] = useState<Assignment[]>([]);
  const [staff, setStaff] = useState<StaffMember[]>([]);
  const [form, setForm] = useState({ teacher_id: '', subject_id: '', class_id: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetchApi<{ data: Assignment[] }>('/subjects/assignments');
      setRows(res.data ?? []);
    } catch (err) {
      setError(errorText(err, 'Could not load teaching assignments.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
    fetchApi<{ data: StaffMember[] }>('/staff/directory')
      .then((res) => setStaff((res.data ?? []).filter((m) => m.role === 'teacher' || m.role === 'school_admin')))
      .catch(() => setStaff([]));
  }, [load]);

  const assign = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await fetchApi('/subjects/assign-teacher', {
        method: 'POST',
        body: JSON.stringify({
          teacher_id: Number(form.teacher_id),
          subject_id: Number(form.subject_id),
          class_id: form.class_id ? Number(form.class_id) : null,
        }),
      });
      setForm({ ...form, class_id: '' });
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not make that assignment.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (row: Assignment) => {
    setBusy(true);
    try {
      await fetchApi('/subjects/remove-teacher', {
        method: 'POST',
        body: JSON.stringify({ teacher_id: row.teacher_id, subject_id: row.subject_id, class_id: row.class_id }),
      });
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not remove that assignment.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-6">
      <Card title="Assign a teacher">
        <form onSubmit={assign} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Teacher">
            <select required value={form.teacher_id} onChange={(e) => setForm({ ...form, teacher_id: e.target.value })} className={inputClass}>
              <option value="">Choose…</option>
              {staff.map((m) => (
                <option key={m.user_id} value={m.user_id}>{m.name}</option>
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
          <Field label="Class">
            <select value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })} className={inputClass}>
              <option value="">Every class</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </Field>
          <div className="flex items-end">
            <Button type="submit" disabled={busy} className="w-full">Assign</Button>
          </div>
        </form>
        {staff.length === 0 && <p className="mt-3 text-xs text-ink-muted">No teachers yet. Add them on the Staff page.</p>}
      </Card>

      <ErrorNote message={error} />

      <Card title="Who teaches what">
        {rows.length === 0 ? (
          <Empty>No teaching assignments yet.</Empty>
        ) : (
          <Table columns={['Subject', 'Class', 'Teacher', '']}>
            {rows.map((r) => (
              <tr key={r.id}>
                <Cell className="font-medium text-brand">{r.subject_name}</Cell>
                <Cell>{r.class_name ?? 'Every class'}</Cell>
                <Cell>{r.teacher_name}</Cell>
                <Cell>
                  <Button variant="secondary" disabled={busy} onClick={() => remove(r)}>Remove</Button>
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  );
}
