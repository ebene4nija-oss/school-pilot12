'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * Scholarships and discounts the school offers (doc §7.12): a staff-child
 * discount, a sibling discount, a merit award. Defined here, applied to a
 * particular invoice on the Collect payments screen, where each use is
 * written to the audit log.
 */

interface Scholarship { id: number; name: string; type: 'percentage' | 'fixed'; value: string | number; description: string | null }

const blank = { name: '', type: 'percentage' as 'percentage' | 'fixed', value: '', description: '' };

export default function ScholarshipsPage() {
  const [items, setItems] = useState<Scholarship[] | null>(null);
  const [form, setForm] = useState(blank);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setItems((await fetchApi<{ data: Scholarship[] }>('/finance/scholarships')).data);
    } catch (err) {
      setError(errorText(err, 'Could not load scholarships.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function create(e: React.FormEvent) {
    e.preventDefault();
    const value = Number(form.value);
    if (form.type === 'percentage' && (value <= 0 || value > 100)) { setError('A percentage must be between 1 and 100.'); return; }
    setBusy(true);
    setError(null);
    try {
      const res = await fetchApi<{ message: string }>('/finance/scholarships', { method: 'POST', body: JSON.stringify({ ...form, value, description: form.description || undefined }) });
      setNotice(res.message);
      setForm(blank);
      await load();
    } catch (err) {
      setError(errorText(err, 'Could not create it.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell title="Scholarships & discounts" subtitle="Define them here; apply one to a student's invoice on Collect payments.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <Card>
          {!items ? <Loading /> : items.length === 0 ? <Empty>None defined yet.</Empty> : (
            <Table columns={['Name', 'Worth', 'Notes']}>
              {items.map((s) => (
                <tr key={s.id}>
                  <Cell className="font-medium">{s.name}</Cell>
                  <Cell><Badge tone="blue">{s.type === 'percentage' ? `${Number(s.value)}% off` : `${nairaAmount(Number(s.value))} off`}</Badge></Cell>
                  <Cell className="text-sm text-ink-muted">{s.description}</Cell>
                </tr>
              ))}
            </Table>
          )}
          <Link href="/finance/collect" className="mt-4 inline-block text-sm text-brand underline">Apply one to a student’s invoice</Link>
        </Card>
        <Card title="New scholarship or discount">
          <form onSubmit={create} className="space-y-3">
            <Field label="Name"><input className={inputClass} required placeholder="e.g. Sibling discount" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Type">
              <select className={inputClass} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as 'percentage' | 'fixed' })}>
                <option value="percentage">Percentage of the invoice</option>
                <option value="fixed">Fixed amount (₦)</option>
              </select>
            </Field>
            <Field label={form.type === 'percentage' ? 'Percentage' : 'Amount (₦)'}><input className={inputClass} type="number" min={0.01} step="0.01" required value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} /></Field>
            <Field label="Who qualifies (optional)"><textarea className={inputClass} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
            <Button type="submit" disabled={busy}>Create</Button>
          </form>
        </Card>
      </div>
    </PageShell>
  );
}
