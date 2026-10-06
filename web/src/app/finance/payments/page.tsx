'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * The payments ledger: every payment, filterable, and the bank transfers
 * waiting to be checked against the bank statement.
 *
 * Approving here is offered only for a pending *bank transfer*. The API's
 * reconcile endpoint would also approve a pending card payment, but that one
 * must settle through the gateway's signed webhook — marking it paid by hand
 * would credit money nobody has confirmed arrived.
 */

interface Row { payment_id: number; receipt_number: string; reference: string | null; amount: number; gateway: string | null; status: string; paid_at: string | null; created_at: string | null; invoice_number: string | null; student_id: number | null; student: string | null; admission_number: string | null; class: string | null }
interface Ledger { data: Row[]; meta: { current_page: number; last_page: number; total: number }; total_received: number }

const GATEWAY: Record<string, string> = { cash: 'Cash', bank_transfer: 'Bank transfer', paystack: 'Paystack', flutterwave: 'Flutterwave' };
const STATUS_TONE: Record<string, 'green' | 'amber' | 'red'> = { successful: 'green', pending: 'amber', failed: 'red' };
const dateOf = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

export default function PaymentsLedger() {
  const [filters, setFilters] = useState({ status: '', gateway: '', from: '', to: '', q: '' });
  const [page, setPage] = useState(1);
  const [ledger, setLedger] = useState<Ledger | null>(null);
  const [waiting, setWaiting] = useState<Row[]>([]);
  const [busy, setBusy] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const qs = new URLSearchParams({ page: String(page), ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) });
    try {
      const [all, pending] = await Promise.all([
        fetchApi<Ledger>(`/finance/payments?${qs}`),
        fetchApi<Ledger>('/finance/payments?status=pending&gateway=bank_transfer&per_page=100'),
      ]);
      setLedger(all);
      setWaiting(pending.data);
    } catch (err) {
      setError(errorText(err, 'Could not load payments.'));
    }
  }, [filters, page]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function reconcile(row: Row, action: 'approve' | 'reject') {
    const verb = action === 'approve' ? `Confirm ${nairaAmount(row.amount)} from ${row.student} has arrived in the school's bank account` : `Reject the transfer of ${nairaAmount(row.amount)} from ${row.student}`;
    const notes = window.prompt(`${verb}?\n\nOptional note (e.g. statement line):`);
    if (notes === null) return;
    setBusy(row.payment_id);
    setError(null);
    try {
      const res = await fetchApi<{ message: string }>('/finance/payments/reconcile-bank-transfer', { method: 'POST', body: JSON.stringify({ payment_id: row.payment_id, action, notes: notes || undefined }) });
      setNotice(res.message);
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not reconcile.'));
    } finally {
      setBusy(null);
    }
  }

  const set = (k: keyof typeof filters, v: string) => { setPage(1); setFilters((f) => ({ ...f, [k]: v })); };
  const account = (r: Row) => `/finance/collect?${new URLSearchParams({ student: String(r.student_id), name: r.student ?? '', adm: r.admission_number ?? '', class: r.class ?? '' })}`;

  return (
    <PageShell title="Payments" subtitle="Every payment received, and bank transfers waiting to be confirmed." actions={<Link href="/finance/collect" className="rounded-lg bg-accent px-3.5 py-2 text-sm font-medium text-accent-ink hover:bg-accent-strong">Record a payment</Link>}>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {waiting.length > 0 && (
        <Card title={`Bank transfers to confirm (${waiting.length})`} description="Check each against the school's bank statement before approving." className="mb-5">
          <Table columns={['Student', 'Invoice', 'Amount', 'Reference', 'Submitted', '']}>
            {waiting.map((r) => (
              <tr key={r.payment_id}>
                <Cell className="font-medium">{r.student}<span className="block text-xs text-ink-muted">{[r.admission_number, r.class].filter(Boolean).join(' · ')}</span></Cell>
                <Cell className="font-mono text-xs">{r.invoice_number}</Cell>
                <Cell>{nairaAmount(r.amount)}</Cell>
                <Cell className="font-mono text-xs">{r.reference}</Cell>
                <Cell>{dateOf(r.created_at)}</Cell>
                <Cell className="space-x-2 whitespace-nowrap text-right">
                  <Button disabled={busy === r.payment_id} onClick={() => reconcile(r, 'approve')}>Approve</Button>
                  <Button variant="danger" disabled={busy === r.payment_id} onClick={() => reconcile(r, 'reject')}>Reject</Button>
                </Cell>
              </tr>
            ))}
          </Table>
        </Card>
      )}

      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Field label="Search"><input className={inputClass} placeholder="Name, admission no., reference" value={filters.q} onChange={(e) => set('q', e.target.value)} /></Field>
          <Field label="Status">
            <select className={inputClass} value={filters.status} onChange={(e) => set('status', e.target.value)}>
              <option value="">All</option><option value="successful">Successful</option><option value="pending">Pending</option><option value="failed">Failed</option>
            </select>
          </Field>
          <Field label="Method">
            <select className={inputClass} value={filters.gateway} onChange={(e) => set('gateway', e.target.value)}>
              <option value="">All</option>{Object.entries(GATEWAY).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label="From"><input className={inputClass} type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} /></Field>
          <Field label="To"><input className={inputClass} type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} /></Field>
        </div>

        {!ledger ? <Loading /> : (
          <>
            <div className="mb-4 grid gap-4 sm:grid-cols-2">
              <StatTile label="Received (confirmed, these filters)" value={nairaAmount(ledger.total_received)} tone="green" />
              <StatTile label="Payments listed" value={ledger.meta.total} />
            </div>
            {ledger.data.length === 0 ? <Empty>No payments match.</Empty> : (
              <Table columns={['Receipt', 'Student', 'Invoice', 'Amount', 'Method', 'Status', 'Date']}>
                {ledger.data.map((r) => (
                  <tr key={r.payment_id}>
                    <Cell className="font-mono text-xs">{r.receipt_number}<span className="block text-ink-faint">{r.reference}</span></Cell>
                    <Cell>{r.student_id ? <Link href={account(r)} className="font-medium text-brand hover:underline">{r.student}</Link> : r.student}<span className="block text-xs text-ink-muted">{[r.admission_number, r.class].filter(Boolean).join(' · ')}</span></Cell>
                    <Cell className="font-mono text-xs">{r.invoice_number}</Cell>
                    <Cell>{nairaAmount(r.amount)}</Cell>
                    <Cell>{GATEWAY[r.gateway ?? ''] ?? r.gateway}</Cell>
                    <Cell><Badge tone={STATUS_TONE[r.status] ?? 'slate'}>{r.status}</Badge></Cell>
                    <Cell>{dateOf(r.paid_at ?? r.created_at)}</Cell>
                  </tr>
                ))}
              </Table>
            )}
            {ledger.meta.last_page > 1 && (
              <div className="mt-4 flex items-center gap-3">
                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Newer</Button>
                <span className="text-sm text-ink-muted">Page {page} of {ledger.meta.last_page}</span>
                <Button variant="secondary" disabled={page >= ledger.meta.last_page} onClick={() => setPage(page + 1)}>Older</Button>
              </div>
            )}
          </>
        )}
      </Card>
    </PageShell>
  );
}
