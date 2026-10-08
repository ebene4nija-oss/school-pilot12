'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Module settings for a school admin, in one place: CBT, hostel, library,
 * progress tracking, transfers and the student directory. Each section reads
 * the module's own settings and saves only what was changed; the module's
 * API validates every value and says why it refused one.
 */

type Kind = 'bool' | 'number' | 'text' | 'select' | 'lines' | 'checks' | 'multi';
interface Def { key: string; label: string; kind: Kind; options?: [string, string][]; min?: number; max?: number; hint?: string }
interface Section { id: string; title: string; description: string; path: string; module?: string; fields: Def[] }

const SECTIONS: Section[] = [
  {
    id: 'leave', title: 'Staff leave', path: '/hr/leave-settings',
    description: 'Who requests leave in SchoolPilot, and how many days each type allows (leave a box empty for no set allowance). Staff with their own allowance keep it. Unless requests over the allowance are refused, the approver sees the figures and decides.',
    fields: [
      { key: 'leave_roles', label: 'Staff who request leave here', kind: 'checks', options: [['teacher', 'Teachers'], ['bursar', 'Bursars'], ['nurse', 'Nurses']] },
      { key: 'days_annual', label: 'Annual leave (days a year)', kind: 'number', min: 0, max: 365 },
      { key: 'days_sick', label: 'Sick leave (days a year)', kind: 'number', min: 0, max: 365 },
      { key: 'days_maternity', label: 'Maternity leave (days)', kind: 'number', min: 0, max: 365 },
      { key: 'days_compassionate', label: 'Compassionate leave (days a year)', kind: 'number', min: 0, max: 365 },
      { key: 'days_study', label: 'Study leave (days a year)', kind: 'number', min: 0, max: 365 },
      { key: 'enforce_allowance', label: 'Refuse requests that go over the allowance', kind: 'bool' },
    ],
  },
  {
    id: 'cbt', title: 'CBT exams', path: '/cbt/settings', module: 'cbt',
    description: 'How papers are approved and how the exam room behaves.',
    fields: [
      { key: 'exam_approval_required', label: 'A paper needs approval before it is published', kind: 'bool' },
      { key: 'trusted_teacher_ids', label: 'Teachers who may publish without approval', kind: 'multi', hint: 'teachers' },
      { key: 'min_preparation_days', label: 'Days of notice students get before a paper opens', kind: 'number', min: 0, max: 30 },
      { key: 'disconnect_after_seconds', label: 'Seconds offline before a candidate shows as disconnected', kind: 'number', min: 30, max: 900 },
    ],
  },
  {
    id: 'progress', title: 'Progress & analysis', path: '/progress/settings',
    description: 'When the system flags a student who may need support.',
    fields: [
      { key: 'trend_points', label: 'Terms used for a trend', kind: 'number', min: 1, max: 50 },
      { key: 'steady_range', label: 'Change (points) still counted as steady', kind: 'number', min: 1, max: 50 },
      { key: 'sharp_decline_points', label: 'Drop (points) flagged as a sharp decline', kind: 'number', min: 1, max: 50 },
      { key: 'low_attendance_rate', label: 'Attendance (%) below which a student is flagged', kind: 'number', min: 1, max: 100 },
      { key: 'low_completion_rate', label: 'Homework completion (%) below which a student is flagged', kind: 'number', min: 1, max: 100 },
      { key: 'multiple_failed_subjects', label: 'Failed subjects that raise a flag', kind: 'number', min: 1, max: 20 },
      { key: 'topic_strong', label: 'Topic score (%) counted as strong', kind: 'number', min: 1, max: 100 },
      { key: 'topic_developing', label: 'Topic score (%) counted as developing (below strong)', kind: 'number', min: 1, max: 100 },
      { key: 'topic_min_questions', label: 'Questions needed before a topic is judged', kind: 'number', min: 2, max: 50 },
      { key: 'core_subject_ids', label: 'Core subjects', kind: 'multi', hint: 'subjects' },
      { key: 'max_flags_shown', label: 'Flags shown per student', kind: 'number', min: 1, max: 20 },
      { key: 'teachers_see_flags', label: 'Teachers see the flags', kind: 'bool' },
      { key: 'students_see_position', label: 'Students see their class position', kind: 'bool' },
      { key: 'students_see_interventions', label: 'Students see their support plans', kind: 'bool' },
    ],
  },
  {
    id: 'directory', title: 'Student records', path: '/student-directory/settings',
    description: 'What a complete student record must contain, and who sees what.',
    fields: [
      { key: 'mandatory_fields', label: 'Required for a complete record', kind: 'checks', hint: 'available_fields' },
      { key: 'name_order', label: 'Names shown as', kind: 'select', options: [['given_first', 'Given name first (Ada Obi)'], ['surname_first', 'Surname first (OBI Ada)']] },
      { key: 'subject_teachers_see_guardian_phone', label: 'Subject teachers see the parent’s phone number', kind: 'bool' },
      { key: 'show_gender_counts', label: 'Show boys and girls counts on class lists', kind: 'bool' },
    ],
  },
  {
    id: 'transfers', title: 'Transfers', path: '/transfers/settings',
    description: 'How a student’s move to another school is approved and released.',
    fields: [
      { key: 'approval_steps', label: 'Who approves, in order', kind: 'checks', hint: 'step_labels' },
      { key: 'fees_block_release', label: 'Outstanding fees hold back the transfer documents', kind: 'bool' },
      { key: 'package_expiry_days', label: 'Days the receiving school has to collect the records', kind: 'number', min: 1, max: 90 },
      { key: 'code_expiry_days', label: 'Days a transfer code stays valid', kind: 'number', min: 1, max: 90 },
      { key: 'lawful_basis', label: 'Lawful basis for sharing the record (NDPA)', kind: 'select', options: [['guardian_consent', 'Parent’s consent'], ['legal_obligation', 'Legal obligation'], ['contract', 'Contract with the family']] },
      { key: 'students_may_request', label: 'Students may request a transfer themselves', kind: 'bool' },
      { key: 'end_student_access_on_completion', label: 'End the student’s sign-in when the transfer completes', kind: 'bool' },
      { key: 'clearance_items', label: 'Clearance checklist (one per line, e.g. Library books returned)', kind: 'lines' },
      { key: 'applicant_retention_days', label: 'Days incoming applicants’ data is kept', kind: 'number', min: 30, max: 3650 },
    ],
  },
  {
    id: 'library', title: 'Library', path: '/library/settings', module: 'library',
    description: 'Loan periods and limits.',
    fields: [
      { key: 'student_loan_days', label: 'Student loan (days)', kind: 'number', min: 1, max: 180 },
      { key: 'staff_loan_days', label: 'Staff loan (days)', kind: 'number', min: 1, max: 365 },
      { key: 'student_max_loans', label: 'Books a student may hold', kind: 'number', min: 1, max: 20 },
      { key: 'staff_max_loans', label: 'Books a member of staff may hold', kind: 'number', min: 1, max: 50 },
      { key: 'max_renewals', label: 'Renewals allowed', kind: 'number', min: 0, max: 10 },
      { key: 'hold_days', label: 'Days a reserved book is held', kind: 'number', min: 1, max: 14 },
      { key: 'students_reserve', label: 'Students may reserve books', kind: 'bool' },
      { key: 'notify_parents', label: 'Tell parents about overdue books', kind: 'bool' },
    ],
  },
  {
    id: 'hostel', title: 'Hostel', path: '/hostel/settings', module: 'hostel',
    description: 'What boarders and their parents may do from their own accounts.',
    fields: [
      { key: 'parents_see_bed', label: 'Parents see the room and bed', kind: 'bool' },
      { key: 'parents_request_leave', label: 'Parents may request leave', kind: 'bool' },
      { key: 'students_request_leave', label: 'Boarders may request leave', kind: 'bool' },
      { key: 'students_report_maintenance', label: 'Boarders may report repairs', kind: 'bool' },
      { key: 'consent_leave_types', label: 'Leave that needs a parent’s consent', kind: 'checks', options: [['weekend', 'Weekend'], ['medical', 'Medical'], ['outing', 'Outing'], ['family', 'Family'], ['other', 'Other']] },
      { key: 'return_grace_minutes', label: 'Minutes late before a return is overdue', kind: 'number', min: 0, max: 720 },
    ],
  },
];

export default function SettingsPage() {
  const [off, setOff] = useState<string[]>([]);
  const [teachers, setTeachers] = useState<[string, string][]>([]);
  const [subjects, setSubjects] = useState<[string, string][]>([]);

  useEffect(() => {
    fetchApi<{ modules: { key: string; enabled: boolean }[] }>('/school/features').then((r) => setOff(r.modules.filter((m) => !m.enabled).map((m) => m.key))).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string }[] }>('/users?role=teacher&status=active&per_page=100').then((r) => setTeachers(r.data.map((t) => [String(t.id), t.name]))).catch(() => undefined);
    fetchApi<{ data: { id: number; name: string }[] }>('/subjects').then((r) => setSubjects((r.data ?? []).map((s) => [String(s.id), s.name]))).catch(() => undefined);
  }, []);

  return (
    <PageShell title="Module settings" subtitle="How each part of SchoolPilot behaves at your school.">
      <div className="space-y-5">
        {SECTIONS.filter((s) => !s.module || !off.includes(s.module)).map((s) => <SettingsCard key={s.id} section={s} lookups={{ teachers, subjects }} />)}
      </div>
    </PageShell>
  );
}

function SettingsCard({ section, lookups }: { section: Section; lookups: Record<string, [string, string][]> }) {
  const [values, setValues] = useState<Record<string, unknown> | null>(null);
  const [extra, setExtra] = useState<Record<string, unknown>>({});
  const [changed, setChanged] = useState<Record<string, unknown>>({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      const r = await fetchApi<Record<string, unknown>>(section.path);
      setValues(r);
      setExtra(r);
    } catch (err) {
      setMsg({ ok: false, text: errorText(err, 'Could not load these settings.') });
      setValues({});
    }
  }, [section.path]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const value = (k: string) => (k in changed ? changed[k] : values?.[k]);
  const set = (k: string, v: unknown) => setChanged({ ...changed, [k]: v });

  function options(d: Def): [string, string][] {
    if (d.options) return d.options;
    if (d.hint === 'teachers' || d.hint === 'subjects') return lookups[d.hint];
    const src = extra[d.hint ?? ''];
    if (Array.isArray(src)) return src.map((x) => [String(x), String(x)]);
    if (src && typeof src === 'object') return Object.entries(src as Record<string, string>).map(([k, v]) => [k, v]);
    return [];
  }

  async function save() {
    setBusy(true);
    setMsg(null);
    try {
      await fetchApi(section.path, { method: 'PUT', body: JSON.stringify(changed) });
      setMsg({ ok: true, text: 'Saved.' });
      setChanged({});
      await load();
    } catch (err) {
      setMsg({ ok: false, text: errorText(err, 'Could not save.') });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card title={section.title} description={section.description}>
      {!values ? <Loading /> : (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            {section.fields.map((d) => {
              const v = value(d.key);
              if (d.kind === 'bool') return <label key={d.key} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!v} onChange={(e) => set(d.key, e.target.checked)} /> {d.label}</label>;
              if (d.kind === 'number') return <Field key={d.key} label={d.label}><input className={inputClass} type="number" min={d.min} max={d.max} value={v == null ? '' : String(v)} onChange={(e) => set(d.key, e.target.value === '' ? null : Number(e.target.value))} /></Field>;
              if (d.kind === 'select') return <Field key={d.key} label={d.label}><select className={inputClass} value={String(v ?? '')} onChange={(e) => set(d.key, e.target.value)}>{d.options!.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>;
              if (d.kind === 'lines') return <div key={d.key} className="sm:col-span-2"><Field label={d.label}><textarea className={`${inputClass} min-h-[5rem]`} value={(Array.isArray(v) ? v : []).join('\n')} onChange={(e) => set(d.key, e.target.value.split('\n').map((x) => x.trim()).filter(Boolean))} /></Field></div>;
              // checks / multi: a set of keys
              const list = (Array.isArray(v) ? v : []).map(String);
              const opts = options(d);
              return (
                <fieldset key={d.key} className="sm:col-span-2">
                  <legend className="mb-1 text-sm font-medium">{d.label}</legend>
                  {opts.length === 0 ? <p className="text-xs text-ink-muted">Nothing to choose from yet.</p> : (
                    <div className="flex flex-wrap gap-x-4 gap-y-1">
                      {opts.map(([k, l]) => (
                        <label key={k} className="flex items-center gap-1.5 text-sm">
                          <input type="checkbox" checked={list.includes(k)} onChange={(e) => {
                            const next = e.target.checked ? [...list, k] : list.filter((x) => x !== k);
                            set(d.key, d.kind === 'multi' ? next.map(Number) : next);
                          }} /> {l}
                        </label>
                      ))}
                    </div>
                  )}
                </fieldset>
              );
            })}
          </div>
          <div className="mt-4 flex items-center gap-3">
            <Button disabled={busy || Object.keys(changed).length === 0} onClick={save}>Save {section.title.toLowerCase()} settings</Button>
            {Object.keys(changed).length > 0 && <button type="button" className="text-sm underline" onClick={() => setChanged({})}>Undo changes</button>}
            {msg && <span className={`text-sm ${msg.ok ? 'text-positive' : 'text-negative'}`}>{msg.text}</span>}
          </div>
        </>
      )}
    </Card>
  );
}
