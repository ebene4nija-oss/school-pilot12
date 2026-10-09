'use client';

import React, { useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { useClassSubjects } from '@/lib/useClassSubjects';
import { Button, Field, inputClass } from '@/components/Page';
import { RichText } from '@/components/RichContent';

/**
 * One question, any of the eleven types, written in the shape
 * CbtGradingService marks (docs/cbt-authoring-guide.md §3):
 *
 *   multiple_choice / image_choice  options {A: text}, correct_answer "A"
 *   true_false                      correct_answer true|false
 *   multiple_response               answer_schema.correct_options [..], partial_credit
 *   fill_blank                      answer_schema.accepted [..], case_sensitive, ignore_punctuation
 *   numeric                         answer_schema.value, tolerance, tolerance_type, unit, require_unit
 *   matching                        answer_schema.pairs {left: right}, right (with distractors)
 *   ordering                        answer_schema.items (shown), order (correct)
 *   diagram_label                   answer_schema.zones [{id,label,x,y,w,h}], label_pool; a diagram image
 *   hotspot                         answer_schema.zones [{shape:'rect',x,y,w,h}]; a diagram image
 *   theory                          answer_schema.response_format, answer_mode, words, parts; held for a teacher
 *
 * Geometry is in normalised 0–1 coordinates, so a zone drawn on a laptop
 * marks the same place on a phone. The server re-validates everything —
 * LaTeX, image ownership, a scheme for every auto-marked type.
 */

export const TYPES: [string, string][] = [
  ['multiple_choice', 'Multiple choice'], ['multiple_response', 'Choose all that apply'], ['true_false', 'True / false'],
  ['fill_blank', 'Fill in the blank'], ['numeric', 'Number answer'], ['matching', 'Matching'], ['ordering', 'Put in order'],
  ['image_choice', 'Picture options'], ['diagram_label', 'Label a diagram'], ['hotspot', 'Click on the picture'], ['theory', 'Theory / essay (teacher marks)'],
];

export interface Media { asset_id: number; url: string; alt_text: string; thumbnail_url?: string | null }
type Ref = { asset_id: number; role: string; position?: number };
type Zone = { id: string; label?: string; x: number; y: number; w: number; h: number };

export interface QuestionInput {
  id?: number;
  subject_id: number | '';
  class_id: number | '';
  group_id?: number | null;
  group_sequence?: number | null;
  topic: string;
  question: string;
  question_type: string;
  options: Record<string, string> | null;
  correct_answer: unknown;
  answer_schema: Record<string, unknown> | null;
  explanation: string;
  difficulty: string;
  marks: number;
  negative_marks: number;
  media: Ref[];
  status: string;
}

export const blankQuestion = (subjectId: number | '' = '', classId: number | '' = ''): QuestionInput => ({
  subject_id: subjectId, class_id: classId, topic: '', question: '', question_type: 'multiple_choice', options: { A: '', B: '', C: '', D: '' },
  correct_answer: 'A', answer_schema: null, explanation: '', difficulty: 'medium', marks: 1, negative_marks: 0, media: [], status: 'approved',
});

const letters = (n: number) => Array.from({ length: n }, (_, i) => String.fromCharCode(65 + i));
const lines = (s: string) => s.split('\n').map((x) => x.trim()).filter(Boolean);
const pct = (v: number) => Math.round(v * 1000) / 10;

export default function QuestionEditor({
  initial, classes, groups, media, frozen, onSaved, onCancel,
}: {
  initial: QuestionInput;
  classes: { id: number; name: string }[];
  groups: { id: number; title: string }[];
  media: Media[];
  frozen?: boolean;
  onSaved: (message: string) => void;
  onCancel: () => void;
}) {
  const [q, setQ] = useState<QuestionInput>(initial);
  // Every question is filed against a class; its subjects follow the class.
  const subjects = useClassSubjects(q.class_id);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState(false);
  // The parent remounts this with a new `key` per question, so no syncing effect.

  const schema = (q.answer_schema ?? {}) as Record<string, unknown>;
  const setSchema = (patch: Record<string, unknown>) => setQ({ ...q, answer_schema: { ...schema, ...patch } });
  const ref = (role: string) => q.media.find((m) => m.role === role);
  const setRef = (role: string, assetId: string) => setQ({
    ...q,
    media: [...q.media.filter((m) => m.role !== role), ...(assetId ? [{ asset_id: Number(assetId), role, position: 0 }] : [])],
  });
  const asset = (id?: number) => media.find((m) => m.asset_id === id);
  const diagram = asset(ref('diagram')?.asset_id ?? ref('stem')?.asset_id);
  const zones = (schema.zones as Zone[] | undefined) ?? [];

  function changeType(t: string) {
    const next: QuestionInput = { ...q, question_type: t, options: null, correct_answer: null, answer_schema: null };
    if (t === 'multiple_choice' || t === 'image_choice' || t === 'multiple_response') next.options = q.options ?? { A: '', B: '', C: '', D: '' };
    if (t === 'multiple_choice' || t === 'image_choice') next.correct_answer = 'A';
    if (t === 'true_false') next.correct_answer = true;
    if (t === 'multiple_response') next.answer_schema = { correct_options: [], partial_credit: false };
    if (t === 'fill_blank') next.answer_schema = { accepted: [], case_sensitive: false, ignore_punctuation: true };
    if (t === 'numeric') next.answer_schema = { value: '', tolerance: 0, tolerance_type: 'absolute' };
    if (t === 'matching') next.answer_schema = { pairs: {}, partial_credit: true };
    if (t === 'ordering') next.answer_schema = { items: [], order: [], partial_credit: true };
    if (t === 'diagram_label') next.answer_schema = { zones: [], label_pool: [], partial_credit: true };
    if (t === 'hotspot') next.answer_schema = { zones: [] };
    if (t === 'theory') next.answer_schema = { response_format: 'short_answer', answer_mode: 'on_screen' };
    setQ(next);
  }

  function addZone(e: React.MouseEvent<HTMLDivElement>) {
    const box = e.currentTarget.getBoundingClientRect();
    const x = Math.max(0, Math.min(0.85, (e.clientX - box.left) / box.width - 0.075));
    const y = Math.max(0, Math.min(0.9, (e.clientY - box.top) / box.height - 0.05));
    const zone: Zone = { id: `z${zones.length + 1}`, x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000, w: 0.15, h: 0.1 };
    setSchema({ zones: [...zones, q.question_type === 'hotspot' ? { ...zone, shape: 'rect' } : { ...zone, label: '' }] });
  }
  const setZone = (i: number, patch: Partial<Zone>) => setSchema({ zones: zones.map((z, j) => (j === i ? { ...z, ...patch } : z)) });

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const body: Record<string, unknown> = {
      subject_id: Number(q.subject_id), class_id: Number(q.class_id), topic: q.topic || null, question: q.question, question_type: q.question_type,
      options: q.options, correct_answer: q.correct_answer, answer_schema: q.answer_schema, explanation: q.explanation || null,
      difficulty: q.difficulty, marks: Number(q.marks), negative_marks: Number(q.negative_marks) || 0, media: q.media,
      status: q.status, group_id: q.group_id || null, group_sequence: q.group_id ? q.group_sequence || 1 : null,
    };
    if (q.question_type === 'diagram_label') body.answer_schema = { ...schema, label_pool: [...new Set([...(schema.label_pool as string[] ?? []), ...zones.map((z) => z.label).filter(Boolean)])] };
    try {
      const res = await fetchApi<{ message: string }>(q.id ? `/cbt/questions/${q.id}` : '/cbt/questions', { method: q.id ? 'PUT' : 'POST', body: JSON.stringify(body) });
      onSaved(res.message);
    } catch (err) {
      setError(errorText(err, 'Could not save the question.'));
    } finally {
      setBusy(false);
    }
  }

  const opts = q.options ?? {};
  const keys = Object.keys(opts);
  const t = q.question_type;

  return (
    <form onSubmit={save} className="space-y-4">
      {frozen && <p className="rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">This question is on a published paper. Its wording and answers can no longer change — copy it into a new question instead.</p>}
      {error && <p role="alert" className="rounded-lg bg-negative-soft px-4 py-3 text-sm text-negative">{error}</p>}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Class"><select className={inputClass} required value={q.class_id} onChange={(e) => setQ({ ...q, class_id: Number(e.target.value) || '' })}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        <Field label="Subject"><select className={inputClass} required value={q.subject_id} onChange={(e) => setQ({ ...q, subject_id: Number(e.target.value) || '' })}><option value="">Choose…</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}{q.subject_id && !subjects.some((s) => s.id === q.subject_id) && <option value={q.subject_id}>(current subject)</option>}</select></Field>
        <Field label="Topic"><input className={inputClass} value={q.topic} onChange={(e) => setQ({ ...q, topic: e.target.value })} placeholder="e.g. Simultaneous equations" /></Field>
        <Field label="Type"><select className={inputClass} value={t} onChange={(e) => changeType(e.target.value)} disabled={!!q.id}>{TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
        <Field label="Difficulty"><select className={inputClass} value={q.difficulty} onChange={(e) => setQ({ ...q, difficulty: e.target.value })}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></Field>
        <Field label="Marks"><input className={inputClass} type="number" min={0} max={100} step="0.5" value={q.marks} onChange={(e) => setQ({ ...q, marks: Number(e.target.value) })} /></Field>
        <Field label="Minus if wrong"><input className={inputClass} type="number" min={0} max={100} step="0.25" value={q.negative_marks} onChange={(e) => setQ({ ...q, negative_marks: Number(e.target.value) })} /></Field>
        <Field label="Passage / group"><select className={inputClass} value={q.group_id ?? ''} onChange={(e) => setQ({ ...q, group_id: e.target.value ? Number(e.target.value) : null })}><option value="">None</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.title}</option>)}</select></Field>
        <Field label="Status"><select className={inputClass} value={q.status} onChange={(e) => setQ({ ...q, status: e.target.value })}><option value="approved">Ready to use</option><option value="draft">Draft</option><option value="retired">Retired</option></select></Field>
      </div>

      <Field label="Question (maths in $…$, e.g. $x^2 + 1$)">
        <textarea className={`${inputClass} min-h-[5rem] font-mono`} required value={q.question} onChange={(e) => setQ({ ...q, question: e.target.value })} />
      </Field>
      <button type="button" className="text-xs text-brand underline" onClick={() => setPreview(!preview)}>{preview ? 'Hide preview' : 'Preview maths'}</button>
      {preview && <div className="rounded-lg border border-line-soft p-3 text-sm"><RichText content={q.question} contentFormat="latex" /></div>}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Picture under the question (optional)"><MediaSelect media={media} value={ref('stem')?.asset_id} onChange={(v) => setRef('stem', v)} /></Field>
        {(t === 'diagram_label' || t === 'hotspot') && <Field label="Diagram to work on (required)"><MediaSelect media={media} value={ref('diagram')?.asset_id} onChange={(v) => setRef('diagram', v)} /></Field>}
      </div>

      {/* ---- the answer, by type ---- */}
      <fieldset className="rounded-lg border border-line-soft p-4">
        <legend className="px-1 text-sm font-semibold text-brand">Answer and marking</legend>

        {(t === 'multiple_choice' || t === 'image_choice' || t === 'multiple_response') && (
          <div className="space-y-2">
            {keys.map((k) => {
              const correctList = (schema.correct_options as string[] | undefined) ?? [];
              return (
                <div key={k} className="flex flex-wrap items-center gap-2">
                  {t === 'multiple_response'
                    ? <input type="checkbox" aria-label={`${k} is correct`} checked={correctList.includes(k)} onChange={(e) => setSchema({ correct_options: e.target.checked ? [...correctList, k] : correctList.filter((x) => x !== k) })} />
                    : <input type="radio" name="correct" aria-label={`${k} is correct`} checked={q.correct_answer === k} onChange={() => setQ({ ...q, correct_answer: k })} />}
                  <span className="w-5 font-semibold">{k}</span>
                  <input className={`${inputClass} flex-1`} required={t !== 'image_choice'} value={opts[k]} onChange={(e) => setQ({ ...q, options: { ...opts, [k]: e.target.value } })} placeholder={t === 'image_choice' ? 'Caption (optional)' : `Option ${k}`} />
                  {(t === 'image_choice' || ref(`option:${k}`)) && <span className="w-44"><MediaSelect media={media} value={ref(`option:${k}`)?.asset_id} onChange={(v) => setRef(`option:${k}`, v)} /></span>}
                  {keys.length > 2 && <button type="button" className="text-xs text-ink-muted underline" onClick={() => { const n = { ...opts }; delete n[k]; setQ({ ...q, options: n }); }}>Remove</button>}
                </div>
              );
            })}
            {keys.length < 8 && <Button type="button" variant="secondary" onClick={() => { const k = letters(keys.length + 1).find((x) => !keys.includes(x)) ?? 'Z'; setQ({ ...q, options: { ...opts, [k]: '' } }); }}>Add option</Button>}
            <p className="text-xs text-ink-muted">{t === 'multiple_response' ? 'Tick every correct option.' : 'Choose the correct option.'}</p>
            {t === 'multiple_response' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schema.partial_credit} onChange={(e) => setSchema({ partial_credit: e.target.checked })} /> Part marks (right picks minus wrong picks); otherwise all-or-nothing</label>}
          </div>
        )}

        {t === 'true_false' && (
          <div className="flex gap-4 text-sm">
            <label className="flex items-center gap-2"><input type="radio" checked={q.correct_answer === true} onChange={() => setQ({ ...q, correct_answer: true })} /> True is correct</label>
            <label className="flex items-center gap-2"><input type="radio" checked={q.correct_answer === false} onChange={() => setQ({ ...q, correct_answer: false })} /> False is correct</label>
          </div>
        )}

        {t === 'fill_blank' && (
          <div className="space-y-2">
            <Field label="Accepted answers, one per line (every spelling you will accept)"><textarea className={`${inputClass} min-h-[4rem]`} required value={((schema.accepted as string[]) ?? []).join('\n')} onChange={(e) => setSchema({ accepted: lines(e.target.value) })} /></Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schema.case_sensitive} onChange={(e) => setSchema({ case_sensitive: e.target.checked })} /> Capital letters must match</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schema.ignore_punctuation} onChange={(e) => setSchema({ ignore_punctuation: e.target.checked })} /> Ignore punctuation and hyphens</label>
          </div>
        )}

        {t === 'numeric' && (
          <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <Field label="Correct value"><input className={inputClass} required inputMode="decimal" value={String(schema.value ?? '')} onChange={(e) => setSchema({ value: e.target.value === '' ? '' : Number(e.target.value) })} /></Field>
            <Field label="Allow ±"><input className={inputClass} type="number" min={0} step="any" value={String(schema.tolerance ?? 0)} onChange={(e) => setSchema({ tolerance: Number(e.target.value) })} /></Field>
            <Field label="± means"><select className={inputClass} value={String(schema.tolerance_type ?? 'absolute')} onChange={(e) => setSchema({ tolerance_type: e.target.value })}><option value="absolute">units</option><option value="percent">percent</option></select></Field>
            <Field label="Unit (optional)"><input className={inputClass} value={String(schema.unit ?? '')} onChange={(e) => setSchema({ unit: e.target.value || undefined })} placeholder="m/s²" /></Field>
            <Field label="Decimal places hint"><input className={inputClass} type="number" min={0} max={10} value={String(schema.decimal_places_hint ?? '')} onChange={(e) => setSchema({ decimal_places_hint: e.target.value === '' ? undefined : Number(e.target.value) })} /></Field>
            <label className="flex items-center gap-2 self-end text-sm"><input type="checkbox" checked={!!schema.require_unit} onChange={(e) => setSchema({ require_unit: e.target.checked })} /> Unit must match</label>
          </div>
        )}

        {t === 'matching' && (
          <div className="space-y-2">
            <p className="text-xs text-ink-muted">Each pair is the correct match. Students see the right-hand column shuffled, plus any extra wrong choices.</p>
            {Object.entries((schema.pairs as Record<string, string>) ?? {}).map(([l, r], i) => (
              <div key={i} className="flex gap-2">
                <input className={inputClass} value={l} readOnly aria-label="Left item" />
                <span className="self-center">→</span>
                <input className={inputClass} value={r} readOnly aria-label="Its match" />
                <button type="button" className="text-xs text-ink-muted underline" onClick={() => { const p = { ...(schema.pairs as Record<string, string>) }; delete p[l]; setSchema({ pairs: p, right: undefined, left: undefined }); }}>Remove</button>
              </div>
            ))}
            <PairAdder onAdd={(l, r) => setSchema({ pairs: { ...((schema.pairs as Record<string, string>) ?? {}), [l]: r }, left: undefined, right: undefined })} />
            <Field label="Extra wrong choices for the right column (optional, one per line)">
              <textarea className={`${inputClass} min-h-[3rem]`} value={((schema.distractors as string[]) ?? []).join('\n')} onChange={(e) => {
                const distractors = lines(e.target.value);
                const pairs = (schema.pairs as Record<string, string>) ?? {};
                setSchema({ distractors, right: distractors.length ? [...Object.values(pairs), ...distractors] : undefined, left: distractors.length ? Object.keys(pairs) : undefined });
              }} />
            </Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={schema.partial_credit !== false} onChange={(e) => setSchema({ partial_credit: e.target.checked })} /> Part marks for each correct pair</label>
          </div>
        )}

        {t === 'ordering' && (
          <div className="space-y-2">
            <Field label="Items in the CORRECT order, one per line (students see them shuffled)">
              <textarea className={`${inputClass} min-h-[6rem]`} required value={((schema.order as string[]) ?? []).join('\n')} onChange={(e) => { const order = lines(e.target.value); setSchema({ order, items: order }); }} />
            </Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schema.partial_credit} onChange={(e) => setSchema({ partial_credit: e.target.checked })} /> Part marks for each item in the right place</label>
          </div>
        )}

        {(t === 'diagram_label' || t === 'hotspot') && (
          <div className="space-y-3">
            {!diagram ? <p className="text-sm text-ink-muted">Choose a diagram above, then click on it to place {t === 'hotspot' ? 'the correct area(s)' : 'each label box'}.</p> : (
              <>
                <p className="text-xs text-ink-muted">Click the picture to add a {t === 'hotspot' ? 'correct area' : 'label box'}; adjust its position and size below (percent of the picture).</p>
                <div className="relative inline-block max-w-full cursor-crosshair" onClick={addZone} role="presentation">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={diagram.url} alt={diagram.alt_text} className="max-w-full rounded border border-line-soft bg-white" />
                  {zones.map((z, i) => (
                    <span key={i} className="pointer-events-none absolute flex items-center justify-center border-2 border-brand bg-brand/20 text-xs font-bold text-brand" style={{ left: `${z.x * 100}%`, top: `${z.y * 100}%`, width: `${z.w * 100}%`, height: `${z.h * 100}%` }}>{z.label || i + 1}</span>
                  ))}
                </div>
                {zones.map((z, i) => (
                  <div key={i} className="flex flex-wrap items-end gap-2 text-sm">
                    <span className="w-10 font-semibold">#{i + 1}</span>
                    {t === 'diagram_label' && <Field label="Correct label"><input className={inputClass} required value={z.label ?? ''} onChange={(e) => setZone(i, { label: e.target.value })} /></Field>}
                    {(['x', 'y', 'w', 'h'] as const).map((k) => (
                      <Field key={k} label={{ x: 'Left %', y: 'Top %', w: 'Width %', h: 'Height %' }[k]}><input className={`${inputClass} w-20`} type="number" min={0} max={100} step="0.5" value={pct(z[k])} onChange={(e) => setZone(i, { [k]: Math.max(0, Math.min(100, Number(e.target.value))) / 100 })} /></Field>
                    ))}
                    <button type="button" className="text-xs text-ink-muted underline" onClick={() => setSchema({ zones: zones.filter((_, j) => j !== i) })}>Remove</button>
                  </div>
                ))}
                {t === 'diagram_label' && (
                  <Field label="Extra wrong labels to choose from (optional, one per line)">
                    <textarea className={`${inputClass} min-h-[3rem]`} value={((schema.label_pool as string[]) ?? []).filter((l) => !zones.some((z) => z.label === l)).join('\n')} onChange={(e) => setSchema({ label_pool: lines(e.target.value) })} />
                  </Field>
                )}
              </>
            )}
          </div>
        )}

        {t === 'theory' && (
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Field label="Answer shape"><select className={inputClass} value={String(schema.response_format ?? 'short_answer')} onChange={(e) => setSchema({ response_format: e.target.value, parts: e.target.value === 'structured' ? (schema.parts ?? [{ key: 'a', prompt: '', marks: undefined }]) : undefined })}><option value="short_answer">Short answer</option><option value="essay">Essay</option><option value="structured">Parts (a), (b), (c)</option></select></Field>
              <Field label="Written"><select className={inputClass} value={String(schema.answer_mode ?? 'on_screen')} onChange={(e) => setSchema({ answer_mode: e.target.value, max_words: e.target.value === 'on_paper' ? undefined : schema.max_words })}><option value="on_screen">On screen</option><option value="on_paper">On paper (answer booklet)</option></select></Field>
              <Field label="Expected words"><input className={inputClass} type="number" min={1} value={String(schema.expected_words ?? '')} onChange={(e) => setSchema({ expected_words: e.target.value ? Number(e.target.value) : undefined })} /></Field>
              {schema.answer_mode !== 'on_paper' && <Field label="Word limit"><input className={inputClass} type="number" min={1} value={String(schema.max_words ?? '')} onChange={(e) => setSchema({ max_words: e.target.value ? Number(e.target.value) : undefined })} /></Field>}
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schema.allow_working_photo} onChange={(e) => setSchema({ allow_working_photo: e.target.checked })} /> Students may add a photo of their working (phone camera)</label>
            {schema.response_format === 'structured' && (
              <div className="space-y-2">
                {((schema.parts as { key: string; prompt: string; marks?: number }[]) ?? []).map((p, i, all) => (
                  <div key={i} className="flex flex-wrap items-end gap-2">
                    <Field label="Part"><input className={`${inputClass} w-16`} value={p.key} onChange={(e) => setSchema({ parts: all.map((x, j) => (j === i ? { ...x, key: e.target.value } : x)) })} /></Field>
                    <div className="min-w-[14rem] flex-1"><Field label="Prompt"><input className={inputClass} required value={p.prompt} onChange={(e) => setSchema({ parts: all.map((x, j) => (j === i ? { ...x, prompt: e.target.value } : x)) })} /></Field></div>
                    <Field label="Marks"><input className={`${inputClass} w-20`} type="number" min={0} value={p.marks ?? ''} onChange={(e) => setSchema({ parts: all.map((x, j) => (j === i ? { ...x, marks: e.target.value ? Number(e.target.value) : undefined } : x)) })} /></Field>
                    <button type="button" className="text-xs text-ink-muted underline" onClick={() => setSchema({ parts: all.filter((_, j) => j !== i) })}>Remove</button>
                  </div>
                ))}
                <Button type="button" variant="secondary" onClick={() => { const all = (schema.parts as { key: string; prompt: string }[]) ?? []; setSchema({ parts: [...all, { key: String.fromCharCode(97 + all.length), prompt: '' }] }); }}>Add part</Button>
              </div>
            )}
            <p className="text-xs text-ink-muted">Theory answers are held for you to mark on the exam’s Results tab.</p>
          </div>
        )}
      </fieldset>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Explanation (shown to students after results are released)"><textarea className={`${inputClass} min-h-[4rem]`} value={q.explanation} onChange={(e) => setQ({ ...q, explanation: e.target.value })} /></Field>
        <Field label="Picture with the explanation (optional)"><MediaSelect media={media} value={ref('explanation')?.asset_id} onChange={(v) => setRef('explanation', v)} /></Field>
      </div>

      <div className="flex gap-2">
        <Button type="submit" disabled={busy || frozen}>{busy ? 'Saving…' : q.id ? 'Save changes' : 'Add to bank'}</Button>
        <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
}

function MediaSelect({ media, value, onChange }: { media: Media[]; value?: number; onChange: (v: string) => void }) {
  return (
    <select className={inputClass} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
      <option value="">No picture</option>
      {media.map((m) => <option key={m.asset_id} value={m.asset_id}>#{m.asset_id} — {m.alt_text}</option>)}
    </select>
  );
}

function PairAdder({ onAdd }: { onAdd: (l: string, r: string) => void }) {
  const [l, setL] = useState('');
  const [r, setR] = useState('');
  return (
    <div className="flex gap-2">
      <input className={inputClass} placeholder="Left item, e.g. Lagos" value={l} onChange={(e) => setL(e.target.value)} />
      <span className="self-center">→</span>
      <input className={inputClass} placeholder="Its match, e.g. Ikeja" value={r} onChange={(e) => setR(e.target.value)} />
      <Button type="button" variant="secondary" disabled={!l.trim() || !r.trim()} onClick={() => { onAdd(l.trim(), r.trim()); setL(''); setR(''); }}>Add pair</Button>
    </div>
  );
}
