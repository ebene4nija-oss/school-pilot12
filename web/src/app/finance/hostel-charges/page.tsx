'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import { useCookieRole } from '@/components/Sidebar';

/**
 * Hostel charges, as Finance sees them (TASK 17): the boarding fee rules and
 * every charge raised against a boarder. Read-only here — the hostel office
 * bills them, and once posted they appear on the student's term invoice, so
 * payment is taken through Collect payments like any other fee.
 */

interface Rule { id: number; boarding_category: string; charge_type: string; title: string; amount: string | number; is_active: boolean }
interface Charge { id: number; student_id: number; student_name: string | null; term_id: number | null; charge_type: string; title: string; amount: string | number; status: string; invoice_id: number | null }

const STATUS: Record<string, 'green' | 'amber' | 'slate'> = { posted: 'green', pending: 'amber', void: 'slate' };

export default function HostelChargesPage() {
  const [rules, setRules] = useState<Rule[] | null>(null);
  const [charges, setCharges] = useState<Charge[] | null>(null);
  const [terms, setTerms] = useState<{ id: number; name: string; session_name?: string | null }[]>([]);
  const [termId, setTermId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const admin = useCookieRole() === 'school_admin';
  const [rule, setRule] = useState({ boarding_category: 'full', charge_type: 'boarding', title: '', amount: '' });
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  async function post(path: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    try {
      const r = await fetchApi<{ message?: string }>(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(r.message ?? done);
      const rr = await fetchApi<{ data: Rule[] }>('/hostel/fee-rules');
      setRules(rr.data);
      await load();
      return true;
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
      return false;
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    fetchApi<{ data: Rule[] }>('/hostel/fee-rules').then((r) => setRules(r.data)).catch((err) => setError(errorText(err, 'Could not load hostel fees.')));
    fetchApi<{ data: { id: number; name: string; session_name?: string | null }[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    try {
      setCharges((await fetchApi<{ data: Charge[] }>(`/hostel/charges${termId ? `?term_id=${termId}` : ''}`)).data);
    } catch (err) {
      setError(errorText(err, 'Could not load hostel charges.'));
    }
  }, [termId]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const live = (charges ?? []).filter((c) => c.status !== 'void');
  const total = live.reduce((t, c) => t + Number(c.amount), 0);
  const pending = live.filter((c) => c.status === 'pending');

  return (
    <PageShell title="Hostel charges" subtitle="Boarding fees raised by the hostel office. Once posted they are on the student's term invoice.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <Card>
          <div className="mb-4 max-w-xs">
            <Field label="Term">
              <select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)}>
                <option value="">All terms</option>
                {terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}
              </select>
            </Field>
          </div>
          {!charges ? <Loading /> : (
            <>
              <div className="mb-4 grid gap-4 sm:grid-cols-2">
                <StatTile label="Charged" value={nairaAmount(total)} hint={`${live.length} charge(s)`} />
                <StatTile label="Not yet on an invoice" value={pending.length} tone={pending.length ? 'amber' : 'slate'} hint={pending.length ? 'generate the term invoices first' : undefined} />
              </div>
              {charges.length === 0 ? <Empty>No hostel charges.</Empty> : (
                <Table columns={['Student', 'Charge', 'Amount', 'Status']}>
                  {charges.map((c) => (
                    <tr key={c.id}>
                      <Cell className="font-medium">{c.student_name ?? `#${c.student_id}`}</Cell>
                      <Cell>{c.title}<span className="block text-xs text-ink-muted">{c.charge_type}</span></Cell>
                      <Cell>{nairaAmount(Number(c.amount))}</Cell>
                      <Cell><Badge tone={STATUS[c.status] ?? 'slate'}>{c.status === 'posted' ? 'on invoice' : c.status}</Badge></Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </>
          )}
        </Card>
        <Card title="Boarding fee rules">
          {!rules ? <Loading /> : rules.length === 0 ? <p className="text-sm text-ink-muted">None set yet.</p> : (
            <ul className="space-y-2 text-sm">
              {rules.map((r) => (
                <li key={r.id} className={r.is_active ? '' : 'text-ink-faint line-through'}>
                  <span className="font-medium">{r.title}</span> · {nairaAmount(Number(r.amount))}
                  <span className="block text-xs text-ink-muted">{r.boarding_category.replaceAll('_', ' ')} boarders · {r.charge_type}</span>
                </li>
              ))}
            </ul>
          )}
          {admin && (
            <div className="mt-4 space-y-4 border-t border-line-soft pt-4">
              <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); void post('/hostel/fee-rules', { ...rule, amount: Number(rule.amount) }, 'Fee rule added.').then((ok) => ok && setRule({ ...rule, title: '', amount: '' })); }}>
                <p className="text-sm font-semibold">Add a fee rule</p>
                <Field label="Boarders"><select className={inputClass} value={rule.boarding_category} onChange={(e) => setRule({ ...rule, boarding_category: e.target.value })}><option value="full">Full boarders</option><option value="weekly">Weekly boarders</option><option value="flexible">Flexible</option><option value="day_services">Day services</option></select></Field>
                <Field label="Kind"><select className={inputClass} value={rule.charge_type} onChange={(e) => setRule({ ...rule, charge_type: e.target.value })}>{['boarding', 'meals', 'deposit', 'laundry', 'other'].map((t) => <option key={t}>{t}</option>)}</select></Field>
                <Field label="Title"><input className={inputClass} required maxLength={120} value={rule.title} onChange={(e) => setRule({ ...rule, title: e.target.value })} placeholder="Boarding fee" /></Field>
                <Field label="Amount per term (₦)"><input className={inputClass} type="number" min={0} required value={rule.amount} onChange={(e) => setRule({ ...rule, amount: e.target.value })} /></Field>
                <Button type="submit" variant="secondary" disabled={busy}>Add rule</Button>
              </form>
              <div>
                <p className="mb-1 text-sm font-semibold">Bill a term</p>
                <p className="mb-2 text-xs text-ink-muted">Adds each boarder’s charges to their term invoice. Safe to run again: nothing is billed twice.</p>
                <Button disabled={busy || !termId} onClick={() => { if (window.confirm('Add this term’s hostel charges to boarders’ invoices?')) void post('/hostel/bill', { term_id: Number(termId) }, 'Billed.'); }}>Bill {terms.find((t) => String(t.id) === termId)?.name ?? 'the chosen term'}</Button>
                {!termId && <p className="mt-1 text-xs text-ink-muted">Choose the term on the left first.</p>}
              </div>
            </div>
          )}
        </Card>
      </div>
    </PageShell>
  );
}
