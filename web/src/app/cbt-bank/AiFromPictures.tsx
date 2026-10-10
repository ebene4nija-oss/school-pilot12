'use client';

import React, { useState } from 'react';
import { fetchApi, uploadApi } from '@/lib/api';
import { Button, Card, Field, inputClass } from '@/components/Page';

/**
 * AI with pictures, for the question bank:
 *  - questions about an image already in the library (diagram, map, graph);
 *  - a photographed printed paper read into questions.
 *
 * Both produce drafts that the teacher reads, corrects and ticks here.
 * Nothing reaches the bank until "Save", and saving goes through the bank's
 * own checks. A scanned objective question with no key on the paper cannot
 * be saved until the teacher picks the answer.
 */

interface Option { id: number; name: string }
interface MediaItem { asset_id: number; alt_text: string; url: string; thumbnail_url?: string | null }
interface Draft {
  question: string;
  question_type: string;
  options: Record<string, string> | null;
  correct_answer: string | null;
  topic?: string | null;
  difficulty?: string | null;
  explanation?: string | null;
  media?: { asset_id: number; role: string }[];
  needs_figure?: boolean;
  keep: boolean;
}

export default function AiFromPictures({ subjects, classes, media, initialAssetId, onDone, onError }: {
  subjects: Option[];
  classes: Option[];
  media: MediaItem[];
  initialAssetId?: number | null;
  onDone: (m: string) => void;
  onError: (e: unknown, f: string) => void;
}) {
  const [mode, setMode] = useState<'image' | 'scan'>('image');
  const [assetId, setAssetId] = useState<string>(initialAssetId ? String(initialAssetId) : '');
  const [pages, setPages] = useState<File[]>([]);
  const [subjectId, setSubjectId] = useState('');
  const [classId, setClassId] = useState('');
  const [count, setCount] = useState('5');
  const [drafts, setDrafts] = useState<Draft[] | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const subjectName = subjects.find((s) => String(s.id) === subjectId)?.name ?? '';
  const className = classes.find((c) => String(c.id) === classId)?.name ?? '';

  async function generate() {
    if (!subjectId || !classId) { onError(new Error(''), 'Choose the class and subject first.'); return; }
    setBusy(true);
    setDrafts(null);
    setNote(null);
    try {
      type Res = { questions: Omit<Draft, 'keep'>[]; reason?: string | null; unreadable?: string | null; stub?: boolean };
      let r: Res;
      if (mode === 'image') {
        r = await fetchApi<Res>('/ai/cbt/questions-from-image', { method: 'POST', body: JSON.stringify({ asset_id: Number(assetId), subject: subjectName, class_level: className, count: Number(count) }) });
      } else {
        const fd = new FormData();
        pages.forEach((p) => fd.append('pages[]', p));
        fd.append('subject', subjectName);
        fd.append('class_level', className);
        r = await uploadApi<Res>('/ai/cbt/scan-paper', fd);
      }
      setDrafts(r.questions.map((q) => ({ ...q, keep: true })));
      setNote([r.reason, r.unreadable, r.stub ? 'AI is not set up for this school, so these are placeholders.' : null].filter(Boolean).join(' ') || null);
    } catch (err) {
      onError(err, 'The AI could not do that just now.');
    } finally {
      setBusy(false);
    }
  }

  function edit(i: number, patch: Partial<Draft>) {
    setDrafts((d) => d && d.map((q, j) => (j === i ? { ...q, ...patch } : q)));
  }

  const blocked = (q: Draft) => q.question_type !== 'theory' && (!q.correct_answer || !q.options || !(q.correct_answer in q.options));

  async function save() {
    if (!drafts) return;
    const chosen = drafts.filter((q) => q.keep);
    if (chosen.some(blocked)) { onError(new Error(''), 'Pick the correct answer for every ticked objective question first.'); return; }
    setBusy(true);
    let saved = 0;
    try {
      for (const q of chosen) {
        await fetchApi('/cbt/questions', {
          method: 'POST',
          body: JSON.stringify({
            subject_id: Number(subjectId), class_id: Number(classId),
            question: q.question, question_type: q.question_type,
            options: q.question_type === 'theory' ? null : q.options,
            correct_answer: q.question_type === 'theory' ? null : q.correct_answer,
            answer_schema: q.question_type === 'theory' ? [] : null,
            topic: q.topic ?? null, difficulty: q.difficulty ?? 'medium', explanation: q.explanation ?? null,
            marks: 1, media: q.media ?? null,
          }),
        });
        saved++;
      }
      onDone(`Saved ${saved} question(s) to the bank.`);
      setDrafts(null);
    } catch (err) {
      onError(err, `Saved ${saved}; the next one was refused.`);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <Card title="AI from pictures" description="Write questions from a diagram in your library, or photograph a printed paper and have it read into questions. You review everything before it is saved.">
        <div className="mb-4 flex gap-2">
          <Button variant={mode === 'image' ? 'primary' : 'secondary'} onClick={() => { setMode('image'); setDrafts(null); }}>Questions from an image</Button>
          <Button variant={mode === 'scan' ? 'primary' : 'secondary'} onClick={() => { setMode('scan'); setDrafts(null); }}>Scan a question paper</Button>
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Class"><select className={inputClass} value={classId} onChange={(e) => setClassId(e.target.value)}><option value="">Choose…</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
          <Field label="Subject"><select className={inputClass} value={subjectId} onChange={(e) => setSubjectId(e.target.value)}><option value="">Choose…</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          {mode === 'image' && <Field label="How many"><input type="number" min={1} max={10} className={inputClass} value={count} onChange={(e) => setCount(e.target.value)} /></Field>}
        </div>
        {mode === 'image' ? (
          <div className="mt-4">
            {media.length === 0 ? <p className="text-sm text-ink-muted">Upload a diagram on the Images tab first.</p> : (
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                {media.map((m) => (
                  <button key={m.asset_id} type="button" onClick={() => setAssetId(String(m.asset_id))} className={`rounded-lg p-1 ring-2 ${assetId === String(m.asset_id) ? 'ring-brand' : 'ring-transparent hover:ring-line'}`} title={m.alt_text}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={m.thumbnail_url ?? m.url} alt={m.alt_text} className="h-20 w-full rounded bg-white object-contain" />
                  </button>
                ))}
              </div>
            )}
          </div>
        ) : (
          <div className="mt-4 space-y-1 text-sm">
            <input type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={(e) => setPages(Array.from(e.target.files ?? []).slice(0, 6))} />
            <p className="text-ink-muted">Up to six pages, in order. Photograph flat, in good light, the whole page in view.</p>
          </div>
        )}
        <Button className="mt-4" disabled={busy || (mode === 'image' ? !assetId : pages.length === 0)} onClick={generate}>
          {busy && !drafts ? 'Working…' : mode === 'image' ? 'Write questions' : 'Read the paper'}
        </Button>
      </Card>

      {drafts && (
        <Card title={`Review ${drafts.length} draft(s)`} description="Correct anything that is wrong, untick what you do not want, then save.">
          {note && <p className="mb-3 text-sm text-caution">{note}</p>}
          {drafts.length === 0 && <p className="text-sm text-ink-muted">No questions came back.</p>}
          <ol className="space-y-4">
            {drafts.map((q, i) => (
              <li key={i} className={`rounded-lg p-4 ring-1 ${q.keep ? 'ring-line-soft' : 'opacity-50 ring-line-soft'}`}>
                <label className="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" checked={q.keep} onChange={(e) => edit(i, { keep: e.target.checked })} /> Keep · <span className="text-ink-muted">{q.question_type === 'theory' ? 'Theory' : 'Objective'}</span>{q.needs_figure && <span className="text-caution">· uses a figure from the paper: attach it after saving</span>}</label>
                <textarea className={`${inputClass} min-h-[3.5rem]`} value={q.question} onChange={(e) => edit(i, { question: e.target.value })} />
                {q.options && (
                  <div className="mt-2 space-y-1">
                    {Object.entries(q.options).map(([k, v]) => (
                      <label key={k} className="flex items-center gap-2 text-sm">
                        <input type="radio" name={`ans-${i}`} checked={q.correct_answer === k} onChange={() => edit(i, { correct_answer: k })} aria-label={`${k} is correct`} />
                        <span className="w-5 font-semibold">{k}</span>
                        <input className={inputClass} value={v} onChange={(e) => edit(i, { options: { ...q.options!, [k]: e.target.value } })} />
                      </label>
                    ))}
                    {blocked(q) && q.keep && <p className="text-xs text-negative">Pick the correct answer.</p>}
                  </div>
                )}
              </li>
            ))}
          </ol>
          {drafts.length > 0 && <Button className="mt-4" disabled={busy || !drafts.some((q) => q.keep)} onClick={save}>{busy ? 'Saving…' : `Save ${drafts.filter((q) => q.keep).length} to the bank`}</Button>}
        </Card>
      )}
    </div>
  );
}
