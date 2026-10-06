'use client';

import React, { Suspense, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * The accounts-office counter (doc §7.12): find a student, see their
 * statement, take a cash or bank-transfer payment, apply a scholarship
 * discount, agree an instalment plan, and print a receipt.
 *
 * Online card payments are not recorded here — they settle only when the
 * gateway's signed webhook confirms them. A recorded payment is capped at the
 * invoice balance on this screen, because the API does not refuse an
 * overpayment and an overpaid invoice is a refund nobody planned.
 */

interface Payment { payment_id: number; receipt_number: string; amount: number; gateway: string | null; reference: string | null; paid_at: string | null }
interface Invoice { invoice_id: number; invoice_number: string; term: string | null; total_amount: number; amount_paid: number; balance: number; status: string; due_date: string | null; payments: Payment[] }
interface Statement { student_id: number; total_invoiced: number; total_paid: number; balance: number; invoices: Invoice[] }
interface Scholarship { id: number; name: string; type: 'percentage' | 'fixed'; value: string | number }
interface Plan { invoice_balance: number; installments: { id: number; amount: number; due_date: string | null; status: string }[]; overdue_count: number }
interface Receipt { school: string; student: PickedStudent; invoice: Invoice; payment: Payment }

const TONE: Record<string, 'green' | 'amber' | 'red' | 'slate'> = { paid: 'green', partial: 'amber', unpaid: 'red', overdue: 'red' };
const GATEWAY: Record<string, string> = { cash: 'Cash', bank_transfer: 'Bank transfer', paystack: 'Paystack', flutterwave: 'Flutterwave' };
const dateOf = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

export default function CollectPageRoute() {
  return <Suspense><CollectPage /></Suspense>;
}

function CollectPage() {
  const params = useSearchParams();
  // Deep link from the payments ledger: /finance/collect?student=12&name=…
  const [student, setStudent] = useState<PickedStudent | null>(() => {
    const id = Number(params.get('student'));
    return id ? { id, name: params.get('name'), admission_number: params.get('adm'), class: params.get('class') } : null;
  });
  const [statement, setStatement] = useState<Statement | null>(null);
  const [scholarships, setScholarships] = useState<Scholarship[]>([]);
  const [school, setSchool] = useState('');
  const [action, setAction] = useState<{ kind: 'pay' | 'discount' | 'plan'; invoice: Invoice } | null>(null);
  const [plans, setPlans] = useState<Record<number, Plan>>({});
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Payment form
  const [method, setMethod] = useState<'cash' | 'bank_transfer'>('cash');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  // Discount form
  const [scholarshipId, setScholarshipId] = useState('');
  const [reason, setReason] = useState('');
  // Plan form
  const [rows, setRows] = useState<{ amount: string; due_date: string }[]>([]);

  useEffect(() => {
    fetchApi<{ data: Scholarship[] }>('/finance/scholarships').then((r) => setScholarships(r.data)).catch(() => undefined);
    fetchApi<{ school: { name: string } | null }>('/user').then((u) => setSchool(u.school?.name ?? '')).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    if (!student) return;
    try {
      setStatement(await fetchApi<Statement>(`/finance/students/${student.id}/statement`));
    } catch (err) {
      setError(errorText(err, 'Could not load the statement.'));
    }
  }, [student]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  function choose(s: PickedStudent | null) {
    setStudent(s);
    setStatement(null);
    setAction(null);
    setPlans({});
    setNotice(null);
    setError(null);
  }

  function open(kind: 'pay' | 'discount' | 'plan', invoice: Invoice) {
    setAction({ kind, invoice });
    setError(null);
    setNotice(null);
    if (kind === 'pay') { setAmount(String(invoice.balance)); setReference(''); setMethod('cash'); }
    if (kind === 'discount') { setScholarshipId(''); setReason(''); }
    if (kind === 'plan') {
      const half = Math.round((invoice.balance / 2) * 100) / 100;
      setRows([{ amount: String(half), due_date: '' }, { amount: String(Math.round((invoice.balance - half) * 100) / 100), due_date: '' }]);
    }
  }

  async function showPlan(invoice: Invoice) {
    try {
      const plan = await fetchApi<Plan>(`/finance/invoices/${invoice.invoice_id}/installment-plan`);
      setPlans((p) => ({ ...p, [invoice.invoice_id]: plan }));
    } catch (err) {
      setError(errorText(err, 'Could not load the instalment plan.'));
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!action || !student) return;
    const inv = action.invoice;
    setBusy(true);
    setError(null);
    try {
      if (action.kind === 'pay') {
        const value = Number(amount);
        if (!(value > 0) || value > inv.balance) throw new Error(`Enter an amount between ₦1 and ${nairaAmount(inv.balance)}.`);
        if (!window.confirm(`Record ${nairaAmount(value)} received by ${GATEWAY[method].toLowerCase()} against ${inv.invoice_number} for ${student.name}?`)) { setBusy(false); return; }
        const res = await fetchApi<{ message: string; payment: { id: number; reference: string; paid_at: string | null; amount: string | number; gateway: string } }>('/finance/payments', {
          method: 'POST',
          body: JSON.stringify({ invoice_id: inv.invoice_id, amount: value, gateway: method, ...(reference.trim() ? { reference: reference.trim() } : {}) }),
        });
        setNotice(res.message);
        setReceipt({
          school, student, invoice: { ...inv, amount_paid: inv.amount_paid + value, balance: Math.max(0, inv.balance - value) },
          payment: { payment_id: res.payment.id, receipt_number: `REC-${String(res.payment.id).padStart(6, '0')}`, amount: Number(res.payment.amount), gateway: res.payment.gateway, reference: res.payment.reference, paid_at: res.payment.paid_at },
        });
      } else if (action.kind === 'discount') {
        const sch = scholarships.find((s) => String(s.id) === scholarshipId);
        if (!window.confirm(`Apply "${sch?.name}" to ${inv.invoice_number}? This lowers the invoice total and is recorded in the audit log.`)) { setBusy(false); return; }
        const res = await fetchApi<{ message: string; discount_applied: number }>(`/finance/invoices/${inv.invoice_id}/apply-discount`, { method: 'POST', body: JSON.stringify({ scholarship_id: Number(scholarshipId), reason: reason || undefined }) });
        setNotice(`${res.message} ${nairaAmount(res.discount_applied)} off.`);
      } else {
        const res = await fetchApi<{ message: string }>(`/finance/invoices/${inv.invoice_id}/installment-plan`, { method: 'POST', body: JSON.stringify({ installments: rows.map((r) => ({ amount: Number(r.amount), due_date: r.due_date })) }) });
        setNotice(res.message);
        await showPlan(inv);
      }
      setAction(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  const planTotal = rows.reduce((t, r) => t + (Number(r.amount) || 0), 0);

  return (
    <PageShell title="Collect payments" subtitle="Take cash and bank-transfer payments at the accounts office, and manage a student's account.">
      <div className="print:hidden">
        <ErrorNote message={error} />
        {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

        <div className="mb-6 max-w-lg">
          <Field label="Student">
            <StudentPicker value={student} onChange={choose} />
          </Field>
        </div>

        {!student ? <Empty>Find a student by name or admission number to see their account.</Empty> : !statement ? <Loading /> : (
          <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-3">
              <StatTile label="Billed" value={nairaAmount(statement.total_invoiced)} />
              <StatTile label="Paid" value={nairaAmount(statement.total_paid)} tone="green" />
              <StatTile label="Balance" value={nairaAmount(statement.balance)} tone={statement.balance > 0 ? 'red' : 'green'} />
            </div>

            {statement.invoices.length === 0 ? <Empty>No invoices for this student yet. Generate term invoices on the Fees & finance page.</Empty> : statement.invoices.map((inv) => (
              <Card key={inv.invoice_id} title={`${inv.invoice_number}${inv.term ? ` · ${inv.term}` : ''}`}>
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                  <span>Total <strong>{nairaAmount(inv.total_amount)}</strong></span>
                  <span>Paid <strong className="text-positive">{nairaAmount(inv.amount_paid)}</strong></span>
                  <span>Balance <strong className={inv.balance > 0 ? 'text-negative' : ''}>{nairaAmount(inv.balance)}</strong></span>
                  {inv.due_date && <span className="text-ink-muted">due {dateOf(inv.due_date)}</span>}
                  <Badge tone={TONE[inv.status] ?? 'slate'}>{inv.status}</Badge>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                  {inv.balance > 0 && <Button variant="accent" onClick={() => open('pay', inv)}>Record payment</Button>}
                  {inv.balance > 0 && scholarships.length > 0 && <Button variant="secondary" onClick={() => open('discount', inv)}>Apply scholarship</Button>}
                  {inv.balance > 0 && <Button variant="secondary" onClick={() => open('plan', inv)}>Instalment plan</Button>}
                  <Button variant="secondary" onClick={() => showPlan(inv)}>View plan</Button>
                </div>

                {action?.invoice.invoice_id === inv.invoice_id && (
                  <form onSubmit={submit} className="mt-4 rounded-lg border border-line-soft bg-surface-sunken p-4">
                    {action.kind === 'pay' && (
                      <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Received by">
                          <select className={inputClass} value={method} onChange={(e) => setMethod(e.target.value as 'cash' | 'bank_transfer')}>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank transfer (confirmed on statement)</option>
                          </select>
                        </Field>
                        <Field label={`Amount (₦, up to ${nairaAmount(inv.balance)})`}><input className={inputClass} type="number" min={1} max={inv.balance} step="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
                        <Field label="Teller / slip number (optional)"><input className={inputClass} maxLength={100} value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
                      </div>
                    )}
                    {action.kind === 'discount' && (
                      <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Scholarship or discount">
                          <select className={inputClass} required value={scholarshipId} onChange={(e) => setScholarshipId(e.target.value)}>
                            <option value="">Choose…</option>
                            {scholarships.map((s) => <option key={s.id} value={s.id}>{s.name} — {s.type === 'percentage' ? `${Number(s.value)}%` : nairaAmount(Number(s.value))}</option>)}
                          </select>
                        </Field>
                        <Field label="Reason (kept in the audit log)"><input className={inputClass} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                      </div>
                    )}
                    {action.kind === 'plan' && (
                      <div className="space-y-2">
                        <p className="text-sm text-ink-muted">Split the balance of {nairaAmount(inv.balance)} into 2–12 parts. The parts must add up to the balance.</p>
                        {rows.map((r, i) => (
                          <div key={i} className="flex flex-wrap items-end gap-2">
                            <Field label={`Part ${i + 1} (₦)`}><input className={inputClass} type="number" min={1} step="0.01" required value={r.amount} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} /></Field>
                            <Field label="Due"><input className={inputClass} type="date" required value={r.due_date} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, due_date: e.target.value } : x)))} /></Field>
                            {rows.length > 2 && <Button type="button" variant="secondary" onClick={() => setRows(rows.filter((_, j) => j !== i))}>Remove</Button>}
                          </div>
                        ))}
                        {rows.length < 12 && <Button type="button" variant="secondary" onClick={() => setRows([...rows, { amount: '', due_date: '' }])}>Add a part</Button>}
                        <p className={`text-sm ${Math.abs(planTotal - inv.balance) < 0.01 ? 'text-positive' : 'text-negative'}`}>Total {nairaAmount(planTotal)} of {nairaAmount(inv.balance)}</p>
                      </div>
                    )}
                    <div className="mt-3 flex gap-2">
                      <Button type="submit" disabled={busy}>{busy ? 'Saving…' : action.kind === 'pay' ? 'Record payment' : action.kind === 'discount' ? 'Apply' : 'Agree plan'}</Button>
                      <Button type="button" variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
                    </div>
                  </form>
                )}

                {plans[inv.invoice_id] && (
                  <div className="mt-4">
                    <p className="mb-2 text-sm font-semibold">Instalment plan{plans[inv.invoice_id].overdue_count ? <Badge tone="red"> {plans[inv.invoice_id].overdue_count} overdue</Badge> : null}</p>
                    {plans[inv.invoice_id].installments.length === 0 ? <p className="text-sm text-ink-muted">No plan agreed.</p> : (
                      <ul className="space-y-1 text-sm">{plans[inv.invoice_id].installments.map((p) => <li key={p.id}>{nairaAmount(p.amount)} due {dateOf(p.due_date)} <Badge tone={p.status === 'paid' ? 'green' : p.status === 'overdue' ? 'red' : 'amber'}>{p.status}</Badge></li>)}</ul>
                    )}
                  </div>
                )}

                {inv.payments.length > 0 && (
                  <div className="mt-4">
                    <Table columns={['Receipt', 'Amount', 'Method', 'Reference', 'Date', '']}>
                      {inv.payments.map((p) => (
                        <tr key={p.payment_id}>
                          <Cell className="font-mono text-xs">{p.receipt_number}</Cell>
                          <Cell>{nairaAmount(p.amount)}</Cell>
                          <Cell>{GATEWAY[p.gateway ?? ''] ?? p.gateway}</Cell>
                          <Cell className="font-mono text-xs">{p.reference}</Cell>
                          <Cell>{dateOf(p.paid_at)}</Cell>
                          <Cell><button type="button" className="text-sm text-brand underline" onClick={() => setReceipt({ school, student, invoice: inv, payment: p })}>Receipt</button></Cell>
                        </tr>
                      ))}
                    </Table>
                  </div>
                )}
              </Card>
            ))}
          </div>
        )}
      </div>

      {receipt && (
        <div className="fixed inset-0 z-20 flex items-start justify-center overflow-y-auto bg-black/40 p-6 print:static print:block print:bg-white print:p-0">
          <div className="w-full max-w-md rounded-xl bg-white p-6 text-black shadow-xl print:max-w-none print:shadow-none">
            <p className="text-lg font-bold">{receipt.school}</p>
            <p className="text-sm">Official receipt</p>
            <hr className="my-3" />
            <dl className="grid grid-cols-2 gap-y-1 text-sm">
              <dt>Receipt no.</dt><dd className="font-mono">{receipt.payment.receipt_number}</dd>
              <dt>Date</dt><dd>{dateOf(receipt.payment.paid_at)}</dd>
              <dt>Student</dt><dd>{receipt.student.name}</dd>
              <dt>Admission no.</dt><dd>{receipt.student.admission_number ?? '—'}</dd>
              <dt>Class</dt><dd>{receipt.student.class ?? '—'}</dd>
              <dt>Invoice</dt><dd className="font-mono">{receipt.invoice.invoice_number}{receipt.invoice.term ? ` · ${receipt.invoice.term}` : ''}</dd>
              <dt>Method</dt><dd>{GATEWAY[receipt.payment.gateway ?? ''] ?? receipt.payment.gateway}</dd>
              <dt>Reference</dt><dd className="font-mono text-xs">{receipt.payment.reference}</dd>
            </dl>
            <hr className="my-3" />
            <p className="flex justify-between text-base font-bold"><span>Amount received</span><span>{nairaAmount(receipt.payment.amount)}</span></p>
            <p className="mt-1 flex justify-between text-sm"><span>Balance on this invoice</span><span>{nairaAmount(receipt.invoice.balance)}</span></p>
            <p className="mt-8 border-t pt-1 text-xs">Bursar’s signature</p>
            <div className="mt-5 flex gap-2 print:hidden">
              <Button onClick={() => window.print()}>Print receipt</Button>
              <Button variant="secondary" onClick={() => setReceipt(null)}>Close</Button>
            </div>
          </div>
        </div>
      )}
    </PageShell>
  );
}
