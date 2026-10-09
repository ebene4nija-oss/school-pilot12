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
  level_groups: string[] | null;
  is_active: boolean;
};

type ClassOption = { id: number; name: string; level_category: string; arms: { id: number; name: string; stream: string | null }[] };

type ClassSubject = { id: number; name: string; code: string | null; is_compulsory: boolean; streams: string[]; max_students: number | null };

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

const LEVELS: [string, string][] = [
  ['nursery', 'Nursery'],
  ['primary', 'Primary'],
  ['junior_secondary', 'JSS'],
  ['senior_secondary', 'SS'],
];

/** Same rule as the API (Sections::taughtAt): no tags means every level. */
function taughtAt(levels: string[] | null, level: string): boolean {
  if (!levels || levels.length === 0 || levels.includes(level)) return true;
  if (level === 'secondary') return levels.some((l) => l.endsWith('_secondary'));
  return levels.includes('secondary') && level.endsWith('secondary');
}

function levelNames(levels: string[] | null): string {
  if (!levels || levels.length === 0) return 'All levels';
  return levels.map((l) => LEVELS.find(([k]) => k === l)?.[1] ?? l).join(', ');
}

function LevelPicker({ value, onChange }: { value: string[]; onChange: (v: string[]) => void }) {
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1">
      {LEVELS.map(([k, l]) => (
        <label key={k} className="flex items-center gap-1.5 text-sm text-ink">
          <input type="checkbox" className="h-4 w-4 accent-brand" checked={value.includes(k)} onChange={(e) => onChange(e.target.checked ? [...value, k] : value.filter((x) => x !== k))} />
          {l}
        </label>
      ))}
    </div>
  );
}
export default function SubjectsPage() {
  const [tab, setTab] = useState<Tab>('subjects');
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [streams, setStreams] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadSubjects = useCallback(async () => {
    try {
      const [subs, cls] = await Promise.all([
        fetchApi<{ data: Subject[] }>('/subjects?include_inactive=1'),
        fetchApi<{ data: ClassOption[]; streams: Record<string, string> }>('/classes'),
      ]);
      setSubjects(subs.data ?? []);
      setClasses(cls.data ?? []);
      setStreams(cls.streams ?? {});
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
          ['teachers', 'Assignment list'],
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
          {tab === 'classes' && <ClassSubjects classes={classes} subjects={active} streams={streams} />}
          {tab === 'teachers' && <Teachers classes={classes} subjects={active} />}
        </>
      )}
    </PageShell>
  );
}

function SubjectList({ subjects, onChanged }: { subjects: Subject[]; onChanged: () => void }) {
  const blank = { name: '', code: '', category: 'core', department: 'general', level_groups: [] as string[] };
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
            <span className="mb-1 block text-sm font-medium text-ink">Taught at (leave all unticked for every level)</span>
            <LevelPicker value={form.level_groups} onChange={(v) => setForm({ ...form, level_groups: v })} />
          </div>
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
          <Table columns={['Subject', 'Code', 'Type', 'Department', 'Levels', 'Status', '']}>
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
                  <Cell>
                    <LevelPicker value={edit.level_groups} onChange={(v) => setEdit({ ...edit, level_groups: v })} />
                  </Cell>
                  <Cell>{null}</Cell>
                  <Cell>
                    <div className="flex gap-2">
                      <Button disabled={busy} onClick={() => save(s.id, { name: edit.name.trim(), code: edit.code.trim() || null, category: edit.category, department: edit.department, level_groups: edit.level_groups })}>
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
                  <Cell className="text-xs">{levelNames(s.level_groups)}</Cell>
                  <Cell>
                    <Badge tone={s.is_active ? 'green' : 'slate'}>{s.is_active ? 'Offered' : 'Switched off'}</Badge>
                  </Cell>
                  <Cell>
                    <div className="flex gap-2">
                      <Button
                        variant="secondary"
                        onClick={() => {
                          setEditing(s.id);
                          setEdit({ name: s.name, code: s.code ?? '', category: s.category, department: s.department ?? 'general', level_groups: s.level_groups ?? [] });
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

function ClassSubjects({ classes, subjects, streams }: { classes: ClassOption[]; subjects: Subject[]; streams: Record<string, string> }) {
  const [classId, setClassId] = useState(classes[0] ? String(classes[0].id) : '');
  const [offered, setOffered] = useState<ClassSubject[]>([]);
  const [picked, setPicked] = useState<number[]>([]);
  const [compulsory, setCompulsory] = useState(true);
  const [forStreams, setForStreams] = useState<string[]>([]);
  const [showAll, setShowAll] = useState(false);
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
        body: JSON.stringify({ class_id: Number(classId), subjects: picked.map((id) => ({ subject_id: id, is_compulsory: compulsory, streams: forStreams })) }),
      });
      setPicked([]);
      await load(classId);
    } catch (err) {
      setError(errorText(err, 'Could not add those subjects.'));
    } finally {
      setBusy(false);
    }
  };

  /** Turn one stream on or off for a subject the class already offers. */
  const toggleStream = async (s: ClassSubject, stream: string) => {
    const next = s.streams.includes(stream) ? s.streams.filter((x) => x !== stream) : [...s.streams, stream];
    setBusy(true);
    setError(null);
    try {
      await fetchApi('/classes/subjects', {
        method: 'POST',
        body: JSON.stringify({ class_id: Number(classId), subjects: [{ subject_id: s.id, is_compulsory: s.is_compulsory, max_students: s.max_students, streams: next }] }),
      });
      await load(classId);
    } catch (err) {
      setError(errorText(err, 'Could not change that subject.'));
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

  const cls = classes.find((c) => String(c.id) === classId);
  // Streams this class's arms actually use; a class with none needs no stream column.
  const classStreams = Array.from(new Set((cls?.arms ?? []).map((a) => a.stream).filter((s): s is string => !!s)));
  const notOffered = subjects.filter((s) => !offered.some((o) => o.id === s.id));
  const candidates = showAll || !cls ? notOffered : notOffered.filter((s) => taughtAt(s.level_groups, cls.level_category));
  const allPicked = candidates.length > 0 && candidates.every((s) => picked.includes(s.id));

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Card title="Offered in this class">
        <select value={classId} onChange={(e) => { setClassId(e.target.value); setPicked([]); }} className={`${inputClass} mb-4 max-w-xs`}>
          {classes.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </select>
        <ErrorNote message={error} />
        {offered.length === 0 ? (
          <Empty>This class offers no subjects yet.</Empty>
        ) : (
          <Table columns={classStreams.length ? ['Subject', 'Type', 'Taken by', ''] : ['Subject', 'Type', '']}>
            {offered.map((s) => (
              <tr key={s.id}>
                <Cell className="font-medium text-brand">{s.name}</Cell>
                <Cell>
                  <Badge tone={s.is_compulsory ? 'blue' : 'amber'}>{s.is_compulsory ? 'Compulsory' : 'Elective'}</Badge>
                </Cell>
                {classStreams.length > 0 && (
                  <Cell>
                    <div className="flex flex-wrap gap-1.5">
                      {classStreams.map((st) => (
                        <button
                          key={st}
                          type="button"
                          disabled={busy}
                          aria-pressed={s.streams.includes(st)}
                          onClick={() => toggleStream(s, st)}
                          className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${s.streams.includes(st) ? 'bg-brand text-white ring-brand' : 'bg-surface text-ink-muted ring-line-soft'}`}
                        >
                          {streams[st] ?? st}
                        </button>
                      ))}
                      {s.streams.length === 0 && <span className="text-xs text-ink-muted">All arms</span>}
                    </div>
                  </Cell>
                )}
                <Cell>
                  <Button variant="secondary" disabled={busy} onClick={() => remove(s.id)}>Remove</Button>
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card title="Add subjects to this class" description={showAll ? 'Showing every subject.' : `Showing subjects taught at this level.`}>
        <div className="mb-3 flex flex-wrap items-center gap-4 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" className="h-4 w-4 accent-brand" checked={allPicked} disabled={candidates.length === 0}
              onChange={(e) => setPicked(e.target.checked ? candidates.map((s) => s.id) : [])} />
            Select all ({candidates.length})
          </label>
          <label className="flex items-center gap-2 text-ink-muted">
            <input type="checkbox" className="h-4 w-4 accent-brand" checked={showAll} onChange={(e) => { setShowAll(e.target.checked); setPicked([]); }} />
            Show subjects from other levels
          </label>
        </div>
        {candidates.length === 0 ? (
          <Empty>Every subject for this level is already offered here.</Empty>
        ) : (
          <>
            <div className="mb-4 grid gap-2 sm:grid-cols-2">
              {candidates.map((s) => (
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
              {classStreams.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 text-sm">
                  <span className="text-ink-muted">Only for:</span>
                  {classStreams.map((st) => (
                    <label key={st} className="flex items-center gap-1.5">
                      <input type="checkbox" className="h-4 w-4 accent-brand" checked={forStreams.includes(st)}
                        onChange={(e) => setForStreams((f) => (e.target.checked ? [...f, st] : f.filter((x) => x !== st)))} />
                      {streams[st] ?? st}
                    </label>
                  ))}
                  {forStreams.length === 0 && <span className="text-xs text-ink-muted">(all arms)</span>}
                </div>
              )}
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
