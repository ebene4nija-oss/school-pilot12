'use client';

import React, { useMemo } from 'react';
import { RichText, type MediaReference } from '@/components/RichContent';
import { inputClass } from '@/components/Page';

/**
 * Answer inputs for every CBT question type.
 *
 * Each writes the exact response envelope CbtGradingService reads for that
 * type — `option` for single choice, `options` for multi-select, `order`,
 * `pairs`, `labels`, `{x, y}`, `text`, `parts`. A bare value would be stored
 * as `{value}` and a correct answer marked wrong, which is the failure the
 * mobile client's CbtResponse class exists to prevent; these mirror it.
 */

export type Response = Record<string, unknown> | null;

export interface Question {
  number: number;
  question_id: number;
  question_type: string;
  content_format: 'plain' | 'latex';
  topic: string | null;
  section: string | null;
  marks: number;
  negative_marks: number;
  question: string;
  media: MediaReference[];
  options: { key: string; text: string; image: MediaReference | null }[];
  interaction: {
    response_format?: 'short_answer' | 'structured' | 'essay';
    answer_mode?: 'on_screen' | 'on_paper';
    expected_words?: number;
    max_words?: number;
    parts?: { key: string; prompt: string; marks?: number }[];
    items?: string[];
    left?: string[];
    right?: string[];
    unit?: string;
    decimal_places_hint?: number;
    zones?: { id: string; x: number; y: number; w: number; h: number }[];
    label_pool?: string[];
  };
  saved_response: Response;
  group?: { group_id: number; title: string | null; stimulus: string | null; content_format: 'plain' | 'latex'; instructions: string | null; media: MediaReference[]; position: number; of: number };
}

/** Whether a response counts as an answer, by the grader's own rule (isBlank). */
export function isAnswered(r: Response): boolean {
  if (!r) return false;
  for (const k of ['option', 'selected_option', 'text', 'value']) {
    const v = r[k];
    if (v !== undefined && v !== null && String(v).trim() !== '') return true;
  }
  for (const k of ['options', 'order', 'media_asset_ids']) {
    if (Array.isArray(r[k]) && (r[k] as unknown[]).length > 0) return true;
  }
  for (const k of ['pairs', 'labels', 'parts']) {
    const v = r[k] as Record<string, string> | undefined;
    if (v && Object.values(v).some((x) => String(x ?? '').trim() !== '')) return true;
  }
  return r.x !== undefined && r.y !== undefined;
}

/** A stable shuffle per question, so the order does not jump between visits. */
function seeded<T>(items: T[], seed: number): T[] {
  const out = [...items];
  let s = seed || 1;
  for (let i = out.length - 1; i > 0; i--) {
    s = (s * 9301 + 49297) % 233280;
    const j = Math.floor((s / 233280) * (i + 1));
    [out[i], out[j]] = [out[j], out[i]];
  }
  return out;
}

const words = (t: string) => (t.trim() ? t.trim().split(/\s+/).length : 0);

export function AnswerInput({ q, value, onChange }: { q: Question; value: Response; onChange: (r: Response) => void }) {
  switch (q.question_type) {
    case 'true_false':
      return (
        <div className="flex gap-3">
          {[true, false].map((b) => (
            <Choice key={String(b)} selected={value?.value === b} onSelect={() => onChange({ value: b })} type="radio">
              {b ? 'True' : 'False'}
            </Choice>
          ))}
        </div>
      );

    case 'multiple_response': {
      const picked = new Set((value?.options as string[] | undefined) ?? []);
      return (
        <div className="space-y-2">
          <p className="text-xs text-ink-muted">Choose all that apply.</p>
          {q.options.map((o) => (
            <Choice key={o.key} type="checkbox" selected={picked.has(o.key)} onSelect={() => {
              const next = new Set(picked);
              if (next.has(o.key)) next.delete(o.key); else next.add(o.key);
              onChange(next.size ? { options: [...next] } : null);
            }}>
              <OptionBody o={o} q={q} />
            </Choice>
          ))}
        </div>
      );
    }

    case 'fill_blank':
      return <input className={inputClass} value={String(value?.text ?? '')} onChange={(e) => onChange(e.target.value ? { text: e.target.value } : null)} placeholder="Your answer" autoComplete="off" spellCheck={false} />;

    case 'numeric':
      return (
        <div className="flex max-w-sm items-center gap-2">
          <input className={inputClass} inputMode="decimal" value={String(value?.value ?? '')} placeholder={q.interaction.decimal_places_hint !== undefined ? `to ${q.interaction.decimal_places_hint} decimal places` : 'Number'}
            onChange={(e) => onChange(e.target.value ? { value: e.target.value, ...(q.interaction.unit ? { unit: q.interaction.unit } : {}) } : null)} autoComplete="off" />
          {q.interaction.unit && <span className="text-sm text-ink-muted">{q.interaction.unit}</span>}
        </div>
      );

    case 'matching':
      return <Matching q={q} value={value} onChange={onChange} />;

    case 'ordering':
      return <Ordering q={q} value={value} onChange={onChange} />;

    case 'diagram_label':
      return <DiagramLabel q={q} value={value} onChange={onChange} />;

    case 'hotspot':
      return <Hotspot q={q} value={value} onChange={onChange} />;

    case 'theory':
      return <Theory q={q} value={value} onChange={onChange} />;

    default:
      // multiple_choice, image_choice, and any newer type that ships options.
      if (q.options.length === 0) {
        return <textarea className={`${inputClass} min-h-[8rem]`} value={String(value?.text ?? '')} onChange={(e) => onChange(e.target.value ? { text: e.target.value } : null)} />;
      }
      return (
        <div className={q.question_type === 'image_choice' ? 'grid gap-3 sm:grid-cols-2' : 'space-y-2'}>
          {q.options.map((o) => (
            <Choice key={o.key} type="radio" selected={value?.option === o.key} onSelect={() => onChange({ option: o.key })}>
              <OptionBody o={o} q={q} />
            </Choice>
          ))}
        </div>
      );
  }
}

function Choice({ selected, onSelect, type, children }: { selected: boolean; onSelect: () => void; type: 'radio' | 'checkbox'; children: React.ReactNode }) {
  return (
    <button type="button" role={type} aria-checked={selected} onClick={onSelect}
      className={`flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm transition ${selected ? 'border-brand bg-info-soft ring-2 ring-brand/30' : 'border-line hover:border-brand'}`}>
      <span aria-hidden="true" className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center border ${type === 'radio' ? 'rounded-full' : 'rounded'} ${selected ? 'border-brand bg-brand' : 'border-line'}`}>
        {selected && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
      </span>
      <span className="min-w-0 flex-1">{children}</span>
    </button>
  );
}

function OptionBody({ o, q }: { o: Question['options'][number]; q: Question }) {
  return (
    <>
      <span className="mr-2 font-semibold">{o.key}.</span>
      <RichText content={o.text} contentFormat={q.content_format} />
      {o.image && (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={o.image.url} alt={o.image.alt_text} loading="eager" className="mt-2 max-h-48 rounded border border-line-soft bg-white" />
      )}
    </>
  );
}

function Matching({ q, value, onChange }: { q: Question; value: Response; onChange: (r: Response) => void }) {
  const pairs = (value?.pairs as Record<string, string> | undefined) ?? {};
  const right = useMemo(() => seeded(q.interaction.right ?? [], q.question_id), [q]);
  return (
    <div className="space-y-2">
      {(q.interaction.left ?? []).map((l) => (
        <label key={l} className="grid items-center gap-2 sm:grid-cols-2">
          <span className="text-sm font-medium">{l}</span>
          <select className={inputClass} value={pairs[l] ?? ''} onChange={(e) => {
            const next = { ...pairs, [l]: e.target.value };
            if (!e.target.value) delete next[l];
            onChange(Object.keys(next).length ? { pairs: next } : null);
          }}>
            <option value="">Choose a match…</option>
            {right.map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
        </label>
      ))}
    </div>
  );
}

function Ordering({ q, value, onChange }: { q: Question; value: Response; onChange: (r: Response) => void }) {
  const initial = useMemo(() => seeded(q.interaction.items ?? [], q.question_id), [q]);
  const order = (value?.order as string[] | undefined) ?? initial;
  const move = (i: number, d: -1 | 1) => {
    const next = [...order];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    onChange({ order: next });
  };
  return (
    <div>
      <p className="mb-2 text-xs text-ink-muted">Put these in the right order, first at the top.{!value && ' Move an item, or confirm the order as it is.'}</p>
      <ol className="space-y-2">
        {order.map((item, i) => (
          <li key={item} className="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm">
            <span className="w-6 text-ink-muted">{i + 1}.</span>
            <span className="flex-1">{item}</span>
            <button type="button" aria-label={`Move ${item} up`} disabled={i === 0} onClick={() => move(i, -1)} className="rounded px-2 py-1 hover:bg-surface-sunken disabled:opacity-30">▲</button>
            <button type="button" aria-label={`Move ${item} down`} disabled={i === order.length - 1} onClick={() => move(i, 1)} className="rounded px-2 py-1 hover:bg-surface-sunken disabled:opacity-30">▼</button>
          </li>
        ))}
      </ol>
      {!value && <button type="button" className="mt-2 text-sm text-brand underline" onClick={() => onChange({ order })}>This order is my answer</button>}
    </div>
  );
}

/** The image a diagram or hotspot question is drawn on: its first stem/diagram media. */
const baseImage = (q: Question) => q.media.find((m) => m.role === 'diagram') ?? q.media.find((m) => m.role === 'stem') ?? q.media[0];

function DiagramLabel({ q, value, onChange }: { q: Question; value: Response; onChange: (r: Response) => void }) {
  const labels = (value?.labels as Record<string, string> | undefined) ?? {};
  const pool = useMemo(() => seeded(q.interaction.label_pool ?? [], q.question_id), [q]);
  const img = baseImage(q);
  const zones = q.interaction.zones ?? [];
  return (
    <div className="space-y-3">
      {img && (
        <div className="relative inline-block max-w-full">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={img.url} alt={img.alt_text} loading="eager" className="max-w-full rounded border border-line-soft bg-white" />
          {zones.map((z, i) => (
            <span key={z.id} className="absolute flex items-center justify-center rounded border-2 border-brand bg-white/70 text-xs font-bold text-brand"
              style={{ left: `${z.x * 100}%`, top: `${z.y * 100}%`, width: `${z.w * 100}%`, height: `${z.h * 100}%` }}>
              {labels[z.id] ? <span className="truncate px-1">{labels[z.id]}</span> : i + 1}
            </span>
          ))}
        </div>
      )}
      <div className="grid gap-2 sm:grid-cols-2">
        {zones.map((z, i) => (
          <label key={z.id} className="flex items-center gap-2 text-sm">
            <span className="w-16 shrink-0 font-medium">Box {i + 1}</span>
            <select className={inputClass} value={labels[z.id] ?? ''} onChange={(e) => {
              const next = { ...labels, [z.id]: e.target.value };
              if (!e.target.value) delete next[z.id];
              onChange(Object.keys(next).length ? { labels: next } : null);
            }}>
              <option value="">Choose a label…</option>
              {pool.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
          </label>
        ))}
      </div>
    </div>
  );
}

function Hotspot({ q, value, onChange }: { q: Question; value: Response; onChange: (r: Response) => void }) {
  const img = baseImage(q);
  if (!img) return <p className="text-sm text-negative">This question&apos;s picture is missing. Tell your invigilator.</p>;
  const x = value?.x as number | undefined;
  const y = value?.y as number | undefined;
  return (
    <div>
      <p className="mb-2 text-xs text-ink-muted">Click the right place on the picture. Click again to change it.</p>
      <div className="relative inline-block max-w-full cursor-crosshair">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={img.url} alt={img.alt_text} loading="eager" className="max-w-full rounded border border-line-soft bg-white"
          onClick={(e) => {
            const box = e.currentTarget.getBoundingClientRect();
            const nx = Math.min(1, Math.max(0, (e.clientX - box.left) / box.width));
            const ny = Math.min(1, Math.max(0, (e.clientY - box.top) / box.height));
            onChange({ x: Math.round(nx * 10000) / 10000, y: Math.round(ny * 10000) / 10000 });
          }} />
        {x !== undefined && y !== undefined && (
          <span aria-hidden="true" className="pointer-events-none absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-4 border-negative bg-white/60" style={{ left: `${x * 100}%`, top: `${y * 100}%` }} />
        )}
      </div>
    </div>
  );
}

function Theory({ q, value, onChange }: { q: Question; value: Response; onChange: (r: Response) => void }) {
  const i = q.interaction;
  if (i.answer_mode === 'on_paper') {
    return <p className="rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">Write this answer in your paper answer booklet, not on screen. Number it {q.number}.</p>;
  }
  if (i.response_format === 'structured' && i.parts?.length) {
    const parts = (value?.parts as Record<string, string> | undefined) ?? {};
    return (
      <div className="space-y-3">
        {i.parts.map((p) => (
          <label key={p.key} className="block">
            <span className="mb-1 block text-sm font-medium">({p.key}) {p.prompt}{p.marks !== undefined && <span className="text-ink-muted"> · {p.marks} marks</span>}</span>
            <textarea className={`${inputClass} min-h-[5rem]`} value={parts[p.key] ?? ''} onChange={(e) => {
              const next = { ...parts, [p.key]: e.target.value };
              onChange(Object.values(next).some((v) => v.trim()) ? { parts: next } : null);
            }} />
          </label>
        ))}
      </div>
    );
  }
  const text = String(value?.text ?? '');
  const n = words(text);
  return (
    <div>
      <textarea className={`${inputClass} ${i.response_format === 'essay' ? 'min-h-[18rem]' : 'min-h-[7rem]'}`} value={text} onChange={(e) => onChange(e.target.value ? { text: e.target.value } : null)} />
      <p className={`mt-1 text-xs ${i.max_words && n > i.max_words ? 'text-negative' : 'text-ink-muted'}`}>
        {n} words{i.expected_words ? ` · about ${i.expected_words} expected` : ''}{i.max_words ? ` · at most ${i.max_words}` : ''}
      </p>
    </div>
  );
}
