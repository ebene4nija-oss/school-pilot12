'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Empty, ErrorNote, Loading, PageShell, StatTile, Table, nairaAmount } from '@/components/Page';
import { day, myStudentId } from '@/lib/student';

/**
 * My fees: what the school has billed and what has been paid, read-only.
 * Paying stays with the parent's account, which opens the school's own
 * payment page; a student is shown the balance so they know where they stand.
 */

interface Invoice { invoice_id: number; invoice_number: string; term: string | null; total_amount: number; amount_paid: number; balance: number; status: string; due_date: string | null; payments?: { payment_id: number; receipt_number: string; amount: number; paid_at: string | null }[] }
interface Statement { total_invoiced: number; total_paid: number; balance: number; invoices: Invoice[] }

const TONE: Record<string, 'green' | 'amber' | 'red' | 'slate'> = { paid: 'green', partial: 'amber', unpaid: 'red', overdue: 'red' };

export default function StudentFees() {
  const [s, setS] = useState<Statement | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    myStudentId()
      .then((id) => fetchApi<Statement>(`/finance/students/${id}/statement`))
      .then(setS)
      .catch((err) => setError(errorText(err, 'Could not load your fee statement.')));
  }, []);

  const receipts = (s?.invoices ?? []).flatMap((i) => (i.payments ?? []).map((p) => ({ ...p, invoice: i.invoice_number })));

  return (
    <PageShell title="My fees" subtitle="Your school fees in Naira. Your parent or guardian pays from their own account.">
      <ErrorNote message={error} />
      {!s ? (!error && <Loading />) : (
        <div className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-3">
            <StatTile label="Billed" value={nairaAmount(s.total_invoiced)} />
            <StatTile label="Paid" value={nairaAmount(s.total_paid)} tone="green" />
            <StatTile label="Balance" value={nairaAmount(s.balance)} tone={s.balance > 0 ? 'amber' : 'green'} />
          </div>
          <Card title="Invoices">
            {s.invoices.length === 0 ? <Empty>No invoices yet.</Empty> : (
              <Table columns={['Invoice', 'Term', 'Amount', 'Paid', 'Balance', 'Due', 'Status']}>
                {s.invoices.map((i) => (
                  <tr key={i.invoice_id}>
                    <Cell className="font-mono text-xs">{i.invoice_number}</Cell>
                    <Cell>{i.term ?? '—'}</Cell>
                    <Cell>{nairaAmount(i.total_amount)}</Cell>
                    <Cell>{nairaAmount(i.amount_paid)}</Cell>
                    <Cell className="font-medium">{nairaAmount(i.balance)}</Cell>
                    <Cell>{i.due_date ? day(i.due_date) : '—'}</Cell>
                    <Cell><Badge tone={TONE[i.status] ?? 'slate'}>{i.status}</Badge></Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
          {receipts.length > 0 && (
            <Card title="Payments received">
              <Table columns={['Receipt', 'Invoice', 'Amount', 'Date']}>
                {receipts.map((p) => (
                  <tr key={p.payment_id}>
                    <Cell className="font-mono text-xs">{p.receipt_number}</Cell>
                    <Cell className="font-mono text-xs">{p.invoice}</Cell>
                    <Cell>{nairaAmount(p.amount)}</Cell>
                    <Cell>{p.paid_at ? day(p.paid_at) : '—'}</Cell>
                  </tr>
                ))}
              </Table>
            </Card>
          )}
        </div>
      )}
    </PageShell>
  );
}
