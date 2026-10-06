'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

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
          {!rules ? <Loading /> : rules.length === 0 ? <p className="text-sm text-ink-muted">None set. The hostel office sets these.</p> : (
            <ul className="space-y-2 text-sm">
              {rules.map((r) => (
                <li key={r.id} className={r.is_active ? '' : 'text-ink-faint line-through'}>
                  <span className="font-medium">{r.title}</span> · {nairaAmount(Number(r.amount))}
                  <span className="block text-xs text-ink-muted">{r.boarding_category.replaceAll('_', ' ')} boarders · {r.charge_type}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </PageShell>
  );
}
