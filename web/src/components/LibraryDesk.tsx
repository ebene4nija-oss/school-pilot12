'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, Field, Table, inputClass } from '@/components/Page';

/**
 * The library desk (doc §9.2): lend a book to a reader, take books back,
 * and see what is out, what is late and who is waiting. Books are found by
 * barcode — typed, or read by a phone camera's scanner keyboard — never a
 * scanner gun (doc §3).
 */

interface Loan {
  id: number; title: string; barcode: string; borrower: string; admission_number: string | null; class: string | null;
  borrowed_at: string; due_date: string; returned_at: string | null; renewals: number; return_condition: string | null; days_overdue: number;
}
interface Borrower { user_id: number; name: string; number: string | null; class: string | null; kind: 'student' | 'staff' }
interface Reservation { id: number; title: string; name: string; status: 'waiting' | 'ready'; ready_until: string | null }

export default function LibraryDesk({ onChange }: { onChange: () => void }) {
  const [filter, setFilter] = useState<'out' | 'overdue' | 'returned'>('out');
  const [loans, setLoans] = useState<Loan[]>([]);
  const [holds, setHolds] = useState<Reservation[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [who, setWho] = useState('');
  const [matches, setMatches] = useState<Borrower[]>([]);
  const [reader, setReader] = useState<Borrower | null>(null);
  const [lendCode, setLendCode] = useState('');
  const [returnCode, setReturnCode] = useState('');
  const [condition, setCondition] = useState('good');

  const fetchAll = useCallback(async (f: string) => {
    const [l, r] = await Promise.all([
      fetchApi<{ data: Loan[] }>(`/library/loans?status=${f}`),
      fetchApi<{ data: Reservation[] }>('/library/reservations'),
    ]);
    return { loans: l.data, holds: r.data };
  }, []);

  const refresh = useCallback(async () => {
    const d = await fetchAll(filter);
    setLoans(d.loans);
    setHolds(d.holds);
    onChange();
  }, [fetchAll, filter, onChange]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const d = await fetchAll(filter);
        if (!cancelled) { setLoans(d.loans); setHolds(d.holds); }
      } catch (err) {
        if (!cancelled) setError(errorText(err, "That did not work."));
      }
    })();
    return () => { cancelled = true; };
  }, [fetchAll, filter]);

  async function run(fn: () => Promise<string>) {
    setError(null);
    setNotice(null);
    try {
      setNotice(await fn());
      await refresh();
    } catch (err) {
      setError(errorText(err, "That did not work."));
    }
  }

  async function findReader(e: React.FormEvent) {
    e.preventDefault();
    try {
      setMatches((await fetchApi<{ data: Borrower[] }>(`/library/borrowers?q=${encodeURIComponent(who.trim())}`)).data);
    } catch (err) {
      setError(errorText(err, "That did not work."));
    }
  }

  return (
    <div className="space-y-6">
      {error && <p role="alert" className="rounded-lg bg-negative-soft px-4 py-3 text-sm text-negative">{error}</p>}
      {notice && <p role="status" className="rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Lend a book" description="Find the reader, then scan or type the book's barcode.">
          {!reader ? (
            <>
              <form onSubmit={findReader} className="flex flex-wrap gap-2">
                <input value={who} onChange={(e) => setWho(e.target.value)} placeholder="Name, admission or staff number" aria-label="Find a reader" className={`${inputClass} max-w-xs`} />
                <Button type="submit" variant="secondary" disabled={who.trim().length < 2}>Find</Button>
              </form>
              <ul className="mt-3 space-y-1 text-sm">
                {matches.map((m) => (
                  <li key={`${m.kind}${m.user_id}`}>
                    <button type="button" className="text-brand hover:underline" onClick={() => { setReader(m); setMatches([]); }}>
                      {m.name}
                    </button>
                    <span className="text-ink-muted"> · {m.number ?? '—'}{m.class ? ` · ${m.class}` : ''} · {m.kind}</span>
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                run(async () => {
                  const res = await fetchApi<{ loan: Loan }>('/library/loans', { method: 'POST', body: JSON.stringify({ barcode: lendCode.trim(), borrower_user_id: reader.user_id }) });
                  setLendCode('');
                  return `"${res.loan.title}" lent to ${reader.name}, due ${res.loan.due_date}.`;
                });
              }}
              className="space-y-3"
            >
              <p className="text-sm">Lending to <span className="font-semibold">{reader.name}</span> <button type="button" className="ml-2 text-xs text-brand underline" onClick={() => setReader(null)}>change</button></p>
              <Field label="Book barcode">
                <input required autoFocus value={lendCode} onChange={(e) => setLendCode(e.target.value)} className={inputClass} />
              </Field>
              <Button type="submit" disabled={!lendCode.trim()}>Lend</Button>
            </form>
          )}
        </Card>

        <Card title="Take a book back">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              run(async () => {
                const res = await fetchApi<{ loan: Loan }>('/library/returns', { method: 'POST', body: JSON.stringify({ barcode: returnCode.trim(), condition }) });
                setReturnCode('');
                return `"${res.loan.title}" returned by ${res.loan.borrower}${res.loan.days_overdue ? `, ${res.loan.days_overdue} day(s) late` : ''}.`;
              });
            }}
            className="space-y-3"
          >
            <Field label="Book barcode">
              <input required value={returnCode} onChange={(e) => setReturnCode(e.target.value)} className={inputClass} />
            </Field>
            <Field label="Condition">
              <select value={condition} onChange={(e) => setCondition(e.target.value)} className={inputClass}>
                <option value="good">Good</option>
                <option value="damaged">Damaged</option>
                <option value="lost">Reported lost (removes the copy from stock)</option>
              </select>
            </Field>
            <Button type="submit" disabled={!returnCode.trim()}>Return</Button>
          </form>
          <p className="mt-3 text-xs text-ink-muted">If more than one copy of the title is out, return it from the loans list below instead.</p>
        </Card>
      </div>

      <Card title="Loans">
        <div className="mb-4 flex gap-2">
          {(['out', 'overdue', 'returned'] as const).map((f) => (
            <Button key={f} variant={filter === f ? 'primary' : 'secondary'} onClick={() => setFilter(f)}>
              {f === 'out' ? 'Out now' : f === 'overdue' ? 'Overdue' : 'Returned'}
            </Button>
          ))}
        </div>
        {loans.length === 0 ? <Empty>Nothing here.</Empty> : (
          <Table columns={['Book', 'Reader', 'Borrowed', 'Due', 'Status', '']}>
            {loans.map((l) => (
              <tr key={l.id}>
                <Cell className="font-medium">{l.title}<span className="block font-mono text-xs text-ink-muted">{l.barcode}</span></Cell>
                <Cell>{l.borrower}<span className="block text-xs text-ink-muted">{[l.admission_number, l.class].filter(Boolean).join(' · ')}</span></Cell>
                <Cell>{l.borrowed_at}</Cell>
                <Cell>{l.due_date}{l.renewals > 0 && <span className="block text-xs text-ink-muted">renewed {l.renewals}×</span>}</Cell>
                <Cell>
                  {l.returned_at ? <Badge tone={l.return_condition === 'good' ? 'green' : 'amber'}>returned {l.returned_at}{l.return_condition !== 'good' ? ` · ${l.return_condition}` : ''}</Badge>
                    : l.days_overdue > 0 ? <Badge tone="red">{l.days_overdue} day(s) late</Badge> : <Badge tone="blue">out</Badge>}
                </Cell>
                <Cell>
                  {!l.returned_at && (
                    <span className="flex gap-2">
                      <Button variant="secondary" onClick={() => run(async () => { await fetchApi(`/library/loans/${l.id}/return`, { method: 'POST', body: JSON.stringify({ condition: 'good' }) }); return `"${l.title}" returned.`; })}>Return</Button>
                      <Button variant="secondary" onClick={() => run(async () => { const r = await fetchApi<{ loan: Loan }>(`/library/loans/${l.id}/renew`, { method: 'POST' }); return `Renewed to ${r.loan.due_date}.`; })}>Renew</Button>
                    </span>
                  )}
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card title="Reservations">
        {holds.length === 0 ? <Empty>No one is waiting for a book.</Empty> : (
          <ul className="space-y-1 text-sm">
            {holds.map((h) => (
              <li key={h.id}>
                <span className="font-medium">{h.title}</span> · {h.name}{' '}
                {h.status === 'ready' ? <Badge tone="green">held until {h.ready_until}</Badge> : <Badge tone="amber">waiting</Badge>}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
