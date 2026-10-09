'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Button, Card } from '@/components/Page';

/**
 * The partner's sales kit: brochures and social images to download, message
 * scripts with their own referral link already in, and useful links.
 * Uploaded by SchoolPilot in Super Admin > Partner materials.
 */

interface Material {
  id: number; title: string; description: string | null; category: string; kind: 'file' | 'text' | 'link';
  file_name: string | null; mime: string | null; size_bytes: number | null; url: string | null; body: string | null;
}

const GROUPS: [string, string][] = [
  ['brochure', 'Brochures'],
  ['script', 'Messages to send'],
  ['social', 'Social media images'],
  ['video', 'Videos'],
  ['other', 'More'],
];

function size(bytes: number | null) {
  if (!bytes) return '';
  return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export default function PartnerMaterials() {
  const [items, setItems] = useState<Material[]>([]);
  const [copied, setCopied] = useState<number | null>(null);

  useEffect(() => {
    fetchApi<{ data: Material[] }>('/partner/materials').then((r) => setItems(r.data)).catch(() => setItems([]));
  }, []);

  if (items.length === 0) return null;

  async function copy(m: Material) {
    try {
      await navigator.clipboard.writeText(m.body ?? '');
      setCopied(m.id);
      window.setTimeout(() => setCopied(null), 2000);
    } catch {
      window.prompt('Copy this message:', m.body ?? '');
    }
  }

  return (
    <Card title="Sales materials" description="Everything you need to introduce SchoolPilot. Messages already carry your own referral link.">
      <div className="space-y-6">
        {GROUPS.map(([key, label]) => {
          const group = items.filter((m) => m.category === key);
          if (group.length === 0) return null;
          return (
            <section key={key}>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-faint">{label}</h3>
              <ul className={key === 'social' ? 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3' : 'space-y-3'}>
                {group.map((m) => (
                  <li key={m.id} className="rounded-lg ring-1 ring-line-soft p-4">
                    {m.kind === 'file' && m.mime?.startsWith('image/') && (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={`/api/backend/partner/materials/${m.id}/download`} alt={m.title} className="mb-3 aspect-[4/5] w-full rounded-md object-cover" loading="lazy" />
                    )}
                    <p className="font-medium text-ink">{m.title}</p>
                    {m.description && <p className="mt-0.5 text-sm text-ink-muted">{m.description}</p>}
                    {m.kind === 'text' && (
                      <>
                        <pre className="mt-3 whitespace-pre-wrap rounded-md bg-surface-sunken p-3 font-sans text-sm text-ink">{m.body}</pre>
                        <div className="mt-2 flex flex-wrap gap-2">
                          <Button variant="secondary" onClick={() => void copy(m)}>{copied === m.id ? 'Copied' : 'Copy message'}</Button>
                          <a className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink hover:bg-surface-sunken" href={`https://wa.me/?text=${encodeURIComponent(m.body ?? '')}`} target="_blank" rel="noopener noreferrer">Send on WhatsApp</a>
                        </div>
                      </>
                    )}
                    {m.kind === 'file' && (
                      <a className="mt-3 inline-block text-sm font-semibold text-brand underline" href={`/api/backend/partner/materials/${m.id}/download`} download>
                        Download{m.file_name ? ` ${m.file_name.split('.').pop()?.toUpperCase()}` : ''} {size(m.size_bytes) && `· ${size(m.size_bytes)}`}
                      </a>
                    )}
                    {m.kind === 'link' && m.url && (
                      <a className="mt-3 inline-block text-sm font-semibold text-brand underline" href={m.url} target="_blank" rel="noopener noreferrer">Open link →</a>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          );
        })}
      </div>
    </Card>
  );
}
