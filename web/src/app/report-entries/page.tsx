'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Ratings and comments for a class, per term: the traits and skills the
 * school chose at onboarding, the form teacher's comment, and — for an
 * administrator — the head of school's comment. These are what fill those
 * sections of the report card.
 */

type ClassOption = { id: number; name: string; arms?: { id: number; name: string }[] };
type Term = { id: number; name: string; session_name: string | null };

type Row = {
  student_id: number;
  name: string | null;
  admission_number: string | null;
  ratings: Record<string, string>;
  form_teacher_comment: string | null;
  principal_comment: string | null;
};

type Grid = {
  settings: {
    affective_traits: string[];
    psychomotor_skills: string[];
    rating_scale: { value: string; label: string | null }[];
    form_teacher_comment: boolean;
    principal_comment: boolean;
    principal_title: string;
  };
  can_write_form: boolean;
  can_write_principal: boolean;
  students: Row[];
};

export default function ReportEntriesPage() {
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [terms, setTerms] = useState<Term[]>([]);
  const [classId, setClassId] = useState('');
  const [armId, setArmId] = useState('');
  const [termId, setTermId] = useState('');
  const [grid, setGrid] = useState<Grid | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [dirty, setDirty] = useState<Set<number>>(new Set());
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: ClassOption[] }>('/classes').then((r) => {
      setClasses(r.data ?? []);
      if (r.data?.[0]) setClassId(String(r.data[0].id));
    }).catch(() => undefined);
    fetchApi<{ data: Term[]; current_term_id: number | null }>('/terms').then((r) => {
      setTerms(r.data ?? []);
      const current = r.current_term_id ?? r.data?.[0]?.id;
      if (current) setTermId(String(current));
    }).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    if (!classId || !termId) return;
    setLoading(true);
    setError(null);
    setNotice(null);
    try {
      const params = new URLSearchParams({ class_id: classId, term_id: termId });
      if (armId) params.set('arm_id', armId);
      const res = await fetchApi<Grid>(`/report-entries?${params}`);
      setGrid(res);
      setRows(res.students.map((s) => ({ ...s, ratings: { ...(s.ratings ?? {}) } })));
      setDirty(new Set());
    } catch (err) {
      setGrid(null);
      setRows([]);
      setError(errorText(err, 'Could not load this class.'));
    } finally {
      setLoading(false);
    }
  }, [classId, armId, termId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const edit = (studentId: number, patch: Partial<Row>) => {
    setRows((list) => list.map((r) => (r.student_id === studentId ? { ...r, ...patch, ratings: { ...r.ratings, ...(patch.ratings ?? {}) } } : r)));
    setDirty((d) => new Set(d).add(studentId));
  };

  const save = async () => {
    if (!grid) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const entries = rows
        .filter((r) => dirty.has(r.student_id))
        .map((r) => ({
          student_id: r.student_id,
          ...(grid.can_write_form ? { ratings: r.ratings, form_teacher_comment: r.form_teacher_comment ?? '' } : {}),
          ...(grid.can_write_principal && grid.settings.principal_comment ? { principal_comment: r.principal_comment ?? '' } : {}),
        }));
      const res = await fetchApi<{ message: string }>('/report-entries', {
        method: 'PUT',
        body: JSON.stringify({ term_id: Number(termId), class_id: Number(classId), arm_id: armId ? Number(armId) : null, entries }),
      });
      setNotice(res.message);
      setDirty(new Set());
    } catch (err) {
      setError(errorText(err, 'Could not save.'));
    } finally {
      setBusy(false);
    }
  };

  const arms = classes.find((c) => String(c.id) === classId)?.arms ?? [];
  const items = grid ? [...grid.settings.affective_traits, ...grid.settings.psychomotor_skills] : [];

  return (
    <PageShell title="Ratings & comments" subtitle="What each pupil's report card shows beside the marks. Form teachers fill in their own class.">
      <Card className="mb-6">
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Class">
            <select value={classId} onChange={(e) => { setClassId(e.target.value); setArmId(''); }} className={inputClass}>
              {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="Arm">
            <select value={armId} disabled={arms.length === 0} onChange={(e) => setArmId(e.target.value)} className={`${inputClass} disabled:opacity-50`}>
              <option value="">All arms</option>
              {arms.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </Field>
          <Field label="Term">
            <select value={termId} onChange={(e) => setTermId(e.target.value)} className={inputClass}>
              {terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}
            </select>
          </Field>
        </div>
      </Card>

      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {loading ? (
        <Loading />
      ) : !grid ? null : rows.length === 0 ? (
        <Empty>No active pupils in this class.</Empty>
      ) : (
        <Card
          title={`${rows.length} pupil${rows.length === 1 ? '' : 's'}`}
          description={grid.settings.rating_scale.map((o) => `${o.value}${o.label ? ` = ${o.label}` : ''}`).join(' · ')}
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] text-sm">
              <thead>
                <tr className="border-b border-line-soft text-left text-xs uppercase tracking-wide text-ink-faint">
                  <th className="sticky left-0 bg-surface px-2 py-2 font-medium">Pupil</th>
                  {items.map((item) => <th key={item} className="px-1 py-2 text-center font-medium">{item}</th>)}
                  {grid.settings.form_teacher_comment && <th className="min-w-[14rem] px-2 py-2 font-medium">Form teacher&rsquo;s comment</th>}
                  {grid.settings.principal_comment && grid.can_write_principal && (
                    <th className="min-w-[14rem] px-2 py-2 font-medium">{grid.settings.principal_title}&rsquo;s comment</th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-line-soft">
                {rows.map((r) => (
                  <tr key={r.student_id} className={dirty.has(r.student_id) ? 'bg-caution-soft/40' : ''}>
                    <td className="sticky left-0 bg-surface px-2 py-2 align-top">
                      <span className="font-medium text-ink">{r.name}</span>
                      <span className="block text-xs text-ink-faint">{r.admission_number}</span>
                    </td>
                    {items.map((item) => (
                      <td key={item} className="px-1 py-2 text-center align-top">
                        <select
                          value={r.ratings[item] ?? ''}
                          disabled={!grid.can_write_form}
                          onChange={(e) => edit(r.student_id, { ratings: { [item]: e.target.value } })}
                          className="rounded border border-line bg-surface px-1 py-1 text-sm"
                          aria-label={`${item} for ${r.name}`}
                        >
                          <option value="">—</option>
                          {grid.settings.rating_scale.map((o) => <option key={o.value} value={o.value}>{o.value}</option>)}
                        </select>
                      </td>
                    ))}
                    {grid.settings.form_teacher_comment && (
                      <td className="px-2 py-2 align-top">
                        <textarea
                          rows={2}
                          maxLength={1000}
                          value={r.form_teacher_comment ?? ''}
                          disabled={!grid.can_write_form}
                          onChange={(e) => edit(r.student_id, { form_teacher_comment: e.target.value })}
                          className={inputClass}
                          aria-label={`Form teacher's comment for ${r.name}`}
                        />
                      </td>
                    )}
                    {grid.settings.principal_comment && grid.can_write_principal && (
                      <td className="px-2 py-2 align-top">
                        <textarea
                          rows={2}
                          maxLength={1000}
                          value={r.principal_comment ?? ''}
                          onChange={(e) => edit(r.student_id, { principal_comment: e.target.value })}
                          className={inputClass}
                          aria-label={`${grid.settings.principal_title}'s comment for ${r.name}`}
                        />
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Button disabled={busy || dirty.size === 0} onClick={save}>{busy ? 'Saving…' : `Save ${dirty.size || ''} change${dirty.size === 1 ? '' : 's'}`}</Button>
            {items.length === 0 && (
              <span className="text-xs text-ink-muted">No traits or skills are set up — an administrator chooses them under Report cards &amp; grading.</span>
            )}
          </div>
        </Card>
      )}
    </PageShell>
  );
}
