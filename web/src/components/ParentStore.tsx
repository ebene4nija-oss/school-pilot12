'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Field, inputClass, nairaAmount } from '@/components/Page';

/**
 * Items the school handed to a parent's children (uniforms, books, and so
 * on), for the parent to confirm received. Waiting hand-overs come first.
 * Renders nothing when the school doesn't use the store or there's nothing
 * to show.
 */

interface Line { id: number; item_name: string; variant: string | null; quantity: number; unit_price: string | number; charged: boolean; status: string }
interface Issue { id: number; student_name: string | null; status: string; issued_at: string; lines: Line[]; charged_total: number; parent_note: string | null }

const STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'slate' }> = {
  awaiting: { label: 'Please confirm', tone: 'amber' },
  confirmed: { label: 'Received', tone: 'green' },
  disputed: { label: 'Reported missing', tone: 'red' },
  resolved: { label: 'Sorted out', tone: 'slate' },
};
const when = (iso: string) => new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' });

export default function ParentStore() {
  const [issues, setIssues] = useState<Issue[]>([]);
  const [answers, setAnswers] = useState<Record<number, boolean>>({});
  const [note, setNote] = useState<Record<number, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    const res = await fetchApi<{ enabled: boolean; data: Issue[] }>('/parent/inventory');
    setIssues(res.enabled ? res.data : []);
  }

  useEffect(() => {
    (async () => {
      try {
        await load();
      } catch {
        // Store off or not available: no card, not a broken home page.
      }
    })();
  }, []);

  if (issues.length === 0) return null;

  const pending = (i: Issue) => i.lines.filter((l) => l.status === 'pending');

  async function confirm(issue: Issue) {
    setBusy(true);
    try {
      const lines = pending(issue).map((l) => ({ id: l.id, received: answers[l.id] ?? true }));
      const r = await fetchApi<{ message: string }>(`/parent/inventory/issues/${issue.id}/confirm`, {
        method: 'POST',
        body: JSON.stringify({ lines, note: note[issue.id] || null }),
      });
      setMessage(r.message);
      await load();
    } catch (err) {
      setMessage(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  const waiting = issues.filter((i) => i.status === 'awaiting');
  const recent = issues.filter((i) => i.status !== 'awaiting').slice(0, 5);

  return (
    <Card
      className="mt-6"
      title="Items from the school"
      description={waiting.length ? 'Uniforms, books and other items handed to your child. Tick what arrived.' : 'Uniforms, books and other items handed to your child.'}
    >
      {message && <p role="status" className="mb-4 rounded-lg bg-surface-sunken px-4 py-3 text-sm">{message}</p>}
      <div className="space-y-5">
        {waiting.map((issue) => {
          const missing = pending(issue).some((l) => answers[l.id] === false);
          return (
            <div key={issue.id} className="rounded-lg ring-1 ring-accent/60 p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">{issue.student_name ?? 'Your child'} · {when(issue.issued_at)}</p>
                <Badge tone="amber">Please confirm</Badge>
              </div>
              <ul className="mt-3 space-y-2">
                {issue.lines.map((l) => (
                  <li key={l.id}>
                    {l.status === 'pending' ? (
                      <label className="flex items-start gap-3 text-sm">
                        <input type="checkbox" className="mt-1 h-4 w-4" checked={answers[l.id] ?? true} onChange={(e) => setAnswers({ ...answers, [l.id]: e.target.checked })} />
                        <span>
                          {l.quantity} × {l.item_name}{l.variant ? ` (${l.variant})` : ''}
                          <span className="block text-xs text-ink-muted">{l.charged ? `${nairaAmount(Number(l.unit_price) * l.quantity)} on this term’s fees` : 'No charge'}</span>
                        </span>
                      </label>
                    ) : (
                      <p className="pl-7 text-sm text-ink-muted">{l.quantity} × {l.item_name} · {l.status}</p>
                    )}
                  </li>
                ))}
              </ul>
              {missing && (
                <div className="mt-3">
                  <Field label="What is missing?">
                    <input className={inputClass} maxLength={500} value={note[issue.id] ?? ''} onChange={(e) => setNote({ ...note, [issue.id]: e.target.value })} placeholder="e.g. The atlas was not in her bag" />
                  </Field>
                </div>
              )}
              <Button className="mt-4" variant={missing ? 'secondary' : 'accent'} disabled={busy || (missing && !(note[issue.id] ?? '').trim())} onClick={() => void confirm(issue)}>
                {missing ? 'Tell the school what is missing' : 'Yes, we received everything'}
              </Button>
            </div>
          );
        })}

        {recent.length > 0 && (
          <ul className="divide-y divide-line-soft text-sm">
            {recent.map((issue) => (
              <li key={issue.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  {issue.student_name ?? 'Your child'} · {when(issue.issued_at)}
                  <span className="block text-xs text-ink-muted">{issue.lines.map((l) => `${l.quantity} × ${l.item_name}`).join(', ')}</span>
                </span>
                <Badge tone={STATUS[issue.status]?.tone ?? 'slate'}>{STATUS[issue.status]?.label ?? issue.status}</Badge>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  );
}
