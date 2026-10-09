'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi, uploadApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Loading } from '@/components/Page';

/**
 * The marketing site's product screenshots, one per slot.
 *
 * Each slot is a screen the site shows (hero, role tabs, feature pages). The
 * site takes whatever is here when it is next rebuilt; an empty slot shows a
 * "screenshot to capture" frame, never a mock-up. Capture from the Greenfield
 * Academy demo school only: no real school's children or records.
 */

interface Slot {
  slot: string;
  label: string;
  frame: 'desktop' | 'phone' | 'social';
  url: string | null;
}

export default function WebsiteScreens() {
  const [slots, setSlots] = useState<Slot[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    const res = await fetchApi<{ data: Slot[] }>('/platform/website/screens');
    setSlots(res.data);
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load the screenshots.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function upload(slot: Slot, file: File) {
    setBusy(slot.slot);
    setError(null);
    setNotice(null);
    try {
      const form = new FormData();
      form.append('image', file);
      await uploadApi(`/platform/website/screens/${slot.slot}`, form);
      setNotice(`${slot.label} saved. It shows on the site after the next rebuild.`);
      await load();
    } catch (err) {
      const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
      setError(payload?.errors?.image?.[0] ?? (err instanceof Error ? err.message : 'That image could not be saved.'));
    } finally {
      setBusy(null);
    }
  }

  async function remove(slot: Slot) {
    if (!window.confirm(`Remove the ${slot.label.toLowerCase()} screenshot?`)) return;
    setBusy(slot.slot);
    setError(null);
    setNotice(null);
    try {
      await fetchApi(`/platform/website/screens/${slot.slot}`, { method: 'DELETE' });
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That screenshot could not be removed.');
    } finally {
      setBusy(null);
    }
  }

  const filled = slots.filter((s) => s.url).length;

  return (
    <Card title="Product screenshots">
      <p className="mb-4 text-sm text-ink-muted">
        Capture these from the Greenfield Academy demo school only — never a real school&apos;s children or records. PNG, JPG or WebP;
        desktop at least 1000 × 600, phone portrait at least 360 × 640. The share preview (shown when a page is shared on WhatsApp, Facebook, X or
        LinkedIn) must be at least 1200 × 630, with the logo and text kept in the middle. {filled} of {slots.length} filled.
      </p>
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}
      {loading ? (
        <Loading />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {slots.map((slot) => (
            <li key={slot.slot} className="flex flex-col rounded-xl border border-line-soft p-3">
              <div
                className={`flex items-center justify-center overflow-hidden rounded-lg bg-surface-sunken ${
                  slot.frame === 'phone' ? 'aspect-[390/844] max-h-56' : slot.frame === 'social' ? 'aspect-[1200/630]' : 'aspect-[16/10]'
                }`}
              >
                {slot.url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={slot.url} alt={slot.label} className="h-full w-full object-cover object-top" />
                ) : (
                  <span className="px-3 text-center text-xs text-ink-muted">No screenshot yet</span>
                )}
              </div>
              <div className="mt-3 flex items-start justify-between gap-2">
                <p className="text-sm font-medium text-ink">{slot.label}</p>
                <Badge tone={slot.url ? 'green' : 'slate'}>{slot.frame === 'phone' ? 'Phone' : slot.frame === 'social' ? 'Share preview' : 'Desktop'}</Badge>
              </div>
              <div className="mt-3 flex flex-wrap gap-2">
                <label className={`cursor-pointer ${busy === slot.slot ? 'pointer-events-none opacity-60' : ''}`}>
                  <span className="inline-flex rounded-lg border border-brand px-3 py-1.5 text-sm font-semibold text-brand hover:bg-surface-sunken">
                    {busy === slot.slot ? 'Uploading…' : slot.url ? 'Replace' : 'Upload'}
                  </span>
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="sr-only"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      e.target.value = '';
                      if (file) void upload(slot, file);
                    }}
                  />
                </label>
                {slot.url && (
                  <Button variant="secondary" disabled={busy === slot.slot} onClick={() => remove(slot)}>
                    Remove
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
