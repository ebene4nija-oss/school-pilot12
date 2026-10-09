'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Who teaches what, one section at a time.
 *
 * Nursery and primary are normally taught by the class teacher, who then
 * teaches every subject of their arm; a specialist (French, Computer, PHE)
 * named for a subject takes that subject over. Secondary is taught by
 * subject teachers, and each arm can have its own. Every cell shows who
 * teaches it now, and a subject nobody teaches is flagged.
 */

type Teacher = { id: number; name: string | null; source: 'assigned' | 'class_teacher'; whole_class: boolean };
type Cellv = { arm_id: number | null; offered: boolean; teachers: Teacher[]; missing: boolean };
type Arm = { id: number | null; name: string; stream: string | null; class_teacher: { id: number; name: string | null } | null; assistant: { id: number; name: string | null } | null };
type ClassView = { id: number; name: string; arms: Arm[]; subjects: { id: number; name: string; cells: Cellv[] }[]; gaps: number };
type Overview = { section: string; teaching_model: string; teaching_model_options: Record<string, string>; session_id: number | null; classes: ClassView[]; loads: { teacher_id: number; name: string | null; subjects: number; classes: number; teaching_slots: number }[]; gaps: number };
type Staff = { user_id: number; name: string; role: string };

const SECTIONS: [string, string][] = [['nursery', 'Nursery'], ['primary', 'Primary'], ['secondary', 'Secondary']];

export default function TeachingPage() {
  const [section, setSection] = useState('secondary');
  const [view, setView] = useState<Overview | null>(null);
  const [staff, setStaff] = useState<Staff[]>([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: Staff[] }>('/staff/directory')
      .then((r) => setStaff((r.data ?? []).filter((m) => m.role === 'teacher' || m.role === 'school_admin')))
      .catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    try {
      setView(await fetchApi<Overview>(`/teaching/overview?section=${section}`));
    } catch (err) {
      setError(errorText(err, 'Could not load who teaches what.'));
    }
  }, [section]);

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
    } catch (err) {
      setError(errorText(err, fallback));
      setNotice(null);
    } finally {
      setBusy(false);
    }
  };

  const post = (path: string, body: unknown) => fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });

  const setModel = (model: string) => run(
    () => fetchApi('/sections/settings', { method: 'PUT', body: JSON.stringify({ teaching_models: { [section]: model } }) }).then(() => ({ message: 'Teaching style saved.' })),
    'Could not change how this section is taught.',
  );

  const assign = (cls: ClassView, armId: number | null, subjectId: number, teacherId: string) => run(
    () => post('/subjects/assign-teacher', { teacher_id: Number(teacherId), subject_id: subjectId, class_id: cls.id, arm_id: armId, replace: true }),
    'Could not assign that teacher.',
  );

  const unassign = (cls: ClassView, armId: number | null, subjectId: number, t: Teacher) => run(
    () => post('/subjects/remove-teacher', { teacher_id: t.id, subject_id: subjectId, class_id: cls.id, arm_id: t.whole_class ? null : armId }),
    'Could not remove that teacher.',
  );

  const setClassTeacher = (cls: ClassView, arm: Arm, teacherId: string) => run(
    () => post('/class-teachers', { session_id: view?.session_id, class_id: cls.id, arm_id: arm.id, form_teacher_id: Number(teacherId), assistant_teacher_id: arm.assistant?.id ?? null }),
    'Could not set the class teacher.',
  );

  const classTeacherMode = view?.teaching_model === 'class_teacher';

  return (
    <PageShell title="Who teaches what" subtitle="Class teachers, subject teachers per arm, and any subject nobody teaches yet.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="mb-5 flex flex-wrap items-end gap-3">
        <div className="flex flex-wrap gap-2" role="tablist" aria-label="Section">
          {SECTIONS.map(([k, l]) => (
            <button key={k} type="button" role="tab" aria-selected={section === k} onClick={() => { setSection(k); setNotice(null); }}
              className={`rounded-lg px-3.5 py-2 text-sm font-medium ${section === k ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'}`}>
              {l}
            </button>
          ))}
        </div>
        {view && (
          <Field label="How this section is taught">
            <select className={`${inputClass} w-80`} value={view.teaching_model} disabled={busy} onChange={(e) => setModel(e.target.value)}>
              {Object.entries(view.teaching_model_options).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </Field>
        )}
        {view && view.gaps > 0 && <Badge tone="red">{view.gaps} subject slot{view.gaps === 1 ? '' : 's'} with no teacher</Badge>}
      </div>

      {view && classTeacherMode && (
        <p className="mb-5 rounded-lg bg-info-soft px-4 py-3 text-sm text-info">
          Each arm’s class teacher teaches all of its subjects. To give one subject to a specialist (French, Computer, PHE…), pick them in that subject’s row; the class teacher keeps the rest.
        </p>
      )}

      {!view ? <Loading /> : view.classes.length === 0 ? <Empty>No classes in this section yet.</Empty> : (
        <div className="space-y-5">
          {view.classes.map((cls) => (
            <Card key={cls.id} title={`${cls.name}${cls.gaps ? ` · ${cls.gaps} gap${cls.gaps === 1 ? '' : 's'}` : ''}`}>
              {cls.subjects.length === 0 ? <Empty>No subjects set for this class. Add them under Subjects → Subjects per class.</Empty> : (
                <Table columns={['Subject', ...cls.arms.map((a) => (a.id === null ? 'Whole class' : `${cls.name} ${a.name}${a.stream ? ` (${a.stream})` : ''}`))]}>
                  <tr className="bg-surface-sunken">
                    <Cell className="text-xs font-semibold uppercase text-ink-muted">Class teacher</Cell>
                    {cls.arms.map((a) => (
                      <Cell key={a.id ?? 'class'}>
                        <select className={`${inputClass} w-44`} value={a.class_teacher?.id ?? ''} disabled={busy || !view.session_id}
                          onChange={(e) => e.target.value && setClassTeacher(cls, a, e.target.value)} aria-label={`Class teacher for ${cls.name} ${a.name}`}>
                          <option value="">{a.class_teacher ? a.class_teacher.name : 'Choose…'}</option>
                          {staff.map((s) => <option key={s.user_id} value={s.user_id}>{s.name}</option>)}
                        </select>
                      </Cell>
                    ))}
                  </tr>
                  {cls.subjects.map((subject) => (
                    <tr key={subject.id}>
                      <Cell className="font-medium text-brand">{subject.name}</Cell>
                      {subject.cells.map((cell) => (
                        <Cell key={cell.arm_id ?? 'class'} className={cell.missing ? 'bg-negative-soft' : ''}>
                          {!cell.offered ? <span className="text-xs text-ink-faint">Not taken</span> : (
                            <div className="space-y-1">
                              {cell.teachers.map((t) => (
                                <div key={`${t.id}-${t.source}`} className="flex items-center gap-1.5 text-sm">
                                  <span>{t.name}</span>
                                  {t.source === 'class_teacher' && <Badge tone="slate">class teacher</Badge>}
                                  {t.source === 'assigned' && t.whole_class && cls.arms.length > 1 && <Badge tone="blue">all arms</Badge>}
                                  {t.source === 'assigned' && (
                                    <button type="button" className="text-xs text-negative" disabled={busy} aria-label={`Remove ${t.name}`} onClick={() => unassign(cls, cell.arm_id, subject.id, t)}>✕</button>
                                  )}
                                </div>
                              ))}
                              {cell.missing && <span className="block text-xs font-semibold text-negative">No teacher</span>}
                              <select className={`${inputClass} w-44 py-1 text-xs`} value="" disabled={busy}
                                onChange={(e) => e.target.value && assign(cls, cell.arm_id, subject.id, e.target.value)}
                                aria-label={`Assign ${subject.name} teacher`}>
                                <option value="">{classTeacherMode ? 'Specialist…' : cell.teachers.length ? 'Change…' : 'Assign…'}</option>
                                {staff.map((s) => <option key={s.user_id} value={s.user_id}>{s.name}</option>)}
                              </select>
                            </div>
                          )}
                        </Cell>
                      ))}
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          ))}

          <Card title="Teaching load" description="What each teacher carries in this section.">
            {view.loads.length === 0 ? <Empty>Nobody is assigned yet.</Empty> : (
              <Table columns={['Teacher', 'Subjects', 'Classes', 'Subject–arm slots']}>
                {view.loads.map((l) => (
                  <tr key={l.teacher_id}>
                    <Cell className="font-medium">{l.name}</Cell>
                    <Cell>{l.subjects}</Cell>
                    <Cell>{l.classes}</Cell>
                    <Cell>{l.teaching_slots}</Cell>
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
