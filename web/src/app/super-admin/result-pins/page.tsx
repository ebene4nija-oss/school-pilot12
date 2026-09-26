'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { ApiError, fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
  nairaAmount,
} from '@/components/Page';

/**
 * Result-checker PINs: how SchoolPilot charges at result generation (doc §13).
 *
 * Three jobs — the wholesale rate card every school buys against, a negotiated
 * rate for one school, and PINs issued to a school that paid by bank transfer
 * or cash. Most schools in this market pay offline, so the grant form is the
 * common path, not an exception.
 */

interface Tier {
  id: number;
  min_quantity: number;
  unit_price: string;
  is_active: boolean;
}

interface SchoolRate {
  id: number;
  school_id: number;
  unit_price: string;
  notes: string | null;
  is_active: boolean;
  updated_at: string;
  school: { id: number; name: string; subdomain: string } | null;
  set_by: { id: number; name: string } | null;
}

interface Batch {
  id: number;
  reference: string;
  quantity: number;
  unit_price: string;
  total_amount: string;
  source: string;
  gateway: string | null;
  status: string;
  notes: string | null;
  created_at: string;
  school: { id: number; name: string; subdomain: string } | null;
}

interface SchoolOption {
  id: number;
  name: string;
  subdomain: string;
}

interface Grant {
  message: string;
  batch: { reference: string; quantity: number; total_amount: string };
  pins: { serial: string; pin: string }[];
}

/** The first validation message, or the API's own message. */
function messageOf(err: unknown, fallback: string): string {
  if (err instanceof ApiError) {
    const errors = (err.payload as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors && Object.values(errors)[0]?.[0];
    return first ?? err.message;
  }
  return err instanceof Error ? err.message : fallback;
}

export default function ResultPinsPage() {
  const [tiers, setTiers] = useState<Tier[]>([]);
  const [rates, setRates] = useState<SchoolRate[]>([]);
  const [batches, setBatches] = useState<Batch[]>([]);
  const [totals, setTotals] = useState({ batches: 0, pins_issued: 0, revenue: 0 });
  const [schools, setSchools] = useState<SchoolOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [tierForm, setTierForm] = useState({ min_quantity: '', unit_price: '' });
  const [rateForm, setRateForm] = useState({ school_id: '', unit_price: '', notes: '' });
  const [grantForm, setGrantForm] = useState({ school_id: '', quantity: '', unit_price: '', gateway: 'bank_transfer', notes: '' });
  const [grant, setGrant] = useState<Grant | null>(null);

  const load = useCallback(async () => {
    const [t, r, b, s] = await Promise.all([
      fetchApi<{ tiers: Tier[] }>('/platform/result-pins/price-tiers'),
      fetchApi<{ rates: SchoolRate[] }>('/platform/result-pins/school-rates'),
      fetchApi<{ totals: typeof totals; batches: Batch[] }>('/platform/result-pins/batches'),
      fetchApi<{ data: SchoolOption[] }>('/platform/schools'),
    ]);

    setTiers(t.tiers);
    setRates(r.rates);
    setBatches(b.batches);
    setTotals(b.totals);
    setSchools(s.data);
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(messageOf(err, 'Could not load result PINs.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function run(action: () => Promise<string>) {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      setNotice(await action());
      await load();
    } catch (err) {
      setError(messageOf(err, 'That change could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  function saveTier(e: React.FormEvent) {
    e.preventDefault();
    run(async () => {
      await fetchApi('/platform/result-pins/price-tiers', {
        method: 'POST',
        body: JSON.stringify({ min_quantity: Number(tierForm.min_quantity), unit_price: Number(tierForm.unit_price) }),
      });
      setTierForm({ min_quantity: '', unit_price: '' });
      return 'Rate card updated. Batches already bought keep the price they were bought at.';
    });
  }

  function toggleTier(tier: Tier) {
    run(async () => {
      await fetchApi('/platform/result-pins/price-tiers', {
        method: 'POST',
        body: JSON.stringify({ min_quantity: tier.min_quantity, unit_price: Number(tier.unit_price), is_active: !tier.is_active }),
      });
      return tier.is_active ? 'Band withdrawn from the rate card.' : 'Band restored to the rate card.';
    });
  }

  function saveRate(e: React.FormEvent) {
    e.preventDefault();
    run(async () => {
      const res = await fetchApi<{ message: string }>('/platform/result-pins/school-rates', {
        method: 'POST',
        body: JSON.stringify({
          school_id: Number(rateForm.school_id),
          unit_price: Number(rateForm.unit_price),
          notes: rateForm.notes,
        }),
      });
      setRateForm({ school_id: '', unit_price: '', notes: '' });
      return res.message;
    });
  }

  function liftRate(rate: SchoolRate) {
    if (!window.confirm(`Put ${rate.school?.name ?? 'this school'} back on the published rate card?`)) return;
    run(async () => {
      const res = await fetchApi<{ message: string }>(`/platform/result-pins/school-rates/${rate.school_id}`, {
        method: 'DELETE',
      });
      return res.message;
    });
  }

  function submitGrant(e: React.FormEvent) {
    e.preventDefault();
    const school = schools.find((s) => String(s.id) === grantForm.school_id);
    if (!window.confirm(`Issue ${grantForm.quantity} PINs to ${school?.name ?? 'this school'}? This creates sellable stock immediately.`)) return;

    run(async () => {
      const res = await fetchApi<Grant>('/platform/result-pins/grant', {
        method: 'POST',
        body: JSON.stringify({
          school_id: Number(grantForm.school_id),
          quantity: Number(grantForm.quantity),
          unit_price: grantForm.unit_price === '' ? null : Number(grantForm.unit_price),
          gateway: grantForm.gateway,
          notes: grantForm.notes,
        }),
      });
      setGrant(res);
      setGrantForm({ school_id: '', quantity: '', unit_price: '', gateway: 'bank_transfer', notes: '' });
      return res.message;
    });
  }

  function downloadPins() {
    if (!grant) return;
    const csv = ['serial,pin', ...grant.pins.map((p) => `${p.serial},${p.pin}`)].join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `${grant.batch.reference}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  const schoolSelect = (value: string, onChange: (v: string) => void) => (
    <select required value={value} onChange={(e) => onChange(e.target.value)} className={inputClass}>
      <option value="">Choose a school</option>
      {schools.map((s) => (
        <option key={s.id} value={s.id}>
          {s.name} ({s.subdomain})
        </option>
      ))}
    </select>
  );

  return (
    <PageShell
      title="Result PINs"
      subtitle="What schools pay SchoolPilot at result time: the rate card, agreed school rates, and PINs for schools that paid offline."
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-3">
            <StatTile label="PIN revenue" value={nairaAmount(totals.revenue)} tone="green" hint="Paid batches, latest 200" />
            <StatTile label="PINs issued" value={totals.pins_issued.toLocaleString('en-NG')} />
            <StatTile label="Batches" value={totals.batches.toLocaleString('en-NG')} />
          </div>

          {grant && (
            <Card
              title={`${grant.batch.quantity} PINs issued · ${grant.batch.reference}`}
              description="These codes are shown once. Download them now; the school can also re-read unsold codes from its own batch view."
              className="ring-2 ring-accent"
            >
              <div className="flex gap-2">
                <Button onClick={downloadPins}>Download CSV</Button>
                <Button variant="secondary" onClick={() => setGrant(null)}>
                  Done
                </Button>
              </div>
            </Card>
          )}

          <Card
            title="Issue PINs for an offline payment"
            description="For a school that paid by bank transfer or cash. Leave the unit price empty to charge the school's agreed rate, or the rate card."
          >
            <form onSubmit={submitGrant} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="School">{schoolSelect(grantForm.school_id, (v) => setGrantForm((f) => ({ ...f, school_id: v })))}</Field>
              <Field label="Number of PINs">
                <input
                  required
                  type="number"
                  min={1}
                  step={1}
                  value={grantForm.quantity}
                  onChange={(e) => setGrantForm((f) => ({ ...f, quantity: e.target.value }))}
                  className={inputClass}
                />
              </Field>
              <Field label="Unit price (₦, optional)">
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  value={grantForm.unit_price}
                  onChange={(e) => setGrantForm((f) => ({ ...f, unit_price: e.target.value }))}
                  className={inputClass}
                  placeholder="Agreed rate or rate card"
                />
              </Field>
              <Field label="How they paid">
                <select
                  value={grantForm.gateway}
                  onChange={(e) => setGrantForm((f) => ({ ...f, gateway: e.target.value }))}
                  className={inputClass}
                >
                  <option value="bank_transfer">Bank transfer</option>
                  <option value="cash">Cash</option>
                </select>
              </Field>
              <div className="sm:col-span-2">
                <Field label="Reference / notes (required)">
                  <input
                    required
                    maxLength={500}
                    value={grantForm.notes}
                    onChange={(e) => setGrantForm((f) => ({ ...f, notes: e.target.value }))}
                    className={inputClass}
                    placeholder="e.g. GTB transfer 26 Sep, ref 00123456"
                  />
                </Field>
              </div>
              <div className="flex items-end">
                <Button type="submit" variant="accent" disabled={busy}>
                  Issue PINs
                </Button>
              </div>
            </form>
          </Card>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card title="Rate card" description="Price per PIN by order size. The largest band a school's order reaches applies.">
              {tiers.length === 0 ? (
                <Empty>No rate card published. Schools cannot buy PINs online until one is.</Empty>
              ) : (
                <Table columns={['From', 'Per PIN', 'Status', '']}>
                  {tiers.map((tier) => (
                    <tr key={tier.id}>
                      <Cell>{tier.min_quantity.toLocaleString('en-NG')}+ PINs</Cell>
                      <Cell className="font-medium">{nairaAmount(Number(tier.unit_price))}</Cell>
                      <Cell>
                        <Badge tone={tier.is_active ? 'green' : 'slate'}>{tier.is_active ? 'On sale' : 'Withdrawn'}</Badge>
                      </Cell>
                      <Cell className="text-right">
                        <button
                          disabled={busy}
                          onClick={() => toggleTier(tier)}
                          className="text-xs font-medium text-info underline hover:text-brand disabled:opacity-60"
                        >
                          {tier.is_active ? 'Withdraw' : 'Restore'}
                        </button>
                      </Cell>
                    </tr>
                  ))}
                </Table>
              )}
              <form onSubmit={saveTier} className="mt-4 grid grid-cols-[1fr_1fr_auto] items-end gap-2">
                <Field label="From (PINs)">
                  <input
                    required
                    type="number"
                    min={1}
                    step={1}
                    value={tierForm.min_quantity}
                    onChange={(e) => setTierForm((f) => ({ ...f, min_quantity: e.target.value }))}
                    className={inputClass}
                  />
                </Field>
                <Field label="Per PIN (₦)">
                  <input
                    required
                    type="number"
                    min={0}
                    step="0.01"
                    value={tierForm.unit_price}
                    onChange={(e) => setTierForm((f) => ({ ...f, unit_price: e.target.value }))}
                    className={inputClass}
                  />
                </Field>
                <Button type="submit" variant="secondary" disabled={busy}>
                  Save band
                </Button>
              </form>
              <p className="mt-2 text-xs text-ink-muted">Saving a band that already exists changes its price.</p>
            </Card>

            <Card title="Agreed school rates" description="A flat per-PIN price for one school, at any order size.">
              {rates.length === 0 ? (
                <Empty>Every school buys at the rate card.</Empty>
              ) : (
                <ul className="divide-y divide-line-soft">
                  {rates.map((rate) => (
                    <li key={rate.id} className="flex items-start justify-between gap-3 py-3">
                      <div>
                        <p className="text-sm font-medium text-brand">
                          {rate.school ? (
                            <Link href={`/super-admin/schools/${rate.school.id}`} className="hover:underline">
                              {rate.school.name}
                            </Link>
                          ) : (
                            `School #${rate.school_id}`
                          )}{' '}
                          · {nairaAmount(Number(rate.unit_price))} per PIN
                        </p>
                        {rate.notes && <p className="text-xs text-ink-muted">{rate.notes}</p>}
                        {rate.set_by && <p className="text-xs text-ink-faint">Agreed by {rate.set_by.name}</p>}
                      </div>
                      <button
                        disabled={busy}
                        onClick={() => liftRate(rate)}
                        className="shrink-0 text-xs font-medium text-negative underline hover:opacity-80 disabled:opacity-60"
                      >
                        Lift
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              <form onSubmit={saveRate} className="mt-4 grid gap-2">
                <Field label="School">{schoolSelect(rateForm.school_id, (v) => setRateForm((f) => ({ ...f, school_id: v })))}</Field>
                <div className="grid grid-cols-2 gap-2">
                  <Field label="Per PIN (₦)">
                    <input
                      required
                      type="number"
                      min={0}
                      step="0.01"
                      value={rateForm.unit_price}
                      onChange={(e) => setRateForm((f) => ({ ...f, unit_price: e.target.value }))}
                      className={inputClass}
                    />
                  </Field>
                  <Field label="Why (required)">
                    <input
                      required
                      maxLength={500}
                      value={rateForm.notes}
                      onChange={(e) => setRateForm((f) => ({ ...f, notes: e.target.value }))}
                      className={inputClass}
                      placeholder="e.g. 3-school group deal"
                    />
                  </Field>
                </div>
                <div>
                  <Button type="submit" variant="secondary" disabled={busy}>
                    Agree rate
                  </Button>
                </div>
              </form>
            </Card>
          </div>

          <Card title="Batches" description="Every PIN batch across schools, newest first.">
            {batches.length === 0 ? (
              <Empty>No PINs have been bought or issued yet.</Empty>
            ) : (
              <Table columns={['Date', 'School', 'Reference', 'PINs', 'Total', 'How paid', 'Status']}>
                {batches.map((batch) => (
                  <tr key={batch.id}>
                    <Cell className="whitespace-nowrap text-xs">
                      {new Date(batch.created_at).toLocaleDateString('en-NG', { dateStyle: 'medium' })}
                    </Cell>
                    <Cell>
                      {batch.school ? (
                        <Link href={`/super-admin/schools/${batch.school.id}`} className="text-brand hover:underline">
                          {batch.school.name}
                        </Link>
                      ) : (
                        '—'
                      )}
                    </Cell>
                    <Cell className="font-mono text-xs">{batch.reference}</Cell>
                    <Cell>{batch.quantity.toLocaleString('en-NG')}</Cell>
                    <Cell className="font-medium">{nairaAmount(Number(batch.total_amount))}</Cell>
                    <Cell className="text-xs">
                      {batch.source === 'manual_grant' ? `Offline (${batch.gateway ?? 'transfer'})` : (batch.gateway ?? 'Online')}
                    </Cell>
                    <Cell>
                      <Badge tone={batch.status === 'active' ? 'green' : 'slate'}>{batch.status}</Badge>
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
