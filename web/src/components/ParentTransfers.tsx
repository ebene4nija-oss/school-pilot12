'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card } from '@/components/Page';

/**
 * A parent's transfers: status, and consent where the school is waiting for
 * it. The transfer itself and each sensitive category are separate choices;
 * health, special-needs and discipline notes stay behind unless ticked.
 */

interface Row {
  id: number; reference: string; status: string; status_label: string; destination: string | null; needs_consent: boolean;
  consents: { category: string; given: boolean }[];
}

export default function ParentTransfers() {
  const [rows, setRows] = useState<Row[]>([]);
  const [categories, setCategories] = useState<Record<string, string>>({});
  const [extra, setExtra] = useState<Record<string, boolean>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    const res = await fetchApi<{ data: Row[]; categories: Record<string, string> }>('/parent/transfers');
    setRows(res.data);
    setCategories(res.categories);
  }

  useEffect(() => {
    (async () => {
      try {
        await load();
      } catch {
        // No transfers card is better than a broken home page.
      }
    })();
  }, []);

  async function decide(row: Row, given: boolean) {
    setBusy(true);
    try {
      const res = await fetchApi<{ message: string }>(`/parent/transfers/${row.id}/consent`, {
        method: 'POST',
        body: JSON.stringify({ transfer: given, ...(given ? extra : {}) }),
      });
      setMessage(res.message);
      await load();
    } catch (err) {
      setMessage(errorText(err, 'Your answer could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  if (rows.length === 0) return null;

  return (
    <Card className="mt-6" title="School transfers">
      {message && <p className="mb-3 text-sm">{message}</p>}
      <ul className="space-y-4">
        {rows.map((row) => (
          <li key={row.id} className="rounded-lg p-3 ring-1 ring-line-soft">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-semibold">{row.reference}</span>
              <Badge tone={row.needs_consent ? 'amber' : 'slate'}>{row.status_label}</Badge>
              {row.destination && <span className="text-sm text-ink-muted">to {row.destination}</span>}
            </div>
            {row.needs_consent && (
              <div className="mt-3 text-sm">
                <p className="mb-2">Do you consent to this transfer and to the school sharing your child&apos;s academic records with the new school?</p>
                <p className="mb-1 text-ink-muted">Also share (optional — nothing below is shared unless ticked):</p>
                {Object.entries(categories).filter(([k]) => k !== 'transfer').map(([k, label]) => (
                  <label key={k} className="mr-4 inline-flex items-center gap-1">
                    <input type="checkbox" checked={!!extra[k]} onChange={(e) => setExtra({ ...extra, [k]: e.target.checked })} /> {label}
                  </label>
                ))}
                <div className="mt-3 flex gap-2">
                  <Button variant="accent" disabled={busy} onClick={() => decide(row, true)}>I consent</Button>
                  <Button variant="danger" disabled={busy} onClick={() => window.confirm('Refusing stops the transfer. Continue?') && decide(row, false)}>I do not consent</Button>
                </div>
              </div>
            )}
          </li>
        ))}
      </ul>
    </Card>
  );
}
