'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
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
 * Result-checker PINs, from the school's side.
 *
 * The API has had stock, counter sales, settings and a sales report since the
 * result checker was built; no screen used any of it, so a school could not
 * see its stock, sell a PIN to a parent at the bursary, or tell who had bought
 * one. SchoolPilot sells the school stock wholesale; the school sets its own
 * price to parents.
 */

type Inventory = {
  available: number;
  sold: number;
  used: number;
  void: number;
  overdraft: number;
  settings: { result_checker_enabled: boolean; retail_price: string | null };
};

type Pricing = {
  negotiated: boolean;
  unit_price: string | null;
  tiers: { min_quantity: number; unit_price: string }[];
  note: string;
};

type Batch = {
  id: number;
  reference: string;
  quantity: number;
  unit_price: number;
  total_amount: number;
  source: 'online' | 'manual_grant';
  status: 'pending' | 'active' | 'cancelled';
  paid_at: string | null;
  created_at: string | null;
  available: number;
  sold: number;
  used: number;
  void: number;
};

type LedgerPin = {
  serial: string;
  batch: string | null;
  origin: string;
  status: 'sold' | 'used' | 'void';
  channel: string | null;
  amount: number | null;
  sold_at: string | null;
  sold_to: string | null;
  student: string | null;
  admission_number: string | null;
  term: string | null;
  views_used: number;
  max_views: number;
  note: string | null;
};

type Ledger = { batches: Batch[]; pins: LedgerPin[]; meta: { total: number; current_page: number; last_page: number } };

type Tab = 'overview' | 'sell' | 'ledger';

const STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'slate' | 'blue'> = {
  sold: 'blue',
  used: 'green',
  void: 'red',
  active: 'green',
  pending: 'amber',
  cancelled: 'slate',
};

const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

/** The per-PIN price for an order of this size. */
function quote(pricing: Pricing | null, quantity: number): number | null {
  if (!pricing || quantity < 1) return null;
  if (pricing.negotiated && pricing.unit_price) return Number(pricing.unit_price);

  const band = [...pricing.tiers].sort((a, b) => b.min_quantity - a.min_quantity).find((t) => quantity >= t.min_quantity);

  return band ? Number(band.unit_price) : null;
}

export default function ResultPinsPage() {
  const [tab, setTab] = useState<Tab>('overview');
  const [inventory, setInventory] = useState<Inventory | null>(null);
  const [pricing, setPricing] = useState<Pricing | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [inv, price] = await Promise.all([
        fetchApi<Inventory>('/result-pins/inventory'),
        fetchApi<Pricing>('/result-pins/price-tiers').catch(() => null),
      ]);
      setInventory(inv);
      setPricing(price);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load PIN stock.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  return (
    <PageShell title="Result PINs" subtitle="Scratch-card PINs parents use to open a released result.">
      <ErrorNote message={error} />

      <div role="tablist" className="mb-6 flex flex-wrap gap-2">
        {([
          ['overview', 'Stock & pricing'],
          ['sell', 'Sell & print'],
          ['ledger', 'Who bought what'],
        ] as [Tab, string][]).map(([id, label]) => (
          <button
            key={id}
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`rounded-lg px-3.5 py-2 text-sm font-medium transition ${
              tab === id ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {loading || !inventory ? (
        loading ? <Loading /> : null
      ) : (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="In stock" value={inventory.available.toLocaleString()} tone={inventory.available === 0 ? 'red' : 'green'} hint="Ready to sell" />
            <StatTile label="Sold, not yet used" value={inventory.sold.toLocaleString()} tone="blue" />
            <StatTile label="Used" value={inventory.used.toLocaleString()} hint="Bound to a child and term" />
            <StatTile
              label="Owed to SchoolPilot"
              value={inventory.overdraft.toLocaleString()}
              tone={inventory.overdraft > 0 ? 'amber' : 'slate'}
              hint="PINs issued after stock ran out"
            />
          </div>

          {tab === 'overview' && <Overview inventory={inventory} pricing={pricing} onSaved={load} />}
          {tab === 'sell' && <Sell inventory={inventory} onSold={load} />}
          {tab === 'ledger' && <LedgerView />}
        </>
      )}
    </PageShell>
  );
}

function Overview({ inventory, pricing, onSaved }: { inventory: Inventory; pricing: Pricing | null; onSaved: () => void }) {
  const [enabled, setEnabled] = useState(inventory.settings.result_checker_enabled);
  const [retail, setRetail] = useState(inventory.settings.retail_price ?? '');
  const [quantity, setQuantity] = useState('100');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const qty = Number(quantity) || 0;
  const unit = quote(pricing, qty);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setMessage(null);

    try {
      await fetchApi('/result-pins/settings', {
        method: 'PUT',
        body: JSON.stringify({ result_checker_enabled: enabled, retail_price: retail === '' ? null : Number(retail) }),
      });
      setMessage({ ok: true, text: 'Saved.' });
      onSaved();
    } catch (err) {
      setMessage({ ok: false, text: errorText(err, 'Could not save.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Card title="Selling to parents" description="Whether parents need a PIN to open a released result, and what you charge them.">
        <form onSubmit={save} className="space-y-4">
          <label className="flex items-start gap-3">
            <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} className="mt-1 h-4 w-4 accent-brand" />
            <span className="text-sm text-ink">
              Parents need a PIN to view results
              <span className="block text-xs text-ink-muted">Off: released results are open to linked parents without a PIN.</span>
            </span>
          </label>
          <Field label="Your price to parents (₦ per PIN)">
            <input type="number" min={0} step="50" value={retail} onChange={(e) => setRetail(e.target.value)} className={inputClass} placeholder="e.g. 1000" />
          </Field>
          {message && <p className={`text-sm ${message.ok ? 'text-positive' : 'text-negative'}`}>{message.text}</p>}
          <Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save'}</Button>
        </form>
      </Card>

      <Card title="Buy PINs from SchoolPilot" description={pricing?.note}>
        {!pricing ? (
          <Empty>PIN pricing is not available right now.</Empty>
        ) : (
          <>
            {pricing.negotiated ? (
              <p className="mb-4 text-sm text-ink">
                Your agreed rate: <strong>{nairaAmount(Number(pricing.unit_price))}</strong> per PIN.
              </p>
            ) : pricing.tiers.length === 0 ? (
              <Empty>SchoolPilot has not published PIN prices yet.</Empty>
            ) : (
              <Table columns={['Order size', 'Price per PIN']}>
                {pricing.tiers.map((t) => (
                  <tr key={t.min_quantity}>
                    <Cell>{t.min_quantity.toLocaleString()}+ PINs</Cell>
                    <Cell>{nairaAmount(Number(t.unit_price))}</Cell>
                  </tr>
                ))}
              </Table>
            )}

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="How many PINs">
                <input type="number" min={1} value={quantity} onChange={(e) => setQuantity(e.target.value)} className={inputClass} />
              </Field>
              <div className="rounded-lg bg-surface-sunken px-4 py-3">
                <p className="text-xs text-ink-muted">Total</p>
                <p className="font-headline text-xl font-bold text-brand">{unit !== null ? nairaAmount(unit * qty) : '—'}</p>
                {unit !== null && <p className="text-xs text-ink-muted">{nairaAmount(unit)} each</p>}
              </div>
            </div>

            <p className="mt-4 rounded-lg bg-info-soft px-3 py-2 text-xs text-info">
              To buy, pay SchoolPilot by bank transfer and send the proof of payment to your SchoolPilot contact. The
              PINs appear here as soon as the payment is confirmed.
            </p>
          </>
        )}
      </Card>
    </div>
  );
}

function Sell({ inventory, onSold }: { inventory: Inventory; onSold: () => void }) {
  const [amount, setAmount] = useState(inventory.settings.retail_price ?? '');
  const [buyer, setBuyer] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sold, setSold] = useState<{ serial: string; pin: string; amount: string } | null>(null);

  const [batches, setBatches] = useState<Batch[]>([]);
  const [revealed, setRevealed] = useState<{ reference: string; pins: { serial: string; pin: string }[] } | null>(null);

  useEffect(() => {
    // Only the batch counts are wanted here; filtering the PIN rows to voided
    // ones keeps that half of the response small.
    fetchApi<Ledger>('/result-pins/ledger?status=void')
      .then((res) => setBatches(res.batches.filter((b) => b.status === 'active' && b.available > 0)))
      .catch(() => setBatches([]));
  }, []);

  const sell = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setSold(null);

    try {
      const res = await fetchApi<{ serial: string; pin: string; amount: string }>('/result-pins/counter-sale', {
        method: 'POST',
        body: JSON.stringify({ amount: Number(amount), note: buyer.trim() || undefined }),
      });
      setSold(res);
      setBuyer('');
      onSold();
    } catch (err) {
      setError(errorText(err, 'That sale could not be recorded.'));
    } finally {
      setBusy(false);
    }
  };

  const reveal = async (batch: Batch) => {
    if (!window.confirm(`Show all ${batch.available} unsold PINs in ${batch.reference}? This is recorded in the audit log.`)) return;

    setError(null);

    try {
      const res = await fetchApi<{ batch_reference: string; pins: { serial: string; pin: string }[] }>(`/result-pins/batches/${batch.id}/reveal`);
      setRevealed({ reference: res.batch_reference, pins: res.pins });
    } catch (err) {
      setError(errorText(err, 'Could not open that batch.'));
    }
  };

  return (
    <div className="space-y-6">
      <ErrorNote message={error} />

      <Card title="Sell one PIN at the bursary" description="Take payment, record it here, and give the parent the code shown.">
        <form onSubmit={sell} className="grid gap-4 sm:grid-cols-3">
          <Field label="Amount received (₦)">
            <input required type="number" min={0} value={amount} onChange={(e) => setAmount(e.target.value)} className={inputClass} />
          </Field>
          <Field label="Sold to (name or phone, optional)">
            <input value={buyer} onChange={(e) => setBuyer(e.target.value)} className={inputClass} placeholder="e.g. Mrs Okeke, 0803…" maxLength={255} />
          </Field>
          <div className="flex items-end">
            <Button type="submit" disabled={busy || inventory.available === 0} className="w-full">
              {inventory.available === 0 ? 'No PINs in stock' : busy ? 'Recording…' : 'Record sale'}
            </Button>
          </div>
        </form>

        {sold && (
          <div className="mt-5 rounded-xl border-2 border-dashed border-brand/40 p-5 text-center">
            <p className="text-xs uppercase tracking-wide text-ink-muted">Give this PIN to the parent</p>
            <p className="font-headline my-2 font-mono text-3xl font-bold tracking-widest text-brand">{sold.pin}</p>
            <p className="text-xs text-ink-muted">
              Serial {sold.serial} · {nairaAmount(Number(sold.amount))}
            </p>
          </div>
        )}
      </Card>

      <Card title="Print scratch cards" description="Show every unsold PIN in a batch, to print and sell as cards. Each viewing is logged.">
        {batches.length === 0 ? (
          <Empty>No batch has unsold PINs.</Empty>
        ) : (
          <Table columns={['Batch', 'Bought', 'Unsold', '']}>
            {batches.map((b) => (
              <tr key={b.id}>
                <Cell className="font-mono text-xs">{b.reference}</Cell>
                <Cell>{date(b.paid_at ?? b.created_at)}</Cell>
                <Cell>{b.available.toLocaleString()}</Cell>
                <Cell>
                  <Button variant="secondary" onClick={() => reveal(b)}>Show PINs</Button>
                </Cell>
              </tr>
            ))}
          </Table>
        )}

        {revealed && (
          <div className="mt-5">
            <div className="mb-3 flex items-center justify-between print:hidden">
              <p className="text-sm font-semibold text-ink">{revealed.pins.length} PINs in {revealed.reference}</p>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => window.print()}>Print</Button>
                <Button variant="secondary" onClick={() => setRevealed(null)}>Hide</Button>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
              {revealed.pins.map((p) => (
                <div key={p.serial} className="rounded-lg border border-dashed border-line p-3 text-center">
                  <p className="font-mono text-base font-bold tracking-wider text-ink">{p.pin}</p>
                  <p className="text-[10px] text-ink-faint">{p.serial}</p>
                </div>
              ))}
            </div>
          </div>
        )}
      </Card>
    </div>
  );
}

function LedgerView() {
  const [ledger, setLedger] = useState<Ledger | null>(null);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [batchId, setBatchId] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (batchId) params.set('batch_id', batchId);
    if (search.trim().length >= 2) params.set('search', search.trim());

    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const res = await fetchApi<Ledger>(`/result-pins/ledger?${params}`);
        if (!cancelled) {
          setLedger(res);
          setError(null);
        }
      } catch (err) {
        if (!cancelled) setError(errorText(err, 'Could not load the ledger.'));
      }
    }, search ? 250 : 0);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [status, search, batchId]);

  if (!ledger) return error ? <ErrorNote message={error} /> : <Loading />;

  return (
    <div className="space-y-6">
      <ErrorNote message={error} />

      <Card title="Batches bought" description="Every purchase from SchoolPilot and how far through it you are.">
        {ledger.batches.length === 0 ? (
          <Empty>No PINs bought yet.</Empty>
        ) : (
          <Table columns={['Batch', 'Date', 'PINs', 'Paid', 'Status', 'Unsold', 'Sold', 'Used']}>
            {ledger.batches.map((b) => (
              <tr key={b.id}>
                <Cell className="font-mono text-xs">{b.reference}</Cell>
                <Cell>{date(b.paid_at ?? b.created_at)}</Cell>
                <Cell>{b.quantity.toLocaleString()}</Cell>
                <Cell>{nairaAmount(b.total_amount)}</Cell>
                <Cell>
                  <Badge tone={STATUS_TONE[b.status] ?? 'slate'}>{b.status === 'active' ? 'paid' : b.status}</Badge>
                </Cell>
                <Cell>{b.available}</Cell>
                <Cell>{b.sold}</Cell>
                <Cell>{b.used}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card title="PINs sold" description="Who bought each PIN and, once used, whose result it opened.">
        <div className="mb-4 flex flex-wrap gap-3">
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Serial, buyer, student or admission no."
            className={`${inputClass} max-w-xs`}
          />
          <select value={status} onChange={(e) => setStatus(e.target.value)} className={`${inputClass} max-w-[10rem]`}>
            <option value="">All</option>
            <option value="sold">Sold, unused</option>
            <option value="used">Used</option>
            <option value="void">Voided</option>
          </select>
          <select value={batchId} onChange={(e) => setBatchId(e.target.value)} className={`${inputClass} max-w-[16rem]`}>
            <option value="">Every batch</option>
            {ledger.batches.map((b) => (
              <option key={b.id} value={b.id}>{b.reference}</option>
            ))}
          </select>
          <span className="self-center text-xs text-ink-muted">{ledger.meta.total} PINs</span>
        </div>

        {ledger.pins.length === 0 ? (
          <Empty>No PINs match.</Empty>
        ) : (
          <Table columns={['Serial', 'Sold', 'How', 'Amount', 'Buyer', 'Used for', 'Views', 'Status']}>
            {ledger.pins.map((p) => (
              <tr key={p.serial}>
                <Cell className="font-mono text-xs">{p.serial}</Cell>
                <Cell>{date(p.sold_at)}</Cell>
                <Cell className="capitalize">{p.channel ?? p.origin}</Cell>
                <Cell>{p.amount !== null ? nairaAmount(p.amount) : '—'}</Cell>
                <Cell>{p.sold_to ?? p.note ?? '—'}</Cell>
                <Cell>
                  {p.student ? (
                    <>
                      <span className="font-medium text-ink">{p.student}</span>
                      <span className="block text-xs text-ink-muted">
                        {[p.admission_number, p.term].filter(Boolean).join(' · ')}
                      </span>
                    </>
                  ) : (
                    '—'
                  )}
                </Cell>
                <Cell>{p.status === 'used' ? `${p.views_used}/${p.max_views}` : '—'}</Cell>
                <Cell>
                  <Badge tone={STATUS_TONE[p.status] ?? 'slate'}>{p.status}</Badge>
                </Cell>
              </tr>
            ))}
          </Table>
        )}
        {ledger.meta.last_page > 1 && (
          <p className="mt-3 text-xs text-ink-muted">Showing the 50 most recent. Search or filter to find older sales.</p>
        )}
      </Card>
    </div>
  );
}
