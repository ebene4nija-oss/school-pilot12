'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { errorText, fetchApi, uploadApi } from '@/lib/api';

/**
 * The ID card designer (TASK 18). A school brings its own artwork — the card
 * front or back it had designed in Canva or at a print shop, exported as an
 * image — and drags SchoolPilot's live fields onto it: photo, name, class,
 * admission number, QR code and so on. Or it starts from a blank card.
 *
 * Positions are millimetres from the card's top-left corner, which is what
 * the server compiles and prints. The preview is drawn by the same engine as
 * a print run, so what it shows is what comes off the printer.
 */

type ElementType = 'field' | 'text' | 'photo' | 'qr' | 'logo' | 'box';
type Side = 'front' | 'back';
type HolderType = 'student' | 'staff';
type Orientation = 'landscape' | 'portrait';

export interface CardElement {
  type: ElementType;
  x: number;
  y: number;
  w: number;
  h: number;
  field?: string;
  text?: string;
  font_size?: number;
  bold?: boolean;
  upper?: boolean;
  colour?: string;
  align?: 'left' | 'center' | 'right';
  show_caption?: boolean;
}

interface SideSpec {
  background_colour: string;
  elements: CardElement[];
}

export interface Layout {
  orientation: Orientation;
  front: SideSpec;
  back: SideSpec;
}

interface Field {
  key: string;
  label: string;
  caption: string | null;
  for: HolderType | null;
  sample: Record<HolderType, string>;
}

type Assets = { front_background?: string; back_background?: string };

export interface DesignerStart {
  name: string;
  holder_type: HolderType;
  layout: Layout;
  assets: Assets;
}

const SCALE = 6; // screen pixels per millimetre
const SNAP = 0.5;
const snap = (v: number) => Math.round(v / SNAP) * SNAP;

const blank = (orientation: Orientation): Layout => ({
  orientation,
  front: { background_colour: '#ffffff', elements: [] },
  back: { background_colour: '#ffffff', elements: [] },
});

/** A sensible starting card: everything required, laid out for the orientation. */
function starter(orientation: Orientation): Layout {
  const l = blank(orientation);
  l.front.elements =
    orientation === 'landscape'
      ? [
          { type: 'field', field: 'school.name', x: 4, y: 3, w: 60, h: 6, font_size: 8, bold: true, upper: true, colour: '#12141a' },
          { type: 'photo', x: 4, y: 12, w: 22, h: 28 },
          { type: 'field', field: 'holder.name', x: 30, y: 13, w: 34, h: 9, font_size: 9, bold: true, upper: true, colour: '#12141a' },
          { type: 'field', field: 'holder.id_value', x: 30, y: 25, w: 34, h: 4, font_size: 6, show_caption: true, colour: '#12141a' },
          { type: 'field', field: 'card.expires_on', x: 30, y: 30, w: 34, h: 4, font_size: 6, show_caption: true, colour: '#12141a' },
          { type: 'qr', x: 68, y: 12, w: 14, h: 14 },
        ]
      : [
          { type: 'field', field: 'school.name', x: 3, y: 4, w: 48, h: 6, font_size: 7, bold: true, upper: true, align: 'center', colour: '#12141a' },
          { type: 'photo', x: 15, y: 13, w: 24, h: 29 },
          { type: 'field', field: 'holder.name', x: 3, y: 45, w: 48, h: 9, font_size: 9, bold: true, upper: true, align: 'center', colour: '#12141a' },
          { type: 'field', field: 'holder.id_value', x: 4, y: 64, w: 30, h: 4, font_size: 5.5, show_caption: true, colour: '#12141a' },
          { type: 'qr', x: 36, y: 66, w: 14, h: 14 },
        ];
  return l;
}

export default function CardDesigner({ start, onSaved, onClose }: { start?: DesignerStart; onSaved: () => void; onClose: () => void }) {
  const [name, setName] = useState(start?.name ?? '');
  const [holderType, setHolderType] = useState<HolderType>(start?.holder_type ?? 'student');
  const [layout, setLayout] = useState<Layout>(start?.layout ?? starter('landscape'));
  const [assets, setAssets] = useState<Assets>(start?.assets ?? {});
  const [side, setSide] = useState<Side>('front');
  const [selected, setSelected] = useState<number | null>(null);
  const [history, setHistory] = useState<Layout[]>([]);
  const [fields, setFields] = useState<Field[]>([]);
  const [preview, setPreview] = useState<{ html: string; warnings: string[] } | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [longName, setLongName] = useState(false);
  const dragRef = useRef<{ index: number; mode: 'move' | 'resize'; sx: number; sy: number; el: CardElement; before: Layout } | null>(null);

  const [cw, ch] = layout.orientation === 'portrait' ? [54, 85.6] : [85.6, 54];
  const elements = layout[side].elements;
  const current = selected !== null ? elements[selected] : null;

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await fetchApi<{ fields: Field[] }>('/id-cards/designer/fields');
        if (!cancelled) setFields(res.fields);
      } catch (err) {
        if (!cancelled) setMessage({ ok: false, text: errorText(err, 'Could not load the fields.') });
      }
    })();
    return () => { cancelled = true; };
  }, []);

  /** Every change goes through here, so it can be undone. */
  const commit = useCallback((next: Layout, before?: Layout) => {
    setHistory((h) => [...h.slice(-49), before ?? layout]);
    setLayout(next);
    setPreview(null);
  }, [layout]);

  const updateElements = (fn: (els: CardElement[]) => CardElement[]) =>
    commit({ ...layout, [side]: { ...layout[side], elements: fn(layout[side].elements) } });

  const updateSelected = (patch: Partial<CardElement>) => {
    if (selected === null) return;
    updateElements((els) => els.map((e, i) => (i === selected ? clampEl({ ...e, ...patch }, cw, ch) : e)));
  };

  const undo = useCallback(() => {
    setHistory((h) => {
      if (h.length === 0) return h;
      setLayout(h[h.length - 1]);
      setSelected(null);
      setPreview(null);
      return h.slice(0, -1);
    });
  }, []);

  const add = (el: CardElement) => {
    updateElements((els) => [...els, clampEl(el, cw, ch)]);
    setSelected(elements.length);
  };

  const remove = useCallback(() => {
    if (selected === null) return;
    commit({ ...layout, [side]: { ...layout[side], elements: layout[side].elements.filter((_, i) => i !== selected) } });
    setSelected(null);
  }, [commit, layout, selected, side]);

  // Keyboard: arrows nudge, Delete removes, Ctrl+Z undoes. Not while typing.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        undo();
        return;
      }
      if (selected === null) return;
      if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        remove();
        return;
      }
      const step = e.shiftKey ? 2 : SNAP;
      const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
      if (d) {
        e.preventDefault();
        const el = layout[side].elements[selected];
        commit({
          ...layout,
          [side]: { ...layout[side], elements: layout[side].elements.map((x, i) => (i === selected ? clampEl({ ...el, x: el.x + d[0], y: el.y + d[1] }, cw, ch) : x)) },
        });
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [commit, cw, ch, layout, remove, selected, side, undo]);

  // Dragging: pointer events, snapped to half a millimetre, kept on the card.
  const startDrag = (e: React.PointerEvent, index: number, mode: 'move' | 'resize') => {
    e.stopPropagation();
    e.preventDefault();
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    setSelected(index);
    dragRef.current = { index, mode, sx: e.clientX, sy: e.clientY, el: elements[index], before: layout };
  };
  const onDrag = (e: React.PointerEvent) => {
    const d = dragRef.current;
    if (!d) return;
    const dx = (e.clientX - d.sx) / SCALE;
    const dy = (e.clientY - d.sy) / SCALE;
    const moved = d.mode === 'move'
      ? { ...d.el, x: snap(d.el.x + dx), y: snap(d.el.y + dy) }
      : { ...d.el, w: snap(d.el.w + dx), h: snap(d.el.h + dy) };
    setLayout((l) => ({ ...l, [side]: { ...l[side], elements: l[side].elements.map((x, i) => (i === d.index ? clampEl(moved, cw, ch) : x)) } }));
  };
  const endDrag = () => {
    const d = dragRef.current;
    dragRef.current = null;
    if (d) {
      setHistory((h) => [...h.slice(-49), d.before]);
      setPreview(null);
    }
  };

  async function uploadBackground(file: File) {
    setBusy(true);
    setMessage(null);
    try {
      const form = new FormData();
      form.append('image', file);
      form.append('orientation', layout.orientation);
      const res = await uploadApi<{ ref: string; warnings: string[] }>('/id-cards/designer/backgrounds', form);
      setAssets((a) => ({ ...a, [`${side}_background`]: res.ref }));
      setPreview(null);
      setMessage(res.warnings.length ? { ok: false, text: res.warnings.join(' ') } : { ok: true, text: 'Artwork added. Now place the fields on it.' });
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'That image could not be used.') });
    } finally {
      setBusy(false);
    }
  }

  function changeOrientation(o: Orientation) {
    if (o === layout.orientation) return;
    if ((layout.front.elements.length || layout.back.elements.length || assets.front_background || assets.back_background)
      && !window.confirm('Turning the card starts a fresh layout and removes the artwork. Continue?')) return;
    commit(starter(o));
    setAssets({});
    setSelected(null);
  }

  async function runPreview() {
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetchApi<{ html: string; warnings: string[] }>('/id-cards/designer/preview', {
        method: 'POST',
        body: JSON.stringify({ holder_type: holderType, layout, assets, long_name: longName }),
      });
      setPreview(res);
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'The preview could not be drawn.') });
    } finally {
      setBusy(false);
    }
  }

  async function save(activate: boolean) {
    if (name.trim().length < 2) {
      setMessage({ ok: false, text: 'Give the design a name first.' });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetchApi<{ message: string; warnings: string[] }>('/id-cards/designer', {
        method: 'POST',
        body: JSON.stringify({ name: name.trim(), holder_type: holderType, layout, assets, activate }),
      });
      setMessage({ ok: true, text: [res.message, ...res.warnings].join(' ') });
      onSaved();
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'The design could not be saved.') });
    } finally {
      setBusy(false);
    }
  }

  const sampleFor = (el: CardElement): string => {
    if (el.type === 'text') return el.upper ? (el.text ?? '').toUpperCase() : el.text ?? '';
    const f = fields.find((x) => x.key === el.field);
    let value = f?.sample[holderType] ?? el.field ?? '';
    if (el.field === 'holder.name' && longName) value = 'Oluwadamilare Chukwuemeka Adebayo-Okonkwo';
    if (el.upper) value = value.toUpperCase();
    const caption = el.show_caption && f ? (f.key === 'holder.id_value' ? (holderType === 'student' ? 'Admission No.' : 'Staff ID') : f.caption) : null;
    return caption ? `${caption} ${value}` : value;
  };

  const bgRef = assets[`${side}_background`];
  const usable = fields.filter((f) => f.for === null || f.for === holderType);

  return (
    <section className="space-y-4 rounded-lg border border-brand/40 bg-surface p-5">
      <div className="flex flex-wrap items-center gap-3">
        <h2 className="mr-auto font-semibold text-ink">Design your own card</h2>
        <button type="button" onClick={onClose} className="text-sm text-ink-faint hover:text-ink">Close</button>
      </div>

      <div className="flex flex-wrap items-end gap-3 text-sm">
        <label className="text-xs text-ink-muted">Design name
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Royal Crest 2026" className="mt-1 block rounded border border-line bg-canvas px-2 py-1.5 text-sm text-ink" />
        </label>
        <label className="text-xs text-ink-muted">For
          <select value={holderType} onChange={(e) => { setHolderType(e.target.value as HolderType); setPreview(null); }} className="mt-1 block rounded border border-line bg-canvas px-2 py-1.5 text-sm text-ink">
            <option value="student">Students</option>
            <option value="staff">Staff</option>
          </select>
        </label>
        <label className="text-xs text-ink-muted">Card
          <select value={layout.orientation} onChange={(e) => changeOrientation(e.target.value as Orientation)} className="mt-1 block rounded border border-line bg-canvas px-2 py-1.5 text-sm text-ink">
            <option value="landscape">Landscape (sideways)</option>
            <option value="portrait">Portrait (upright)</option>
          </select>
        </label>
        <div className="flex gap-1">
          {(['front', 'back'] as const).map((s) => (
            <button key={s} type="button" onClick={() => { setSide(s); setSelected(null); }}
              className={`rounded px-3 py-1.5 text-sm font-semibold capitalize ${side === s ? 'bg-brand-soft text-white' : 'bg-surface-raised text-ink'}`}>
              {s}
            </button>
          ))}
        </div>
        <button type="button" onClick={undo} disabled={history.length === 0} className="rounded bg-surface-raised px-3 py-1.5 text-sm text-ink disabled:opacity-40">Undo</button>
      </div>

      <div className="grid gap-5 xl:grid-cols-[auto_minmax(0,1fr)]">
        <div className="space-y-3">
          {/* The card, at 6 pixels per millimetre. */}
          <div
            className="relative overflow-hidden rounded-[18px] shadow ring-1 ring-line"
            style={{ width: cw * SCALE, height: ch * SCALE, background: layout[side].background_colour }}
            onPointerDown={() => setSelected(null)}
            onPointerMove={onDrag}
            onPointerUp={endDrag}
          >
            {bgRef && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={`/api/backend/id-cards/designer/backgrounds/${bgRef}`} alt="" className="pointer-events-none absolute inset-0 h-full w-full select-none" draggable={false} />
            )}
            {/* The 2mm safe margin: anything outside may be trimmed when cards are cut. */}
            <div className="pointer-events-none absolute border border-dashed border-sky-400/60" style={{ left: 2 * SCALE, top: 2 * SCALE, width: (cw - 4) * SCALE, height: (ch - 4) * SCALE }} />
            {elements.map((el, i) => (
              <div
                key={i}
                onPointerDown={(e) => startDrag(e, i, 'move')}
                className={`absolute cursor-move select-none overflow-hidden ${selected === i ? 'outline outline-2 outline-sky-500' : 'outline outline-1 outline-transparent hover:outline-sky-300'}`}
                style={{ left: el.x * SCALE, top: el.y * SCALE, width: el.w * SCALE, height: el.h * SCALE }}
              >
                <ElementFace el={el} text={sampleFor(el)} />
                {selected === i && (
                  <span onPointerDown={(e) => startDrag(e, i, 'resize')} className="absolute bottom-0 right-0 h-3 w-3 cursor-nwse-resize bg-sky-500" />
                )}
              </div>
            ))}
          </div>
          <p className="text-xs text-ink-muted">Drag to move, drag the blue corner to resize. Arrow keys nudge (Shift for 2mm). Delete removes. Dashed line: keep text inside it.</p>

          <div className="flex flex-wrap items-center gap-2 text-sm">
            <label className="cursor-pointer rounded bg-surface-raised px-3 py-1.5 font-semibold text-ink hover:bg-line">
              {bgRef ? 'Replace artwork' : `Upload ${side} artwork`}
              <input type="file" accept="image/png,image/jpeg" className="hidden" disabled={busy} onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadBackground(f); e.target.value = ''; }} />
            </label>
            {bgRef && <button type="button" className="text-xs text-ink-faint hover:text-negative" onClick={() => { setAssets((a) => ({ ...a, [`${side}_background`]: undefined })); setPreview(null); }}>Remove artwork</button>}
            <label className="text-xs text-ink-muted">Card colour
              <input type="color" value={layout[side].background_colour} onChange={(e) => commit({ ...layout, [side]: { ...layout[side], background_colour: e.target.value } })} className="ml-2 h-7 w-10 align-middle" />
            </label>
          </div>
          <p className="text-xs text-ink-muted">
            Artwork: export your card from Canva or your print shop as PNG or JPG, ideally {layout.orientation === 'portrait' ? '638 × 1011' : '1011 × 638'} pixels. Leave clear spaces for the photo, name and QR code.
          </p>
        </div>

        <div className="space-y-4">
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">Add to the {side}</p>
            <div className="flex flex-wrap gap-1.5">
              <AddButton label="Photo" onClick={() => add({ type: 'photo', x: 4, y: 12, w: 22, h: 28 })} />
              <AddButton label="QR code" onClick={() => add({ type: 'qr', x: cw - 18, y: ch - 18, w: 14, h: 14 })} />
              <AddButton label="Crest" onClick={() => add({ type: 'logo', x: 4, y: 3, w: 8, h: 8 })} />
              <AddButton label="Text" onClick={() => add({ type: 'text', text: 'Your text', x: 4, y: 4, w: 30, h: 5, font_size: 7, colour: '#12141a' })} />
              <AddButton label="Colour box" onClick={() => add({ type: 'box', x: 0, y: 0, w: cw, h: 6, colour: '#0b6b4f' })} />
              <select
                value=""
                onChange={(e) => { if (e.target.value) add({ type: 'field', field: e.target.value, x: 4, y: 4, w: 34, h: 5, font_size: e.target.value === 'holder.name' ? 9 : 6, bold: e.target.value === 'holder.name', colour: '#12141a' }); }}
                className="rounded border border-line bg-canvas px-2 py-1 text-xs text-ink"
              >
                <option value="">+ Field…</option>
                {usable.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
              </select>
            </div>
          </div>

          {current ? (
            <Properties el={current} fields={fields} onChange={updateSelected} onRemove={remove} />
          ) : (
            <p className="text-sm text-ink-muted">Select an item on the card to change it.</p>
          )}

          <div className="space-y-2 border-t border-line-soft pt-4">
            <div className="flex flex-wrap items-center gap-2">
              <button type="button" disabled={busy} onClick={runPreview} className="rounded bg-surface-raised px-3 py-1.5 text-sm font-semibold text-ink hover:bg-line disabled:opacity-50">Preview as printed</button>
              <label className="flex items-center gap-1.5 text-xs text-ink-muted">
                <input type="checkbox" checked={longName} onChange={(e) => { setLongName(e.target.checked); setPreview(null); }} /> Try a very long name
              </label>
            </div>
            {preview && (
              <>
                <iframe title="Printed preview" srcDoc={preview.html} sandbox="" className="w-full rounded border border-line-soft" style={{ height: layout.orientation === 'portrait' ? 420 : 300 }} />
                {preview.warnings.length > 0 && (
                  <ul className="list-disc pl-5 text-xs text-caution">{preview.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                )}
              </>
            )}
            {message && (
              <p role="status" className={`rounded px-3 py-2 text-sm ${message.ok ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative'}`}>{message.text}</p>
            )}
            <div className="flex flex-wrap gap-2">
              <button type="button" disabled={busy} onClick={() => save(true)} className="rounded bg-brand-soft px-4 py-2 text-sm font-semibold text-white hover:bg-brand disabled:opacity-50">
                Save and use for {holderType} cards
              </button>
              <button type="button" disabled={busy} onClick={() => save(false)} className="rounded bg-surface-raised px-4 py-2 text-sm font-semibold text-ink hover:bg-line disabled:opacity-50">
                Save as a draft
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

/** Keep an element on the card, at a usable size. */
function clampEl(el: CardElement, cw: number, ch: number): CardElement {
  const min = el.type === 'box' ? 0.3 : 2;
  let w = Math.min(cw, Math.max(min, el.w));
  let h = Math.min(ch, Math.max(min, el.h));
  if (el.type === 'qr') {
    const s = Math.max(12, Math.min(w, h)); // a QR under 12mm does not scan reliably
    w = s;
    h = s;
  }
  return { ...el, w, h, x: Math.min(cw - w, Math.max(0, el.x)), y: Math.min(ch - h, Math.max(0, el.y)) };
}

function AddButton({ label, onClick }: { label: string; onClick: () => void }) {
  return <button type="button" onClick={onClick} className="rounded border border-line px-2 py-1 text-xs text-ink hover:border-brand">+ {label}</button>;
}

/** How an element looks on the design canvas (the printed preview is the server's). */
function ElementFace({ el, text }: { el: CardElement; text: string }) {
  if (el.type === 'photo') {
    return <div className="flex h-full w-full items-center justify-center border border-dashed border-slate-400 bg-slate-50/80 text-[10px] text-slate-500">PHOTO</div>;
  }
  if (el.type === 'qr') {
    return (
      <div className="h-full w-full bg-white p-[6%]">
        <div className="h-full w-full" style={{ backgroundImage: 'repeating-conic-gradient(#111 0 25%, #fff 0 50%)', backgroundSize: '18% 18%' }} />
      </div>
    );
  }
  if (el.type === 'logo') {
    return <div className="flex h-full w-full items-center justify-center rounded-full border border-dashed border-slate-400 bg-white/70 text-[9px] text-slate-500">CREST</div>;
  }
  if (el.type === 'box') {
    return <div className="h-full w-full" style={{ background: el.colour }} />;
  }
  return (
    <div
      className="h-full w-full whitespace-pre-wrap break-words"
      style={{
        // 1pt = 0.3528mm, drawn at the canvas scale.
        fontSize: (el.font_size ?? 7) * 0.3528 * SCALE,
        lineHeight: 1.15,
        fontWeight: el.bold ? 700 : 400,
        color: el.colour ?? '#12141a',
        textAlign: el.align ?? 'left',
      }}
    >
      {text}
    </div>
  );
}

function Properties({ el, fields, onChange, onRemove }: { el: CardElement; fields: Field[]; onChange: (p: Partial<CardElement>) => void; onRemove: () => void }) {
  const field = fields.find((f) => f.key === el.field);
  const title = el.type === 'field' ? field?.label ?? 'Field' : { text: 'Text', photo: 'Photo', qr: 'QR code', logo: 'Crest', box: 'Colour box' }[el.type];
  const num = (k: keyof CardElement, label: string, step = 0.5) => (
    <label className="text-xs text-ink-muted">{label}
      <input type="number" step={step} value={el[k] as number} onChange={(e) => onChange({ [k]: Number(e.target.value) })} className="mt-0.5 block w-20 rounded border border-line bg-canvas px-2 py-1 text-sm text-ink" />
    </label>
  );
  const isText = el.type === 'field' || el.type === 'text';

  return (
    <div className="space-y-3 rounded border border-line-soft p-3">
      <div className="flex items-center">
        <p className="mr-auto text-sm font-semibold text-ink">{title}</p>
        <button type="button" onClick={onRemove} className="text-xs text-ink-faint hover:text-negative">Remove</button>
      </div>
      <div className="flex flex-wrap gap-2">
        {num('x', 'Left (mm)')}
        {num('y', 'Top (mm)')}
        {num('w', 'Width (mm)')}
        {num('h', 'Height (mm)')}
      </div>
      {el.type === 'qr' && <p className="text-xs text-ink-muted">Kept square and at least 12mm so a phone can scan it.</p>}
      {el.type === 'text' && (
        <label className="block text-xs text-ink-muted">Text
          <textarea value={el.text ?? ''} maxLength={160} rows={2} onChange={(e) => onChange({ text: e.target.value })} className="mt-0.5 block w-full rounded border border-line bg-canvas px-2 py-1 text-sm text-ink" />
        </label>
      )}
      {isText && (
        <div className="flex flex-wrap items-end gap-3">
          {num('font_size', 'Size (pt)', 0.5)}
          <label className="text-xs text-ink-muted">Colour
            <input type="color" value={el.colour ?? '#12141a'} onChange={(e) => onChange({ colour: e.target.value })} className="mt-0.5 block h-8 w-12" />
          </label>
          <label className="text-xs text-ink-muted">Align
            <select value={el.align ?? 'left'} onChange={(e) => onChange({ align: e.target.value as CardElement['align'] })} className="mt-0.5 block rounded border border-line bg-canvas px-2 py-1 text-sm text-ink">
              <option value="left">Left</option>
              <option value="center">Centre</option>
              <option value="right">Right</option>
            </select>
          </label>
          <label className="flex items-center gap-1 text-xs text-ink-muted"><input type="checkbox" checked={!!el.bold} onChange={(e) => onChange({ bold: e.target.checked })} /> Bold</label>
          <label className="flex items-center gap-1 text-xs text-ink-muted"><input type="checkbox" checked={!!el.upper} onChange={(e) => onChange({ upper: e.target.checked })} /> CAPITALS</label>
          {el.type === 'field' && (field?.caption || el.field === 'holder.id_value') && (
            <label className="flex items-center gap-1 text-xs text-ink-muted"><input type="checkbox" checked={!!el.show_caption} onChange={(e) => onChange({ show_caption: e.target.checked })} /> Show label</label>
          )}
        </div>
      )}
      {el.field === 'holder.name' && <p className="text-xs text-ink-muted">Long names are printed smaller automatically so they fit.</p>}
      {el.type === 'box' && (
        <label className="text-xs text-ink-muted">Colour
          <input type="color" value={el.colour ?? '#0b6b4f'} onChange={(e) => onChange({ colour: e.target.value })} className="ml-2 h-8 w-12 align-middle" />
        </label>
      )}
    </div>
  );
}
