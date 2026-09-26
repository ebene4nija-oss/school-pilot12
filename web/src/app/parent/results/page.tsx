'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, Table, Cell, inputClass, nairaAmount } from '@/components/Page';
import ChildSwitcher, { useChildren } from '@/components/ChildSwitcher';

/**
 * A parent's view of their child's results.
 *
 * A result shows only once the school releases it. Where the school charges
 * for results, the full report card needs a PIN: bought online here, or bought
 * at the school office and typed in. A PIN allows a few openings of that one
 * child's card for that one term, so the card opens only when the parent asks.
 */

type Term = { id: number; name: string; session_name: string | null };

type Summary =
  | { status: 'not_released' | 'no_scores'; message: string }
  | {
      status: 'released';
      term_name: string;
      unlocked: boolean;
      preview: { subjects_count: number; average: number; position: number | null; class_size: number };
      access: { serial: string; views_remaining: number } | null;
      unlock_options: {
        online_purchase: { available: boolean; amount?: number; reason?: string };
        redeem_pin: { available: boolean; description: string };
      } | null;
    };

type Pin = { serial: string; status: string; student: string | null; term: string | null; views_remaining: number; sold_at: string | null; amount: string | null };

export default function ParentResultsPage() {
  const { children, selected, select, error: childError } = useChildren();
  const [terms, setTerms] = useState<Term[]>([]);
  const [termId, setTermId] = useState<number | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [pins, setPins] = useState<Pin[]>([]);
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: Term[]; current_term_id: number | null }>('/terms')
      .then((res) => {
        setTerms(res.data ?? []);
        setTermId(res.current_term_id ?? res.data?.[0]?.id ?? null);
      })
      .catch(() => setTerms([]));
    fetchApi<{ pins: Pin[] }>('/result-checker/my-pins').then((r) => setPins(r.pins ?? [])).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    if (!selected || !termId) return;
    setSummary(null);
    setError(null);
    try {
      setSummary(await fetchApi<Summary>(`/result-checker/${selected.id}/${termId}/summary`));
    } catch (err) {
      setError(errorText(err, 'Could not load this result.'));
    }
  }, [selected, termId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const redeem = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selected || !termId) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message: string }>('/result-checker/redeem', {
        method: 'POST',
        body: JSON.stringify({ pin: pin.trim(), student_id: selected.id, term_id: termId }),
      });
      setNotice(res.message);
      setPin('');
      await load();
    } catch (err) {
      setError(errorText(err, 'That PIN was not accepted.'));
    } finally {
      setBusy(false);
    }
  };

  const buy = async () => {
    if (!selected || !termId) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ authorization_url: string }>('/result-checker/purchase', {
        method: 'POST',
        body: JSON.stringify({ student_id: selected.id, term_id: termId }),
      });
      window.location.href = res.authorization_url;
    } catch (err) {
      setError(errorText(err, 'Could not start the payment.'));
      setBusy(false);
    }
  };

  const open = (format: 'html' | 'pdf') => {
    if (!selected || !termId || summary?.status !== 'released') return;
    const left = summary.access?.views_remaining;
    if (left !== undefined && !window.confirm(`Opening the report card uses one of your ${left} remaining views for this PIN. Continue?`)) return;
    window.open(`/api/backend/result-checker/${selected.id}/${termId}${format === 'pdf' ? '?format=pdf' : ''}`, '_blank', 'noopener');
    // The view count changed; refresh it shortly after.
    setTimeout(() => void load(), 2500);
  };

  return (
    <PageShell title="Results" subtitle="Your child's report card, once the school releases it.">
      <ErrorNote message={childError ?? error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {!children ? (
        <Loading />
      ) : children.length === 0 ? (
        <Empty>No child is linked to your account yet. Ask the school office to link your child.</Empty>
      ) : (
        <>
          <ChildSwitcher items={children} selected={selected} onSelect={select} />

          <div className="mb-6 max-w-xs">
            <Field label="Term">
              <select value={termId ?? ''} onChange={(e) => setTermId(Number(e.target.value))} className={inputClass}>
                {terms.map((t) => (
                  <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>
                ))}
              </select>
            </Field>
          </div>

          {!summary ? (
            !error && <Loading />
          ) : summary.status !== 'released' ? (
            <Card><p className="text-sm text-ink-muted">{summary.message}</p></Card>
          ) : (
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title={`${selected?.name ?? 'Result'} — ${summary.term_name}`}>
                <div className="flex flex-wrap gap-6">
                  <div>
                    <p className="text-xs uppercase tracking-wide text-ink-faint">Average</p>
                    <p className="font-headline text-2xl font-bold text-brand">{Number(summary.preview.average).toFixed(1)}%</p>
                  </div>
                  {summary.preview.position && (
                    <div>
                      <p className="text-xs uppercase tracking-wide text-ink-faint">Position</p>
                      <p className="font-headline text-2xl font-bold text-brand">
                        {summary.preview.position} <span className="text-sm font-normal text-ink-muted">of {summary.preview.class_size}</span>
                      </p>
                    </div>
                  )}
                  <div>
                    <p className="text-xs uppercase tracking-wide text-ink-faint">Subjects</p>
                    <p className="font-headline text-2xl font-bold text-brand">{summary.preview.subjects_count}</p>
                  </div>
                </div>

                {summary.unlocked ? (
                  <div className="mt-5">
                    {summary.access && (
                      <p className="mb-3 text-xs text-ink-muted">
                        Unlocked with PIN {summary.access.serial} · {summary.access.views_remaining} view(s) left
                      </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                      <Button onClick={() => open('html')}>Open report card</Button>
                      <Button variant="secondary" onClick={() => open('pdf')}>Download PDF</Button>
                    </div>
                  </div>
                ) : (
                  <p className="mt-5 text-sm text-ink-muted">The full report card needs a result PIN. Choose how to get one.</p>
                )}
              </Card>

              {!summary.unlocked && summary.unlock_options && (
                <Card title="Unlock the report card">
                  {summary.unlock_options.online_purchase.available ? (
                    <div className="mb-5">
                      <p className="mb-2 text-sm text-ink">Pay online and it unlocks as soon as the payment is confirmed.</p>
                      <Button variant="accent" disabled={busy} onClick={buy}>
                        Pay {nairaAmount(Number(summary.unlock_options.online_purchase.amount ?? 0))} online
                      </Button>
                    </div>
                  ) : (
                    <p className="mb-5 text-sm text-ink-muted">{summary.unlock_options.online_purchase.reason}</p>
                  )}
                  <form onSubmit={redeem} className="border-t border-line-soft pt-4">
                    <Field label="Have a PIN from the school office?">
                      <input value={pin} onChange={(e) => setPin(e.target.value)} className={inputClass} placeholder="Type the PIN" autoComplete="off" required />
                    </Field>
                    <Button className="mt-2" type="submit" variant="secondary" disabled={busy || !pin.trim()}>Use this PIN</Button>
                    <p className="mt-2 text-xs text-ink-muted">A PIN works for one child and one term only. Check the child and term above first.</p>
                  </form>
                </Card>
              )}
            </div>
          )}

          {pins.length > 0 && (
            <Card title="PINs you have bought" className="mt-6">
              <Table columns={['Serial', 'Child', 'Term', 'Views left', 'Status']}>
                {pins.map((p) => (
                  <tr key={p.serial}>
                    <Cell className="font-mono text-xs">{p.serial}</Cell>
                    <Cell>{p.student ?? 'Not used yet'}</Cell>
                    <Cell>{p.term ?? '—'}</Cell>
                    <Cell>{p.views_remaining}</Cell>
                    <Cell><Badge tone={p.status === 'used' ? 'green' : 'blue'}>{p.status}</Badge></Cell>
                  </tr>
                ))}
              </Table>
            </Card>
          )}
        </>
      )}
    </PageShell>
  );
}
