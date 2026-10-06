'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, ErrorNote, Field, Loading, inputClass } from '@/components/Page';

/**
 * Onboarding choices for report cards: how marks are split (any number of
 * CAs), what is rated and on what scale, and which comments are printed.
 * Teachers then fill these in per pupil on the Ratings & comments screen.
 */

type Part = { name: string; max: string; kind: 'ca' | 'exam' };
type Option = { value: string; label: string };

type SettingsResponse = {
  configured: boolean;
  settings: {
    affective_traits: string[];
    psychomotor_skills: string[];
    rating_scale: { value: string; label: string | null }[];
    form_teacher_comment: boolean;
    principal_comment: boolean;
    principal_title: string;
  };
  ca_scheme: { name: string; saved: boolean; components: { name: string; max: number; kind: 'ca' | 'exam' }[] };
};

const PRESETS: Record<string, Part[]> = {
  '20 / 20 / 60': [
    { name: '1st CA', max: '20', kind: 'ca' }, { name: '2nd CA', max: '20', kind: 'ca' }, { name: 'Exam', max: '60', kind: 'exam' },
  ],
  '10 / 10 / 10 / 70': [
    { name: '1st CA', max: '10', kind: 'ca' }, { name: '2nd CA', max: '10', kind: 'ca' }, { name: '3rd CA', max: '10', kind: 'ca' }, { name: 'Exam', max: '70', kind: 'exam' },
  ],
  '30 / 70': [{ name: 'CA', max: '30', kind: 'ca' }, { name: 'Exam', max: '70', kind: 'exam' }],
};

const lines = (text: string) => text.split('\n').map((t) => t.trim()).filter(Boolean);

export default function ReportSetup() {
  const [data, setData] = useState<SettingsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<SettingsResponse>('/report-settings')
      .then(setData)
      .catch((err) => setError(errorText(err, 'Could not load report settings.')));
  }, []);

  if (!data) return error ? <ErrorNote message={error} /> : <Loading />;

  return (
    <div className="space-y-6">
      <p className="text-sm text-ink-muted">
        Set these once, when your school starts on SchoolPilot. They decide the columns on your score sheets and what your
        report cards show. You can change them later; a change applies to scores and ratings entered after it.
      </p>
      <MarksCard scheme={data.ca_scheme} />
      <RatingsCard initial={data.settings} configured={data.configured} />
      <EarlierSchemes />
    </div>
  );
}

/** Schemes saved before: go back to one without retyping it. */
function EarlierSchemes() {
  const [schemes, setSchemes] = useState<{ id: number; name: string; is_default: boolean; components: { name: string; max: number }[] | null }[]>([]);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(() => fetchApi<{ data: typeof schemes }>('/assessment/ca-schemes').then((r) => setSchemes(r.data ?? [])).catch(() => undefined), []);
  useEffect(() => { void load(); }, [load]);

  if (schemes.length < 2) return null;

  return (
    <Card title="Earlier marking schemes" description="Switch back to a scheme you used before. Scores already entered keep the scheme they were entered under.">
      <ul className="space-y-2 text-sm">
        {schemes.map((sc) => (
          <li key={sc.id} className="flex flex-wrap items-center justify-between gap-2">
            <span><span className="font-medium">{sc.name}</span> <span className="text-ink-muted">{(sc.components ?? []).map((c) => `${c.name} ${c.max}`).join(' · ')}</span></span>
            {sc.is_default ? <span className="text-xs text-positive">in use</span> : (
              <Button variant="secondary" onClick={async () => { try { const r = await fetchApi<{ message?: string }>(`/assessment/ca-schemes/${sc.id}/activate`, { method: 'POST', body: '{}' }); setMsg(r.message ?? 'Now in use.'); await load(); } catch (err) { setMsg(errorText(err, 'Could not switch.')); } }}>Use this one</Button>
            )}
          </li>
        ))}
      </ul>
      {msg && <p className="mt-2 text-sm text-ink-muted">{msg}</p>}
    </Card>
  );
}

function MarksCard({ scheme }: { scheme: SettingsResponse['ca_scheme'] }) {
  const [name, setName] = useState(scheme.name);
  const [parts, setParts] = useState<Part[]>(scheme.components.map((c) => ({ name: c.name, max: String(c.max), kind: c.kind })));
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const total = parts.reduce((s, p) => s + (Number(p.max) || 0), 0);
  const hasCa = parts.some((p) => p.kind === 'ca');
  const hasExam = parts.some((p) => p.kind === 'exam');

  const save = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetchApi<{ message: string }>('/assessment/ca-schemes', {
        method: 'POST',
        body: JSON.stringify({ name: name.trim() || 'Marking scheme', components: parts.map((p) => ({ name: p.name.trim(), max: Number(p.max), kind: p.kind })), is_default: true }),
      });
      setMessage({ ok: true, text: res.message === 'CA scheme saved' ? 'Marking scheme saved. Score sheets now have these columns.' : res.message });
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'Could not save the marking scheme.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card
      title="1. How marks are split"
      description={scheme.saved ? 'Your continuous assessments and exam, in the order they appear on the card.' : 'Not set yet — until you save, scores are marked 20 / 20 / 60.'}
    >
      <div className="mb-3 flex flex-wrap gap-2 text-xs">
        <span className="self-center text-ink-muted">Start from:</span>
        {Object.entries(PRESETS).map(([label, preset]) => (
          <button key={label} type="button" onClick={() => { setParts(preset); setName(label); }} className="rounded-lg border border-line px-2.5 py-1 text-ink hover:bg-surface-sunken">
            {label}
          </button>
        ))}
      </div>
      <div className="space-y-2">
        {parts.map((p, i) => (
          <div key={i} className="flex flex-wrap items-center gap-2">
            <input value={p.name} onChange={(e) => setParts(parts.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} className={`${inputClass} max-w-[12rem]`} aria-label="Part name" />
            <input type="number" min={1} max={100} value={p.max} onChange={(e) => setParts(parts.map((x, j) => (j === i ? { ...x, max: e.target.value } : x)))} className={`${inputClass} w-24`} aria-label="Marks" />
            <select value={p.kind} onChange={(e) => setParts(parts.map((x, j) => (j === i ? { ...x, kind: e.target.value as Part['kind'] } : x)))} className={`${inputClass} w-auto`} aria-label="Type">
              <option value="ca">CA</option>
              <option value="exam">Exam</option>
            </select>
            {parts.length > 2 && (
              <button type="button" onClick={() => setParts(parts.filter((_, j) => j !== i))} className="text-xs text-negative hover:underline">Remove</button>
            )}
          </div>
        ))}
      </div>
      <div className="mt-3 flex flex-wrap items-center gap-4 text-xs">
        <button
          type="button"
          onClick={() => {
            const cas = parts.filter((p) => p.kind === 'ca').length;
            const at = parts.findIndex((p) => p.kind === 'exam');
            const next = { name: `${cas + 1}${['st', 'nd', 'rd'][cas] ?? 'th'} CA`, max: '10', kind: 'ca' as const };
            setParts(at === -1 ? [...parts, next] : [...parts.slice(0, at), next, ...parts.slice(at)]);
          }}
          className="font-semibold text-brand hover:underline"
        >
          + Add a CA
        </button>
        <span className={total === 100 ? 'text-positive' : 'text-caution'}>Total: {total} {total === 100 ? '' : '(marks are turned into a percentage if this is not 100)'}</span>
      </div>
      <div className="mt-4 flex flex-wrap items-end gap-3">
        <Field label="Scheme name">
          <input value={name} maxLength={100} onChange={(e) => setName(e.target.value)} className={`${inputClass} max-w-xs`} />
        </Field>
        <Button disabled={busy || !hasCa || !hasExam || total <= 0} onClick={save}>{busy ? 'Saving…' : 'Save marking scheme'}</Button>
      </div>
      {(!hasCa || !hasExam) && <p className="mt-2 text-xs text-negative">Include at least one CA and one exam.</p>}
      {message && <p className={`mt-2 text-sm ${message.ok ? 'text-positive' : 'text-negative'}`}>{message.text}</p>}
    </Card>
  );
}

function RatingsCard({ initial, configured }: { initial: SettingsResponse['settings']; configured: boolean }) {
  const [affective, setAffective] = useState(initial.affective_traits.join('\n'));
  const [psychomotor, setPsychomotor] = useState(initial.psychomotor_skills.join('\n'));
  const [scale, setScale] = useState<Option[]>(initial.rating_scale.map((o) => ({ value: o.value, label: o.label ?? '' })));
  const [formComment, setFormComment] = useState(initial.form_teacher_comment);
  const [principalComment, setPrincipalComment] = useState(initial.principal_comment);
  const [title, setTitle] = useState(initial.principal_title);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const save = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetchApi<{ message: string }>('/report-settings', {
        method: 'PUT',
        body: JSON.stringify({
          affective_traits: lines(affective),
          psychomotor_skills: lines(psychomotor),
          rating_scale: scale.filter((o) => o.value.trim()).map((o) => ({ value: o.value.trim(), label: o.label.trim() || null })),
          form_teacher_comment: formComment,
          principal_comment: principalComment,
          principal_title: title.trim() || 'Principal',
        }),
      });
      setMessage({ ok: true, text: res.message });
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'Could not save.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card
      title="2. Ratings and comments"
      description={configured ? 'What form teachers rate and write for every pupil each term.' : 'Suggested defaults are filled in — adjust them to what your report card uses, then save.'}
    >
      <div className="grid gap-6 lg:grid-cols-2">
        <Field label="Affective traits (one per line)">
          <textarea rows={7} value={affective} onChange={(e) => setAffective(e.target.value)} className={inputClass} />
        </Field>
        <Field label="Psychomotor skills (one per line)">
          <textarea rows={7} value={psychomotor} onChange={(e) => setPsychomotor(e.target.value)} className={inputClass} />
        </Field>
      </div>

      <h3 className="mt-5 text-sm font-semibold text-ink">Rating scale, best first</h3>
      <div className="mt-2 space-y-2">
        {scale.map((o, i) => (
          <div key={i} className="flex items-center gap-2">
            <input value={o.value} maxLength={5} onChange={(e) => setScale(scale.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} className={`${inputClass} w-20`} aria-label="Rating" placeholder="5" />
            <input value={o.label} maxLength={30} onChange={(e) => setScale(scale.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} className={`${inputClass} max-w-xs`} aria-label="Meaning" placeholder="Excellent" />
            {scale.length > 2 && <button type="button" onClick={() => setScale(scale.filter((_, j) => j !== i))} className="text-xs text-negative hover:underline">Remove</button>}
          </div>
        ))}
      </div>
      <div className="mt-2 flex flex-wrap gap-3 text-xs">
        <button type="button" onClick={() => setScale([...scale, { value: '', label: '' }])} className="font-semibold text-brand hover:underline">+ Add a rating</button>
        <button type="button" onClick={() => setScale([{ value: '5', label: 'Excellent' }, { value: '4', label: 'Very good' }, { value: '3', label: 'Good' }, { value: '2', label: 'Fair' }, { value: '1', label: 'Poor' }])} className="text-ink-muted hover:underline">Use 5 to 1</button>
        <button type="button" onClick={() => setScale([{ value: 'A', label: 'Excellent' }, { value: 'B', label: 'Very good' }, { value: 'C', label: 'Good' }, { value: 'D', label: 'Fair' }, { value: 'E', label: 'Poor' }])} className="text-ink-muted hover:underline">Use A to E</button>
      </div>

      <h3 className="mt-5 text-sm font-semibold text-ink">Comments on the card</h3>
      <div className="mt-2 space-y-2 text-sm text-ink">
        <label className="flex items-center gap-2"><input type="checkbox" className="h-4 w-4 accent-brand" checked={formComment} onChange={(e) => setFormComment(e.target.checked)} /> Form teacher&rsquo;s comment</label>
        <label className="flex items-center gap-2"><input type="checkbox" className="h-4 w-4 accent-brand" checked={principalComment} onChange={(e) => setPrincipalComment(e.target.checked)} /> Head of school&rsquo;s comment</label>
      </div>
      {principalComment && (
        <div className="mt-3 max-w-xs">
          <Field label="Head of school's title, as printed">
            <input value={title} maxLength={40} onChange={(e) => setTitle(e.target.value)} className={inputClass} placeholder="Principal, Head Teacher…" />
          </Field>
        </div>
      )}

      <Button className="mt-5" disabled={busy || scale.filter((o) => o.value.trim()).length < 2} onClick={save}>{busy ? 'Saving…' : 'Save ratings and comments'}</Button>
      {message && <p className={`mt-2 text-sm ${message.ok ? 'text-positive' : 'text-negative'}`}>{message.text}</p>}
    </Card>
  );
}
