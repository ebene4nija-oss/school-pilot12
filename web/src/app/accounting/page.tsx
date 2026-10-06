'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, Meter, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * The school's books beyond fees (doc §7.12): what came in, what went out,
 * petty cash, budgets against actual spending, and the suppliers paid.
 *
 * Every figure is Naira. Fee income is confirmed payments only — a pending
 * transfer is not money in the bank yet.
 */

type Tab = 'report' | 'expenses' | 'petty' | 'budgets' | 'vendors';
interface Report { financial_statement: { total_fee_income: number; total_operating_expenses: number; total_payroll_disbursed: number; net_profit_or_loss: number }; expense_breakdown: { category: string; total: number | string }[] }
interface Expense { id: number; category: string; title: string; amount: string | number; expense_date: string; payment_method: string; notes: string | null; vendor: { name: string } | null }
interface Petty { id: number; type: 'disbursement' | 'replenishment'; amount: number; purpose: string; entry_date: string | null; custodian: string | null }
interface Budget { id: number; category: string; fiscal_year: string; term: string | null; allocated_amount: number; spent: number; remaining: number }
interface Vendor { id: number; name: string; company_name: string | null; phone: string | null; email: string | null; bank_name: string | null; account_number: string | null }

const today = () => new Date().toISOString().slice(0, 10);
const METHODS: Record<string, string> = { cash: 'Cash', bank_transfer: 'Bank transfer', cheque: 'Cheque', petty_cash: 'Petty cash' };

export default function AccountingPage() {
  const [tab, setTab] = useState<Tab>('report');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Stable, because each tab's loader depends on them.
  const done = useCallback((msg: string) => { setNotice(msg); setError(null); }, []);
  const fail = useCallback((err: unknown, fallback: string) => { setError(errorText(err, fallback)); setNotice(null); }, []);

  return (
    <PageShell title="Accounts" subtitle="Income, expenses, petty cash, budgets and suppliers, in Naira.">
      <nav className="mb-5 flex flex-wrap gap-2" aria-label="Accounts sections">
        {([['report', 'Income & expenditure'], ['expenses', 'Expenses'], ['petty', 'Petty cash'], ['budgets', 'Budgets'], ['vendors', 'Suppliers']] as [Tab, string][]).map(([k, l]) => (
          <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setNotice(null); setError(null); }}>{l}</Button>
        ))}
      </nav>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {tab === 'report' && <IncomeReport onError={fail} />}
      {tab === 'expenses' && <Expenses onDone={done} onError={fail} />}
      {tab === 'petty' && <PettyCash onDone={done} onError={fail} />}
      {tab === 'budgets' && <Budgets onDone={done} onError={fail} />}
      {tab === 'vendors' && <Vendors onDone={done} onError={fail} />}
    </PageShell>
  );
}

type Handlers = { onDone: (m: string) => void; onError: (e: unknown, f: string) => void };

function IncomeReport({ onError }: { onError: Handlers['onError'] }) {
  const [range, setRange] = useState({ from: '', to: '' });
  const [r, setR] = useState<Report | null>(null);

  const load = useCallback(async () => {
    const qs = new URLSearchParams(Object.fromEntries(Object.entries(range).filter(([, v]) => v)));
    try { setR(await fetchApi<Report>(`/accounting/income-report?${qs}`)); } catch (err) { onError(err, 'Could not load the report.'); }
  }, [range, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const s = r?.financial_statement;
  const breakdown = (r?.expense_breakdown ?? []).map((b) => ({ category: b.category, total: Number(b.total) })).sort((a, b) => b.total - a.total);
  const max = Math.max(1, ...breakdown.map((b) => b.total));

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <Field label="From"><input className={inputClass} type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
        <Field label="To"><input className={inputClass} type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
        <Button variant="secondary" onClick={() => setRange({ from: '', to: '' })}>All time</Button>
        <Button variant="secondary" onClick={() => window.print()}>Print</Button>
      </div>
      {!s ? <Loading /> : (
        <>
          <p className="text-sm text-ink-muted">{range.from || range.to ? `${range.from || 'the start'} to ${range.to || 'today'}` : 'All time'}. Payroll is everything paid to date.</p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Fee income" value={nairaAmount(s.total_fee_income)} tone="green" hint="confirmed payments" />
            <StatTile label="Operating expenses" value={nairaAmount(s.total_operating_expenses)} tone="amber" />
            <StatTile label="Payroll paid" value={nairaAmount(s.total_payroll_disbursed)} tone="amber" />
            <StatTile label={s.net_profit_or_loss >= 0 ? 'Surplus' : 'Deficit'} value={nairaAmount(Math.abs(s.net_profit_or_loss))} tone={s.net_profit_or_loss >= 0 ? 'green' : 'red'} />
          </div>
          <Card title="Expenses by category">
            {breakdown.length === 0 ? <Empty>No expenses recorded in this period.</Empty> : (
              <ul className="space-y-3">
                {breakdown.map((b) => (
                  <li key={b.category} className="text-sm">
                    <div className="mb-1 flex justify-between"><span>{b.category}</span><span className="font-medium">{nairaAmount(b.total)}</span></div>
                    <Meter value={(b.total / max) * 100} tone="amber" />
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </>
      )}
    </div>
  );
}

function Expenses({ onDone, onError }: Handlers) {
  const [data, setData] = useState<{ data: Expense[]; total: number; categories: string[] } | null>(null);
  const [vendors, setVendors] = useState<Vendor[]>([]);
  const [filter, setFilter] = useState({ from: '', to: '', category: '' });
  const [form, setForm] = useState({ category: '', title: '', amount: '', expense_date: today(), payment_method: 'cash', vendor_id: '', notes: '' });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const qs = new URLSearchParams(Object.fromEntries(Object.entries(filter).filter(([, v]) => v)));
    try { setData(await fetchApi(`/accounting/expenses?${qs}`)); } catch (err) { onError(err, 'Could not load expenses.'); }
  }, [filter, onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);
  useEffect(() => { fetchApi<{ data: Vendor[] }>('/accounting/vendors').then((r) => setVendors(r.data)).catch(() => undefined); }, []);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const res = await fetchApi<{ message: string }>('/accounting/expenses', { method: 'POST', body: JSON.stringify({ ...form, amount: Number(form.amount), vendor_id: form.vendor_id ? Number(form.vendor_id) : null, notes: form.notes || null }) });
      onDone(res.message);
      setForm({ ...form, title: '', amount: '', notes: '' });
      await load();
    } catch (err) { onError(err, 'Could not record the expense.'); } finally { setBusy(false); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <Field label="From"><input className={inputClass} type="date" value={filter.from} onChange={(e) => setFilter({ ...filter, from: e.target.value })} /></Field>
          <Field label="To"><input className={inputClass} type="date" value={filter.to} onChange={(e) => setFilter({ ...filter, to: e.target.value })} /></Field>
          <Field label="Category">
            <select className={inputClass} value={filter.category} onChange={(e) => setFilter({ ...filter, category: e.target.value })}>
              <option value="">All</option>{data?.categories.map((c) => <option key={c}>{c}</option>)}
            </select>
          </Field>
        </div>
        {!data ? <Loading /> : (
          <>
            <p className="mb-3 text-sm">Total spent: <strong>{nairaAmount(data.total)}</strong></p>
            {data.data.length === 0 ? <Empty>No expenses recorded.</Empty> : (
              <Table columns={['Date', 'Item', 'Category', 'Supplier', 'Paid by', 'Amount']}>
                {data.data.map((x) => (
                  <tr key={x.id}>
                    <Cell>{x.expense_date?.slice(0, 10)}</Cell>
                    <Cell className="font-medium">{x.title}{x.notes && <span className="block text-xs text-ink-muted">{x.notes}</span>}</Cell>
                    <Cell>{x.category}</Cell>
                    <Cell>{x.vendor?.name ?? '—'}</Cell>
                    <Cell>{METHODS[x.payment_method] ?? x.payment_method}</Cell>
                    <Cell className="font-medium">{nairaAmount(Number(x.amount))}</Cell>
                  </tr>
                ))}
              </Table>
            )}
          </>
        )}
      </Card>
      <Card title="Record an expense">
        <form onSubmit={save} className="space-y-3">
          <Field label="What was paid for"><input className={inputClass} required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="e.g. Generator diesel" /></Field>
          <Field label="Category"><input className={inputClass} required list="expense-categories" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} placeholder="e.g. Fuel" /></Field>
          <datalist id="expense-categories">{data?.categories.map((c) => <option key={c} value={c} />)}</datalist>
          <Field label="Amount (₦)"><input className={inputClass} type="number" min={0.01} step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
          <Field label="Date"><input className={inputClass} type="date" required value={form.expense_date} onChange={(e) => setForm({ ...form, expense_date: e.target.value })} /></Field>
          <Field label="Paid by">
            <select className={inputClass} value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })}>{Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
          </Field>
          <Field label="Supplier (optional)">
            <select className={inputClass} value={form.vendor_id} onChange={(e) => setForm({ ...form, vendor_id: e.target.value })}><option value="">—</option>{vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</select>
          </Field>
          <Field label="Notes"><input className={inputClass} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
          <Button type="submit" disabled={busy}>Record expense</Button>
        </form>
      </Card>
    </div>
  );
}

function PettyCash({ onDone, onError }: Handlers) {
  const [data, setData] = useState<{ data: Petty[]; balance: number } | null>(null);
  const [form, setForm] = useState({ type: 'disbursement', amount: '', purpose: '', entry_date: today() });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { setData(await fetchApi('/accounting/petty-cash')); } catch (err) { onError(err, 'Could not load petty cash.'); }
  }, [onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (form.type === 'disbursement' && data && Number(form.amount) > data.balance && !window.confirm(`That is more than the ${nairaAmount(data.balance)} in the box. Record it anyway?`)) return;
    setBusy(true);
    try {
      const res = await fetchApi<{ message: string }>('/accounting/petty-cash', { method: 'POST', body: JSON.stringify({ ...form, amount: Number(form.amount) }) });
      onDone(res.message);
      setForm({ ...form, amount: '', purpose: '' });
      await load();
    } catch (err) { onError(err, 'Could not log it.'); } finally { setBusy(false); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card>
        {!data ? <Loading /> : (
          <>
            <div className="mb-4 max-w-xs"><StatTile label="Cash in the box" value={nairaAmount(data.balance)} tone={data.balance < 0 ? 'red' : 'green'} /></div>
            {data.data.length === 0 ? <Empty>No petty cash entries.</Empty> : (
              <Table columns={['Date', 'Purpose', 'By', 'In', 'Out']}>
                {data.data.map((p) => (
                  <tr key={p.id}>
                    <Cell>{p.entry_date}</Cell>
                    <Cell>{p.purpose}</Cell>
                    <Cell className="text-ink-muted">{p.custodian}</Cell>
                    <Cell className="text-positive">{p.type === 'replenishment' ? nairaAmount(p.amount) : ''}</Cell>
                    <Cell className="text-negative">{p.type === 'disbursement' ? nairaAmount(p.amount) : ''}</Cell>
                  </tr>
                ))}
              </Table>
            )}
          </>
        )}
      </Card>
      <Card title="Log petty cash">
        <form onSubmit={save} className="space-y-3">
          <Field label="Type">
            <select className={inputClass} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              <option value="disbursement">Money out (spent)</option>
              <option value="replenishment">Money in (top-up)</option>
            </select>
          </Field>
          <Field label="Amount (₦)"><input className={inputClass} type="number" min={0.01} step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
          <Field label="Purpose"><input className={inputClass} required value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })} /></Field>
          <Field label="Date"><input className={inputClass} type="date" required value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} /></Field>
          <Button type="submit" disabled={busy}>Log it</Button>
        </form>
      </Card>
    </div>
  );
}

function Budgets({ onDone, onError }: Handlers) {
  const [items, setItems] = useState<Budget[] | null>(null);
  const [terms, setTerms] = useState<{ id: number; name: string; session_name?: string | null }[]>([]);
  const [form, setForm] = useState({ category: '', allocated_amount: '', fiscal_year: '', term_id: '' });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { setItems((await fetchApi<{ data: Budget[] }>('/accounting/budget')).data); } catch (err) { onError(err, 'Could not load budgets.'); }
  }, [onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);
  useEffect(() => { fetchApi<{ data: { id: number; name: string; session_name?: string | null }[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined); }, []);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const res = await fetchApi<{ message: string }>('/accounting/budget', { method: 'POST', body: JSON.stringify({ ...form, allocated_amount: Number(form.allocated_amount), term_id: form.term_id ? Number(form.term_id) : null }) });
      onDone(res.message);
      setForm({ ...form, category: '', allocated_amount: '' });
      await load();
    } catch (err) { onError(err, 'Could not save the budget.'); } finally { setBusy(false); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card description="Spending is matched by category: name an expense's category exactly as its budget line.">
        {!items ? <Loading /> : items.length === 0 ? <Empty>No budgets set.</Empty> : (
          <ul className="space-y-4">
            {items.map((b) => {
              const pct = b.allocated_amount > 0 ? (b.spent / b.allocated_amount) * 100 : 0;
              return (
                <li key={b.id} className="text-sm">
                  <div className="mb-1 flex flex-wrap justify-between gap-2">
                    <span><span className="font-medium">{b.category}</span> <span className="text-ink-muted">· {b.term ?? b.fiscal_year}</span></span>
                    <span>{nairaAmount(b.spent)} of {nairaAmount(b.allocated_amount)} {b.remaining < 0 && <Badge tone="red">over by {nairaAmount(-b.remaining)}</Badge>}</span>
                  </div>
                  <Meter value={pct} tone={pct > 100 ? 'red' : pct > 85 ? 'amber' : 'green'} />
                </li>
              );
            })}
          </ul>
        )}
      </Card>
      <Card title="Set a budget">
        <form onSubmit={save} className="space-y-3">
          <Field label="Category"><input className={inputClass} required value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} placeholder="e.g. Fuel" /></Field>
          <Field label="Amount (₦)"><input className={inputClass} type="number" min={0} step="0.01" required value={form.allocated_amount} onChange={(e) => setForm({ ...form, allocated_amount: e.target.value })} /></Field>
          <Field label="School year"><input className={inputClass} required placeholder="e.g. 2026/2027" value={form.fiscal_year} onChange={(e) => setForm({ ...form, fiscal_year: e.target.value })} /></Field>
          <Field label="Term (optional)">
            <select className={inputClass} value={form.term_id} onChange={(e) => setForm({ ...form, term_id: e.target.value })}><option value="">Whole year</option>{terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}</select>
          </Field>
          <Button type="submit" disabled={busy}>Save budget</Button>
        </form>
      </Card>
    </div>
  );
}

function Vendors({ onDone, onError }: Handlers) {
  const [items, setItems] = useState<Vendor[] | null>(null);
  const [form, setForm] = useState({ name: '', company_name: '', phone: '', email: '', bank_name: '', account_number: '' });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { setItems((await fetchApi<{ data: Vendor[] }>('/accounting/vendors')).data); } catch (err) { onError(err, 'Could not load suppliers.'); }
  }, [onError]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() || null]));
      const res = await fetchApi<{ message: string }>('/accounting/vendors', { method: 'POST', body: JSON.stringify(body) });
      onDone(res.message);
      setForm({ name: '', company_name: '', phone: '', email: '', bank_name: '', account_number: '' });
      await load();
    } catch (err) { onError(err, 'Could not add the supplier.'); } finally { setBusy(false); }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
      <Card>
        {!items ? <Loading /> : items.length === 0 ? <Empty>No suppliers yet.</Empty> : (
          <Table columns={['Name', 'Contact', 'Bank']}>
            {items.map((v) => (
              <tr key={v.id}>
                <Cell className="font-medium">{v.name}{v.company_name && <span className="block text-xs text-ink-muted">{v.company_name}</span>}</Cell>
                <Cell className="text-sm">{[v.phone, v.email].filter(Boolean).join(' · ') || '—'}</Cell>
                <Cell className="text-sm">{[v.bank_name, v.account_number].filter(Boolean).join(' · ') || '—'}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      <Card title="Add a supplier">
        <form onSubmit={save} className="space-y-3">
          <Field label="Name"><input className={inputClass} required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
          <Field label="Company"><input className={inputClass} value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} /></Field>
          <Field label="Phone"><input className={inputClass} inputMode="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
          <Field label="Email"><input className={inputClass} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
          <Field label="Bank"><input className={inputClass} value={form.bank_name} onChange={(e) => setForm({ ...form, bank_name: e.target.value })} /></Field>
          <Field label="Account number"><input className={inputClass} inputMode="numeric" maxLength={10} value={form.account_number} onChange={(e) => setForm({ ...form, account_number: e.target.value })} /></Field>
          <Button type="submit" disabled={busy}>Add supplier</Button>
        </form>
      </Card>
    </div>
  );
}
