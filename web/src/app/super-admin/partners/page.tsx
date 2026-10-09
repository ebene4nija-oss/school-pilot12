'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * Partner programme (operator side).
 *
 * Partners earn the published 30% lifetime commission on every term bill their
 * schools pay. A rate agreed with one partner is set here and nowhere else: it
 * is never shown to the partner or on the website, so the "Rate" column and the
 * field below are for the operator's eyes only. A new rate applies to bills paid
 * from that moment; commissions already earned keep the rate they were earned at.
 *
 * Payouts are made outside SchoolPilot (bank transfer); recording one settles
 * everything the partner is owed so far.
 */

interface PartnerRow {
  id: number;
  code: string;
  name: string;
  email: string;
  phone: string | null;
  status: 'pending' | 'active' | 'suspended';
  pitch: string | null;
  rate_percent: number;
  negotiated: boolean;
  has_bank_details: boolean;
  schools: number;
  owed_naira: number;
  paid_naira: number;
}
interface SchoolOption {
  id: number;
  name: string;
}
interface Login {
  email: string;
  temporary_password: string;
  email_sent: boolean;
}

const TONE = { pending: 'amber', active: 'green', suspended: 'red' } as const;

export default function PartnersPage() {
  const [rows, setRows] = useState<PartnerRow[]>([]);
  const [defaultRate, setDefaultRate] = useState(30);
  const [schools, setSchools] = useState<SchoolOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [login, setLogin] = useState<{ name: string; login: Login } | null>(null);
  const [busy, setBusy] = useState<number | 'new' | 'credit' | null>(null);
  const [rates, setRates] = useState<Record<number, string>>({});
  const [form, setForm] = useState({ name: '', email: '', phone: '', rate: '' });
  const [credit, setCredit] = useState({ school_id: '', partner_id: '' });

  const load = useCallback(async () => {
    const [partners, list] = await Promise.all([
      fetchApi<{ default_rate_percent: number; data: PartnerRow[] }>('/platform/partners'),
      fetchApi<{ data: SchoolOption[] }>('/platform/schools'),
    ]);
    setRows(partners.data);
    setDefaultRate(partners.default_rate_percent);
    setSchools(list.data);
    setRates(Object.fromEntries(partners.data.map((p) => [p.id, String(p.rate_percent)])));
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(errorText(err, 'Could not load partners.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function run(id: PartnerRow['id'] | 'new' | 'credit', job: () => Promise<string | void>) {
    setBusy(id);
    setError(null);
    setNotice(null);
    try {
      const message = await job();
      if (message) setNotice(message);
      await load();
    } catch (err) {
      setError(errorText(err, 'That change could not be saved.'));
    } finally {
      setBusy(null);
    }
  }

  const addPartner = (e: React.FormEvent) => {
    e.preventDefault();
    return run('new', async () => {
      const res = await fetchApi<{ login: Login | null }>('/platform/partners', {
        method: 'POST',
        body: JSON.stringify({
          name: form.name,
          email: form.email,
          phone: form.phone || null,
          commission_rate_percent: form.rate === '' ? null : Number(form.rate),
        }),
      });
      if (res.login) setLogin({ name: form.name, login: res.login });
      setForm({ name: '', email: '', phone: '', rate: '' });
      return `${form.name} added.`;
    });
  };

  const setStatus = (p: PartnerRow, status: PartnerRow['status']) =>
    run(p.id, async () => {
      const res = await fetchApi<{ login: Login | null }>(`/platform/partners/${p.id}`, { method: 'PUT', body: JSON.stringify({ status }) });
      if (res.login) setLogin({ name: p.name, login: res.login });
      return `${p.name} is now ${status}.`;
    });

  const saveRate = (p: PartnerRow) =>
    run(p.id, async () => {
      const value = rates[p.id]?.trim();
      await fetchApi(`/platform/partners/${p.id}`, {
        method: 'PUT',
        body: JSON.stringify({ commission_rate_percent: value === '' || Number(value) === defaultRate ? null : Number(value) }),
      });
      return `Rate saved for ${p.name}. It applies to bills paid from now on.`;
    });

  const payOut = (p: PartnerRow) => {
    const reference = window.prompt(`Pay ${p.name} ${nairaAmount(p.owed_naira)}. Bank transfer reference (optional):`, '');
    if (reference === null) return;
    return run(p.id, async () => {
      const res = await fetchApi<{ message: string }>(`/platform/partners/${p.id}/payouts`, {
        method: 'POST',
        body: JSON.stringify({ paid_on: new Date().toISOString().slice(0, 10), reference: reference || null }),
      });
      return res.message;
    });
  };

  const creditSchool = (e: React.FormEvent) => {
    e.preventDefault();
    return run('credit', async () => {
      await fetchApi(`/platform/schools/${credit.school_id}/partner`, {
        method: 'PUT',
        body: JSON.stringify({ partner_id: credit.partner_id === '' ? null : Number(credit.partner_id) }),
      });
      setCredit({ school_id: '', partner_id: '' });
      return 'School credited.';
    });
  };

  const owed = rows.reduce((sum, p) => sum + p.owed_naira, 0);
  const paid = rows.reduce((sum, p) => sum + p.paid_naira, 0);

  return (
    <PageShell
      title="Partners"
      subtitle={`Partners earn a lifetime ${defaultRate}% commission on every term bill their schools pay. Rates set here are private.`}
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}
      {login && (
        <div role="status" className="mb-6 rounded-lg border border-accent/40 bg-caution-soft px-4 py-3 text-sm text-ink">
          {login.login.email_sent ? (
            <>
              <p className="font-semibold">{login.name} has been emailed a link to set their password.</p>
              <p className="mt-1 text-ink-muted">
                Sent to <span className="font-mono">{login.login.email}</span>. The link expires in an hour; they can use “Forgot password” for a new one. Backup password, shown once:{' '}
                <span className="font-mono">{login.login.temporary_password}</span>
              </p>
            </>
          ) : (
            <>
              <p className="font-semibold">The email to {login.name} could not be sent. Pass these on yourself — shown once.</p>
              <p className="mt-1">
                Email <span className="font-mono">{login.login.email}</span> · temporary password{' '}
                <span className="font-mono">{login.login.temporary_password}</span>
              </p>
              <p className="mt-1 text-ink-muted">They sign in at the same address as the operator console and must change the password on first sign-in.</p>
            </>
          )}
          <button type="button" onClick={() => setLogin(null)} className="mt-2 text-xs underline">
            Dismiss
          </button>
        </div>
      )}

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatTile label="Partners" value={rows.filter((p) => p.status === 'active').length} hint={`${rows.filter((p) => p.status === 'pending').length} awaiting approval`} />
        <StatTile label="Owed now" value={nairaAmount(owed)} tone="amber" hint="Earned, not yet paid out" />
        <StatTile label="Paid out" value={nairaAmount(paid)} tone="green" />
      </div>

      {loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>No partners yet. Applications from the website&apos;s partner page appear here for approval.</Empty>
      ) : (
        <Card className="mb-6">
          <Table columns={['Partner', 'Status', 'Schools', 'Owed', 'Paid', 'Rate (private)', '']}>
            {rows.map((p) => (
              <tr key={p.id}>
                <Cell>
                  <p className="font-medium">{p.name}</p>
                  <p className="text-xs text-ink-muted">
                    {p.email}
                    {p.phone ? ` · ${p.phone}` : ''} · code {p.code}
                  </p>
                  {p.pitch && <p className="mt-1 max-w-xs text-xs text-ink-muted">{p.pitch}</p>}
                </Cell>
                <Cell>
                  <Badge tone={TONE[p.status]}>{p.status}</Badge>
                </Cell>
                <Cell>{p.schools}</Cell>
                <Cell>{nairaAmount(p.owed_naira)}</Cell>
                <Cell>{nairaAmount(p.paid_naira)}</Cell>
                <Cell>
                  <div className="flex items-center gap-2">
                    <input
                      aria-label={`Commission percent for ${p.name}`}
                      className={`${inputClass} w-20`}
                      inputMode="decimal"
                      value={rates[p.id] ?? ''}
                      onChange={(e) => setRates((r) => ({ ...r, [p.id]: e.target.value }))}
                    />
                    <span className="text-xs text-ink-muted">%</span>
                    {p.negotiated && <Badge tone="blue">negotiated</Badge>}
                    <Button variant="secondary" disabled={busy === p.id || Number(rates[p.id]) === p.rate_percent} onClick={() => saveRate(p)}>
                      Save
                    </Button>
                  </div>
                </Cell>
                <Cell>
                  <div className="flex flex-wrap gap-2">
                    {p.status !== 'active' && (
                      <Button variant="accent" disabled={busy === p.id} onClick={() => setStatus(p, 'active')}>
                        {p.status === 'pending' ? 'Approve' : 'Reactivate'}
                      </Button>
                    )}
                    {p.status === 'active' && (
                      <Button variant="secondary" disabled={busy === p.id} onClick={() => setStatus(p, 'suspended')}>
                        Suspend
                      </Button>
                    )}
                    {p.owed_naira > 0 && (
                      <Button disabled={busy === p.id} onClick={() => payOut(p)}>
                        Record payout
                      </Button>
                    )}
                  </div>
                  {p.owed_naira > 0 && !p.has_bank_details && <p className="mt-1 text-xs text-caution">No bank details yet.</p>}
                </Cell>
              </tr>
            ))}
          </Table>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Add a partner" description="They are approved at once and given a sign-in. Leave the rate empty for the public rate.">
          <form onSubmit={addPartner} className="space-y-3">
            <Field label="Name">
              <input required className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field label="Email">
              <input required type="email" className={inputClass} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </Field>
            <Field label="Phone">
              <input className={inputClass} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            </Field>
            <Field label={`Private rate % (public is ${defaultRate})`}>
              <input className={inputClass} inputMode="decimal" value={form.rate} onChange={(e) => setForm({ ...form, rate: e.target.value })} />
            </Field>
            <Button type="submit" disabled={busy === 'new'}>
              Add partner
            </Button>
          </form>
        </Card>

        <Card title="Credit a school to a partner" description="For a school that came in without the partner's link. Its paid bills earn commission from now on. Choose “No partner” to clear it.">
          <form onSubmit={creditSchool} className="space-y-3">
            <Field label="School">
              <select required className={inputClass} value={credit.school_id} onChange={(e) => setCredit({ ...credit, school_id: e.target.value })}>
                <option value="">Choose…</option>
                {schools.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Partner">
              <select className={inputClass} value={credit.partner_id} onChange={(e) => setCredit({ ...credit, partner_id: e.target.value })}>
                <option value="">No partner</option>
                {rows
                  .filter((p) => p.status === 'active')
                  .map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
              </select>
            </Field>
            <Button type="submit" disabled={busy === 'credit' || !credit.school_id}>
              Save
            </Button>
          </form>
        </Card>
      </div>
    </PageShell>
  );
}
