'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * The partner's own page: their referral link, the schools credited to them,
 * and what they have earned and been paid.
 *
 * Money here is the partner's commission only. A school's bill is never shown,
 * by design (see PartnerPortalController).
 */

interface Summary {
  partner: { name: string; email: string; status: string; code: string; has_bank_details: boolean };
  referral_link: string;
  owed_naira: number;
  paid_naira: number;
  lifetime_naira: number;
  schools: number;
}
interface SchoolRow {
  name: string;
  status: string;
  paid_terms: number;
  earned_naira: number;
}
interface CommissionRow {
  school: string | null;
  amount_naira: number;
  status: 'owed' | 'paid';
  earned_on: string;
}
interface PayoutRow {
  amount_naira: number;
  paid_on: string;
  reference: string | null;
}
interface Bank {
  bank_name: string | null;
  account_name: string | null;
  account_number_masked: string | null;
}

export default function PartnerPage() {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [schools, setSchools] = useState<SchoolRow[]>([]);
  const [commissions, setCommissions] = useState<CommissionRow[]>([]);
  const [payouts, setPayouts] = useState<PayoutRow[]>([]);
  const [bank, setBank] = useState<Bank | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ bank_name: '', account_number: '', account_name: '' });

  const load = useCallback(async () => {
    const [s, sc, c, p, b] = await Promise.all([
      fetchApi<Summary>('/partner/summary'),
      fetchApi<{ data: SchoolRow[] }>('/partner/schools'),
      fetchApi<{ data: CommissionRow[] }>('/partner/commissions'),
      fetchApi<{ data: PayoutRow[] }>('/partner/payouts'),
      fetchApi<Bank>('/partner/bank'),
    ]);
    setSummary(s);
    setSchools(sc.data);
    setCommissions(c.data);
    setPayouts(p.data);
    setBank(b);
    setForm((f) => ({ ...f, bank_name: b.bank_name ?? '', account_name: b.account_name ?? '' }));
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(errorText(err, 'Could not load your partner page.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function saveBank(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      await fetchApi('/partner/bank', { method: 'PUT', body: JSON.stringify(form) });
      setForm((f) => ({ ...f, account_number: '' }));
      setNotice('Bank details saved.');
      await load();
    } catch (err) {
      setError(errorText(err, 'Bank details could not be saved.'));
    } finally {
      setSaving(false);
    }
  }

  async function copyLink() {
    if (!summary) return;
    try {
      await navigator.clipboard.writeText(summary.referral_link);
      setNotice('Link copied.');
    } catch {
      setNotice('Select the link and copy it.');
    }
  }

  return (
    <PageShell title="My referrals" subtitle="Schools you bring to SchoolPilot, and the commission you earn on them for as long as they stay.">
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading || !summary ? (
        <Loading />
      ) : (
        <>
          {summary.partner.status !== 'active' && (
            <p className="mb-6 rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">
              Your partner account is {summary.partner.status}. New schools do not earn commission until it is active again.
            </p>
          )}

          <div className="mb-6 grid gap-4 sm:grid-cols-4">
            <StatTile label="Schools" value={summary.schools} />
            <StatTile label="Owed to you" value={nairaAmount(summary.owed_naira)} tone="amber" hint="Earned, not yet paid out" />
            <StatTile label="Paid to you" value={nairaAmount(summary.paid_naira)} tone="green" />
            <StatTile label="Earned in total" value={nairaAmount(summary.lifetime_naira)} />
          </div>

          <Card title="Your referral link" description="Share this link. A school that asks for a demo through it is credited to you, and you earn commission on every term it pays.">
            <div className="flex flex-wrap items-center gap-3">
              <input readOnly aria-label="Referral link" className={`${inputClass} max-w-md`} value={summary.referral_link} onFocus={(e) => e.currentTarget.select()} />
              <Button variant="secondary" onClick={copyLink}>
                Copy
              </Button>
              <span className="text-sm text-ink-muted">
                Code <span className="font-mono">{summary.partner.code}</span>
              </span>
            </div>
          </Card>

          <div className="mt-6 grid gap-6 lg:grid-cols-2">
            <Card title="Your schools">
              {schools.length === 0 ? (
                <Empty>No schools credited to you yet.</Empty>
              ) : (
                <Table columns={['School', 'Status', 'Terms paid', 'You earned']}>
                  {schools.map((s) => (
                    <tr key={s.name}>
                      <Cell>{s.name}</Cell>
                      <Cell>
                        <Badge tone={s.status === 'active' ? 'green' : 'slate'}>{s.status}</Badge>
                      </Cell>
                      <Cell>{s.paid_terms}</Cell>
                      <Cell>{nairaAmount(s.earned_naira)}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <Card title="Payout details" description="Where we send what you are owed. We show only the last four digits back.">
              <form onSubmit={saveBank} className="space-y-3">
                <Field label="Bank">
                  <input required className={inputClass} value={form.bank_name} onChange={(e) => setForm({ ...form, bank_name: e.target.value })} />
                </Field>
                <Field label={`Account number${bank?.account_number_masked ? ` (on file: ${bank.account_number_masked})` : ''}`}>
                  <input required inputMode="numeric" maxLength={10} className={inputClass} value={form.account_number} onChange={(e) => setForm({ ...form, account_number: e.target.value })} />
                </Field>
                <Field label="Account name">
                  <input required className={inputClass} value={form.account_name} onChange={(e) => setForm({ ...form, account_name: e.target.value })} />
                </Field>
                <Button type="submit" disabled={saving}>
                  {saving ? 'Saving…' : 'Save details'}
                </Button>
              </form>
            </Card>
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-2">
            <Card title="Commission earned">
              {commissions.length === 0 ? (
                <Empty>Commission appears here when a school you referred pays its term bill.</Empty>
              ) : (
                <Table columns={['Date', 'School', 'Amount', '']}>
                  {commissions.map((c, i) => (
                    <tr key={i}>
                      <Cell>{c.earned_on}</Cell>
                      <Cell>{c.school}</Cell>
                      <Cell>{nairaAmount(c.amount_naira)}</Cell>
                      <Cell>
                        <Badge tone={c.status === 'paid' ? 'green' : 'amber'}>{c.status}</Badge>
                      </Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <Card title="Payments to you">
              {payouts.length === 0 ? (
                <Empty>No payouts yet.</Empty>
              ) : (
                <Table columns={['Date', 'Amount', 'Reference']}>
                  {payouts.map((p, i) => (
                    <tr key={i}>
                      <Cell>{p.paid_on}</Cell>
                      <Cell>{nairaAmount(p.amount_naira)}</Cell>
                      <Cell>{p.reference ?? '—'}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          </div>
        </>
      )}
    </PageShell>
  );
}
