'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Classes and their arms.
 *
 * An arm can carry a stream (SS 2A science, SS 2B arts), which decides which
 * of the class's subjects it takes, and a merit flag for the arm the school
 * keeps its strongest students in. Parents and students never see the merit
 * flag, only the arm's name.
 */

type Arm = { id: number; name: string; stream: string | null; is_merit?: boolean };
type SchoolClass = { id: number; name: string; level_category: string; order_index: number; student_count: number; arms: Arm[] };

const LEVELS: [string, string][] = [
  ['nursery', 'Nursery'],
  ['primary', 'Primary'],
  ['junior_secondary', 'Junior Secondary (JSS)'],
  ['senior_secondary', 'Senior Secondary (SS)'],
];

export default function ClassesPage() {
  const [classes, setClasses] = useState<SchoolClass[] | null>(null);
  const [streams, setStreams] = useState<Record<string, string>>({});
  const [form, setForm] = useState({ name: '', level_category: 'junior_secondary', order_index: '' });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const r = await fetchApi<{ data: SchoolClass[]; streams: Record<string, string> }>('/classes');
      setClasses(r.data ?? []);
      setStreams(r.streams ?? {});
    } catch (err) {
      setError(errorText(err, 'Could not load classes.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const run = async (fn: () => Promise<{ message?: string }>, fallback: string) => {
    setBusy(true);
    setError(null);
    try {
      const r = await fn();
      setNotice(r.message ?? 'Saved.');
      await load();
      return true;
    } catch (err) {
      setError(errorText(err, fallback));
      setNotice(null);
      return false;
    } finally {
      setBusy(false);
    }
  };

  const addClass = async (e: React.FormEvent) => {
    e.preventDefault();
    const body = { name: form.name.trim(), level_category: form.level_category, ...(form.order_index ? { order_index: Number(form.order_index) } : {}) };
    if (await run(() => fetchApi('/classes', { method: 'POST', body: JSON.stringify(body) }), 'Could not add that class.')) {
      setForm({ ...form, name: '', order_index: '' });
    }
  };

  return (
    <PageShell title="Classes & arms" subtitle="Your classes in teaching order, their arms, and which stream each arm is.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <Card title="Add a class" className="mb-6">
        <form onSubmit={addClass} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Name"><input required maxLength={50} className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="e.g. JSS 1" /></Field>
          <Field label="Level">
            <select className={inputClass} value={form.level_category} onChange={(e) => setForm({ ...form, level_category: e.target.value })}>
              {LEVELS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </Field>
          <Field label="Order (1 = first)"><input type="number" min={0} max={255} className={inputClass} value={form.order_index} onChange={(e) => setForm({ ...form, order_index: e.target.value })} /></Field>
          <div className="flex items-end"><Button type="submit" disabled={busy}>Add class</Button></div>
        </form>
      </Card>

      {!classes ? <Loading /> : classes.length === 0 ? <Empty>No classes yet.</Empty> : (
        <div className="space-y-4">
          {classes.map((c) => <ClassCard key={c.id} cls={c} streams={streams} busy={busy} run={run} />)}
        </div>
      )}

      <p className="mt-6 text-sm text-ink-muted">
        Which subjects each stream takes is set per class under <Link href="/subjects" className="text-brand underline">Subjects → Subjects per class</Link>.
      </p>
    </PageShell>
  );
}

function ClassCard({ cls, streams, busy, run }: {
  cls: SchoolClass;
  streams: Record<string, string>;
  busy: boolean;
  run: (fn: () => Promise<{ message?: string }>, fallback: string) => Promise<boolean>;
}) {
  const [editing, setEditing] = useState(false);
  const [edit, setEdit] = useState({ name: cls.name, level_category: cls.level_category, order_index: String(cls.order_index ?? 0) });
  const [arm, setArm] = useState({ name: '', stream: '', is_merit: false });
  const secondary = cls.level_category.includes('secondary');

  const saveClass = async () => {
    if (await run(() => fetchApi(`/classes/${cls.id}`, { method: 'PUT', body: JSON.stringify({ ...edit, name: edit.name.trim(), order_index: Number(edit.order_index) || 0 }) }), 'Could not save the class.')) setEditing(false);
  };

  const addArm = async (e: React.FormEvent) => {
    e.preventDefault();
    if (await run(() => fetchApi(`/classes/${cls.id}/arms`, { method: 'POST', body: JSON.stringify({ name: arm.name.trim(), stream: arm.stream || null, is_merit: arm.is_merit }) }), 'Could not add the arm.')) {
      setArm({ name: '', stream: '', is_merit: false });
    }
  };

  const updateArm = (a: Arm, body: Partial<Arm>) => run(() => fetchApi(`/arms/${a.id}`, { method: 'PUT', body: JSON.stringify(body) }), 'Could not save the arm.');

  const removeArm = (a: Arm) => {
    if (!window.confirm(`Delete ${cls.name} ${a.name}?`)) return;
    void run(() => fetchApi(`/arms/${a.id}`, { method: 'DELETE' }), 'Could not delete the arm.');
  };

  const removeClass = () => {
    if (!window.confirm(`Delete ${cls.name} and its arms?`)) return;
    void run(() => fetchApi(`/classes/${cls.id}`, { method: 'DELETE' }), 'Could not delete the class.');
  };

  return (
    <Card>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        {editing ? (
          <div className="grid flex-1 gap-3 sm:grid-cols-3">
            <input className={inputClass} value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} aria-label="Class name" />
            <select className={inputClass} value={edit.level_category} onChange={(e) => setEdit({ ...edit, level_category: e.target.value })} aria-label="Level">
              {LEVELS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
            <input type="number" min={0} max={255} className={inputClass} value={edit.order_index} onChange={(e) => setEdit({ ...edit, order_index: e.target.value })} aria-label="Order" />
          </div>
        ) : (
          <div>
            <h2 className="font-headline text-base font-semibold text-brand">{cls.name}</h2>
            <p className="text-sm text-ink-muted">{LEVELS.find(([k]) => k === cls.level_category)?.[1] ?? cls.level_category} · {cls.student_count} student{cls.student_count === 1 ? '' : 's'}</p>
          </div>
        )}
        <div className="flex gap-2">
          {editing
            ? <><Button disabled={busy} onClick={saveClass}>Save</Button><Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button></>
            : <><Button variant="secondary" onClick={() => setEditing(true)}>Edit</Button><Button variant="secondary" disabled={busy} onClick={removeClass}>Delete</Button></>}
        </div>
      </div>

      {cls.arms.length === 0 ? (
        <p className="mb-4 text-sm text-ink-muted">No arms: the class is taught as one group.</p>
      ) : (
        <ul className="mb-4 divide-y divide-line-soft">
          {cls.arms.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 py-2.5 text-sm">
              <span className="w-24 font-medium">{cls.name} {a.name}</span>
              <select className={`${inputClass} w-48`} value={a.stream ?? ''} disabled={busy} onChange={(e) => updateArm(a, { stream: e.target.value || null })} aria-label={`Stream for ${a.name}`}>
                <option value="">General / mixed</option>
                {Object.entries(streams).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
              <label className="flex items-center gap-2">
                <input type="checkbox" className="h-4 w-4 accent-brand" checked={!!a.is_merit} disabled={busy} onChange={(e) => updateArm(a, { is_merit: e.target.checked })} />
                Merit arm
              </label>
              {a.is_merit && <Badge tone="blue">Staff only</Badge>}
              <button type="button" className="ml-auto text-negative underline" disabled={busy} onClick={() => removeArm(a)}>Delete</button>
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={addArm} className="flex flex-wrap items-end gap-3">
        <Field label="New arm"><input required maxLength={30} className={`${inputClass} w-32`} value={arm.name} onChange={(e) => setArm({ ...arm, name: e.target.value })} placeholder="A, Gold…" /></Field>
        {secondary && (
          <Field label="Stream">
            <select className={`${inputClass} w-48`} value={arm.stream} onChange={(e) => setArm({ ...arm, stream: e.target.value })}>
              <option value="">General / mixed</option>
              {Object.entries(streams).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </Field>
        )}
        <label className="flex items-center gap-2 pb-2 text-sm">
          <input type="checkbox" className="h-4 w-4 accent-brand" checked={arm.is_merit} onChange={(e) => setArm({ ...arm, is_merit: e.target.checked })} />
          Merit arm
        </label>
        <Button type="submit" variant="secondary" disabled={busy}>Add arm</Button>
      </form>
    </Card>
  );
}
