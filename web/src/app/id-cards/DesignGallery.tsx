'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';

/**
 * Ready-made ID card designs (TASK 18): three for students, three for staff.
 * The school picks one, sets its own colours, checks it with its own crest and
 * a very long name, and makes it live — or keeps it as a draft. Printing,
 * scanning and every card already issued are unaffected until it goes live.
 */

interface Design {
  key: string;
  name: string;
  holder_type: 'student' | 'staff';
  orientation: 'landscape' | 'portrait';
  description: string;
  primary: string;
  accent: string;
  school_primary: string | null;
  school_accent: string | null;
  live: boolean;
}

export default function DesignGallery({ onChanged }: { onChanged: () => void }) {
  const [designs, setDesigns] = useState<Design[]>([]);
  const [open, setOpen] = useState<Design | null>(null);
  const [error, setError] = useState<string | null>(null);

  const fetchDesigns = useCallback(async () => (await fetchApi<{ designs: Design[] }>('/id-cards/gallery')).designs, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const d = await fetchDesigns();
        if (!cancelled) setDesigns(d);
      } catch (err) {
        if (!cancelled) setError(errorText(err, 'Could not load the designs.'));
      }
    })();
    return () => { cancelled = true; };
  }, [fetchDesigns]);

  const refresh = async () => {
    setDesigns(await fetchDesigns());
    onChanged();
  };

  return (
    <section className="space-y-5 rounded-lg border border-line-soft bg-surface p-5">
      <div>
        <h2 className="font-semibold text-ink">Choose a design</h2>
        <p className="mt-1 text-sm text-ink-muted">
          Pick a design for students and one for staff, in your school&apos;s colours. Cards already printed are not changed.
        </p>
      </div>
      {error && <p className="text-sm text-negative">{error}</p>}

      {(['student', 'staff'] as const).map((type) => (
        <div key={type}>
          <h3 className="mb-2 text-sm font-semibold capitalize text-ink">{type} cards</h3>
          <div className="grid gap-3 sm:grid-cols-3">
            {designs.filter((d) => d.holder_type === type).map((d) => (
              <button
                key={d.key}
                type="button"
                onClick={() => setOpen(d)}
                className={`rounded-lg border p-3 text-left hover:border-brand ${open?.key === d.key ? 'border-brand ring-1 ring-brand' : 'border-line-soft'}`}
              >
                <div className="flex items-center gap-2">
                  <span className="h-4 w-4 rounded-full" style={{ background: d.school_primary ?? d.primary }} />
                  <span className="h-4 w-4 rounded-full" style={{ background: d.school_accent ?? d.accent }} />
                  <span className="text-xs text-ink-muted">{d.orientation}</span>
                  {d.live && <span className="ml-auto rounded bg-brand/15 px-1.5 py-0.5 text-xs font-semibold text-positive">live</span>}
                </div>
                <p className="mt-2 font-medium text-ink">{d.name}</p>
                <p className="mt-1 text-xs text-ink-muted">{d.description}</p>
              </button>
            ))}
          </div>
        </div>
      ))}

      {open && <DesignEditor key={open.key} design={open} onDone={refresh} />}
    </section>
  );
}

function DesignEditor({ design, onDone }: { design: Design; onDone: () => Promise<void> }) {
  const [primary, setPrimary] = useState(design.school_primary ?? design.primary);
  const [accent, setAccent] = useState(design.school_accent ?? design.accent);
  const [longName, setLongName] = useState(false);
  const [html, setHtml] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  // Re-render shortly after the colours settle, not on every pixel of a drag.
  useEffect(() => {
    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const q = new URLSearchParams({ primary, accent, format: 'json', ...(longName ? { long_name: '1' } : {}) });
        const res = await fetchApi<{ html: string }>(`/id-cards/gallery/${design.key}/preview?${q}`);
        if (!cancelled) setHtml(res.html);
      } catch (err) {
        if (!cancelled) setMessage({ ok: false, text: errorText(err, 'Could not draw the preview.') });
      }
    }, 300);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [design.key, primary, accent, longName]);

  async function use(activate: boolean) {
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetchApi<{ message: string }>(`/id-cards/gallery/${design.key}`, {
        method: 'POST',
        body: JSON.stringify({ primary, accent, activate }),
      });
      setMessage({ ok: true, text: res.message });
      await onDone();
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'That design could not be saved.') });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4 rounded-lg border border-line-soft p-4">
      <div className="flex flex-wrap items-end gap-4">
        <h3 className="mr-auto font-semibold text-ink">{design.name}</h3>
        <label className="text-xs text-ink-muted">
          Main colour
          <input type="color" value={primary} onChange={(e) => setPrimary(e.target.value)} className="ml-2 h-8 w-12 cursor-pointer align-middle" />
        </label>
        <label className="text-xs text-ink-muted">
          Accent colour
          <input type="color" value={accent} onChange={(e) => setAccent(e.target.value)} className="ml-2 h-8 w-12 cursor-pointer align-middle" />
        </label>
        <label className="flex items-center gap-2 text-xs text-ink-muted">
          <input type="checkbox" checked={longName} onChange={(e) => setLongName(e.target.checked)} />
          Try a very long name
        </label>
        <button type="button" className="text-xs text-ink-faint underline" onClick={() => { setPrimary(design.primary); setAccent(design.accent); }}>
          Reset colours
        </button>
      </div>

      {html ? (
        // The server's own sanitised card markup, shown in a sandbox with no scripts.
        <iframe
          title={`${design.name} preview`}
          srcDoc={html}
          sandbox=""
          className="w-full rounded border border-line-soft bg-surface-sunken"
          style={{ height: design.orientation === 'portrait' ? 400 : 280 }}
        />
      ) : (
        <p className="text-sm text-ink-muted">Drawing the preview…</p>
      )}
      <p className="text-xs text-ink-muted">
        The preview uses your school&apos;s name and crest with a made-up {design.holder_type}. The QR code is a sample. Real cards carry each holder&apos;s own photo and code.
      </p>

      {message && (
        <p role="status" className={`rounded px-3 py-2 text-sm ${message.ok ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative'}`}>
          {message.text}
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          disabled={busy}
          onClick={() => use(true)}
          className="rounded bg-brand-soft px-4 py-2 text-sm font-semibold text-white hover:bg-brand disabled:opacity-50"
        >
          Use this design for {design.holder_type} cards
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={() => use(false)}
          className="rounded bg-surface-raised px-4 py-2 text-sm font-semibold text-ink hover:bg-line disabled:opacity-50"
        >
          Save as a draft
        </button>
      </div>
    </div>
  );
}
