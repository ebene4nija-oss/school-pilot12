'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import ChildSwitcher, { useChildren } from '@/components/ChildSwitcher';
import Receipt, { type ReceiptData } from '@/components/Receipt';

/**
 * A parent's view of their child's fees: what was billed, what is paid, and
 * paying the balance online.
 *
 * Payment opens the school's own Paystack or Flutterwave checkout, through
 * the same endpoint as the app; the amount is checked on the server, and the
 * invoice updates when the gateway confirms, not when the page says so.
 */

type Invoice = {
  invoice_id: number;
  invoice_number: string;
  term: string | null;
  total_amount: number;
  amount_paid: number;
  balance: number;
  status: string;
  due_date: string | null;
  payments?: { payment_id: number; receipt_number: string; amount: number; gateway: string | null; reference: string | null; paid_at: string | null }[];
};

type Statement = { total_invoiced: number; total_paid: number; balance: number; invoices: Invoice[] };

const TONE: Record<string, 'green' | 'amber' | 'red' | 'slate'> = { paid: 'green', partial: 'amber', unpaid: 'red', overdue: 'red' };

export default function ParentFeesPage() {
  const { children, selected, select, error: childError } = useChildren();
  const [statement, setStatement] = useState<Statement | null>(null);
  const [paying, setPaying] = useState<number | null>(null);
  const [amounts, setAmounts] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [receipt, setReceipt] = useState<ReceiptData | null>(null);
  const [school, setSchool] = useState('');

  useEffect(() => { fetchApi<{ school: { name: string } | null }>('/user').then((u) => setSchool(u.school?.name ?? '')).catch(() => undefined); }, []);

  const load = useCallback(async () => {
    if (!selected) return;
    setStatement(null);
    setError(null);
    try {
      setStatement(await fetchApi<Statement>(`/finance/students/${selected.id}/statement`));
    } catch (err) {
      setError(errorText(err, 'Could not load the fee statement.'));
    }
  }, [selected]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const pay = async (invoice: Invoice) => {
    const typed = amounts[invoice.invoice_id];
    const amount = typed ? Number(typed) : invoice.balance;

    if (!(amount > 0) || amount > invoice.balance) {
      setError(`Enter an amount between ₦1 and ${nairaAmount(invoice.balance)}.`);
      return;
    }

    setPaying(invoice.invoice_id);
    setError(null);
    try {
      const res = await fetchApi<{ authorization_url: string }>('/finance/payments/initialize', {
        method: 'POST',
        body: JSON.stringify({ invoice_id: invoice.invoice_id, amount }),
      });
      window.location.href = res.authorization_url;
    } catch (err) {
      setError(errorText(err, 'Could not open the payment page.'));
      setPaying(null);
    }
  };

  return (
    <PageShell title="Fees" subtitle="What has been billed, what is paid, and paying the balance online.">
      <div className={receipt ? 'print:hidden' : ''}>
      <ErrorNote message={childError ?? error} />

      {!children ? (
        <Loading />
      ) : children.length === 0 ? (
        <Empty>No child is linked to your account yet. Ask the school office to link your child.</Empty>
      ) : (
        <>
          <ChildSwitcher items={children} selected={selected} onSelect={select} />

          {!statement ? (
            !error && <Loading />
          ) : (
            <>
              <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatTile label="Billed" value={nairaAmount(statement.total_invoiced)} />
                <StatTile label="Paid" value={nairaAmount(statement.total_paid)} tone="green" />
                <StatTile label="Balance" value={nairaAmount(statement.balance)} tone={statement.balance > 0 ? 'amber' : 'green'} />
              </div>

              <Card title={`Invoices — ${selected?.name ?? ''}`}>
                {statement.invoices.length === 0 ? (
                  <Empty>No invoices yet.</Empty>
                ) : (
                  <Table columns={['Invoice', 'Term', 'Amount', 'Paid', 'Balance', 'Status', '']}>
                    {statement.invoices.map((inv) => (
                      <tr key={inv.invoice_id}>
                        <Cell className="font-mono text-xs">
                          <a href={`/api/backend/finance/invoices/${inv.invoice_id}/pdf`} target="_blank" rel="noopener" className="text-brand hover:underline">
                            {inv.invoice_number}
                          </a>
                          {inv.due_date && <span className="block text-ink-faint">Due {new Date(inv.due_date).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
                        </Cell>
                        <Cell>{inv.term ?? '—'}</Cell>
                        <Cell>{nairaAmount(inv.total_amount)}</Cell>
                        <Cell>{nairaAmount(inv.amount_paid)}</Cell>
                        <Cell className="font-semibold">{nairaAmount(inv.balance)}</Cell>
                        <Cell><Badge tone={TONE[inv.status] ?? 'slate'}>{inv.status}</Badge></Cell>
                        <Cell>
                          {inv.balance > 0 && (
                            <div className="flex flex-wrap items-center gap-2">
                              <input
                                type="number"
                                min={1}
                                max={inv.balance}
                                placeholder={String(inv.balance)}
                                value={amounts[inv.invoice_id] ?? ''}
                                onChange={(e) => setAmounts({ ...amounts, [inv.invoice_id]: e.target.value })}
                                className={`${inputClass} w-28`}
                                aria-label="Amount to pay"
                              />
                              <Button variant="accent" disabled={paying !== null} onClick={() => pay(inv)}>
                                {paying === inv.invoice_id ? 'Opening…' : 'Pay'}
                              </Button>
                            </div>
                          )}
                        </Cell>
                      </tr>
                    ))}
                  </Table>
                )}
                <p className="mt-4 text-xs text-ink-muted">
                  Leave the amount blank to pay the full balance, or enter part of it. Tap an invoice number for the PDF.
                  After paying, the balance updates once your bank confirms — usually within a minute.
                </p>
              </Card>

              <Card title="Receipts" className="mt-6" description="Every payment the school has confirmed, with its receipt number.">
                {statement.invoices.every((inv) => !inv.payments?.length) ? (
                  <Empty>No payments yet.</Empty>
                ) : (
                  <Table columns={['Receipt', 'Date', 'Invoice', 'Amount', 'Paid by', 'Reference', '']}>
                    {statement.invoices.flatMap((inv) =>
                      (inv.payments ?? []).map((p) => (
                        <tr key={p.payment_id}>
                          <Cell className="font-mono text-xs">{p.receipt_number}</Cell>
                          <Cell>{p.paid_at ? new Date(p.paid_at).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}</Cell>
                          <Cell className="font-mono text-xs">{inv.invoice_number}</Cell>
                          <Cell className="font-semibold">{nairaAmount(p.amount)}</Cell>
                          <Cell className="capitalize">{(p.gateway ?? '—').replace('_', ' ')}</Cell>
                          <Cell className="font-mono text-xs">{p.reference ?? '—'}</Cell>
                          <Cell><button type="button" className="text-sm text-brand underline" onClick={() => setReceipt({ school, student: { name: selected?.name ?? null, admission_number: selected?.admission_number }, invoiceNumber: inv.invoice_number, term: inv.term, receiptNumber: p.receipt_number, amount: p.amount, method: p.gateway, reference: p.reference, paidAt: p.paid_at, balance: inv.balance })}>Print</button></Cell>
                        </tr>
                      )),
                    )}
                  </Table>
                )}
              </Card>
            </>
          )}
        </>
      )}
      </div>
      {receipt && <Receipt data={receipt} onClose={() => setReceipt(null)} />}
    </PageShell>
  );
}
