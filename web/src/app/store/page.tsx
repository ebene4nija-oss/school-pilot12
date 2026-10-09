'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import { roleFromCookie } from '@/components/Sidebar';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * The school store: uniforms, books and consumables handed to students.
 * Stock in, hand-overs out, and each hand-over confirmed received by a parent.
 * Charged items go onto the student's term invoice in full; the school
 * decides per item whether it is charged at all.
 *
 * Optional: off until the school admin switches it on here.
 */

interface Item {
  id: number; name: string; variant: string | null; label: string; category: string;
  unit_price: string | number; charge_to_fees: boolean; quantity_on_hand: number; reorder_level: number;
  is_active: boolean; low_stock: boolean;
}
interface PackItem { item_id: number; label: string; quantity: number; unit_price: string | number; charge_to_fees: boolean; quantity_on_hand: number }
interface Pack { id: number; name: string; class_id: number | null; is_active: boolean; items: PackItem[]; charged_total: number }
interface Line { id: number; item_name: string; variant: string | null; quantity: number; unit_price: string | number; charged: boolean; status: string }
interface Issue {
  id: number; student_id: number; student_name: string | null; admission_number?: string | null; class_name?: string | null;
  status: string; issued_at: string; confirmed_at: string | null; parent_note: string | null; resolution_note: string | null;
  lines: Line[]; charged_total: number; has_parent?: boolean;
}
interface Summary {
  term_id: number | null; items: number; stock_value: number;
  low_stock: { id: number; label: string; quantity_on_hand: number; reorder_level: number }[];
  awaiting_confirmation: number; disputed: number;
  sales: { billed: number; awaiting_invoice: number; awaiting_invoice_count: number };
}
type Option = { id: number; name: string };

const CATEGORIES: Record<string, string> = { uniform: 'Uniform', book: 'Book', consumable: 'Consumable', other: 'Other' };
const ISSUE_STATUS: Record<string, { label: string; tone: 'green' | 'amber' | 'red' | 'slate' | 'blue' }> = {
  awaiting: { label: 'Awaiting parent', tone: 'amber' },
  confirmed: { label: 'Received', tone: 'green' },
  disputed: { label: 'Not received', tone: 'red' },
  resolved: { label: 'Resolved', tone: 'slate' },
};
const LINE_STATUS: Record<string, string> = { pending: 'waiting', received: 'received', missing: 'missing', cancelled: 'cancelled' };
const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'handover', label: 'Hand over' },
  { key: 'issues', label: 'Hand-overs' },
  { key: 'items', label: 'Items & stock' },
  { key: 'packs', label: 'Packs' },
] as const;
type Tab = (typeof TABS)[number]['key'];

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

export default function StorePage() {
  const [enabled, setEnabled] = useState<boolean | null>(null);
  const [admin] = useState(() => roleFromCookie() === 'school_admin');
  const [tab, setTab] = useState<Tab>('overview');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [items, setItems] = useState<Item[] | null>(null);
  const [packs, setPacks] = useState<Pack[] | null>(null);

  useEffect(() => {
    fetchApi<{ enabled: boolean }>('/inventory/settings')
      .then((r) => setEnabled(r.enabled))
      .catch((err) => setError(errorText(err, 'Could not load the school store.')));
  }, []);

  const loadCatalogue = useCallback(async () => {
    try {
      const [i, p] = await Promise.all([
        fetchApi<{ data: Item[] }>('/inventory/items?include_inactive=1'),
        fetchApi<{ data: Pack[] }>('/inventory/packs'),
      ]);
      setItems(i.data);
      setPacks(p.data);
    } catch (err) {
      setError(errorText(err, 'Could not load the store.'));
    }
  }, []);

  useEffect(() => {
    if (!enabled) return;
    void (async () => {
      await loadCatalogue();
    })();
  }, [enabled, loadCatalogue]);

  /** Every write goes through here: one busy flag, one notice, one error line. */
  async function send<T>(path: string, method: 'POST' | 'PUT', body: unknown, done: string): Promise<T | null> {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const r = await fetchApi<T & { message?: string }>(path, { method, body: JSON.stringify(body) });
      setNotice(r.message ?? done);
      return r;
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function toggle(on: boolean) {
    const r = await send<{ enabled: boolean }>('/inventory/settings', 'PUT', { enabled: on }, on ? 'The school store is on.' : 'The school store is off.');
    if (r) setEnabled(r.enabled);
  }

  if (enabled === null) {
    return (
      <PageShell title="School store" subtitle="Uniforms, books and consumables handed to students.">
        <ErrorNote message={error} />
        {!error && <Loading />}
      </PageShell>
    );
  }

  if (!enabled) {
    return (
      <PageShell title="School store" subtitle="Uniforms, books and consumables handed to students.">
        <ErrorNote message={error} />
        <Card className="max-w-2xl">
          <h2 className="font-headline text-lg font-semibold text-brand">The school store is off</h2>
          <p className="mt-2 text-sm text-ink-muted">
            Switch it on to keep stock of what you sell or hand to students (uniforms, textbooks, exercise books, sportswear),
            hand it over in one go at registration, and have each parent confirm in their app that it arrived.
            Items you choose to charge go onto that term’s invoice.
          </p>
          <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-ink-muted">
            <li>Nothing changes for parents or staff until you switch it on.</li>
            <li>You can switch it off again at any time. Records are kept.</li>
          </ul>
          <div className="mt-5">
            {admin ? (
              <Button variant="accent" disabled={busy} onClick={() => void toggle(true)}>Switch on the school store</Button>
            ) : (
              <p className="text-sm font-medium text-ink">Ask the school admin to switch it on.</p>
            )}
          </div>
        </Card>
      </PageShell>
    );
  }

  return (
    <PageShell
      title="School store"
      subtitle="Stock in, hand-overs out, and a parent’s confirmation for every hand-over."
      actions={admin ? <Button variant="secondary" disabled={busy} onClick={() => { if (window.confirm('Switch off the school store? Parents stop seeing it; records are kept.')) void toggle(false); }}>Switch off</Button> : undefined}
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <nav aria-label="Store sections" className="mb-6 flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            aria-current={tab === t.key ? 'page' : undefined}
            onClick={() => setTab(t.key)}
            className={`rounded-full px-4 py-2 text-sm font-medium transition ${tab === t.key ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'}`}
          >
            {t.label}
          </button>
        ))}
      </nav>

      {tab === 'overview' && <Overview onOpen={setTab} send={send} busy={busy} />}
      {tab === 'handover' && <HandOver items={items} packs={packs} send={send} busy={busy} onDone={() => { void loadCatalogue(); setTab('issues'); }} />}
      {tab === 'issues' && <Issues send={send} busy={busy} onChange={loadCatalogue} />}
      {tab === 'items' && <Items items={items} send={send} busy={busy} onChange={loadCatalogue} />}
      {tab === 'packs' && <Packs items={items} packs={packs} send={send} busy={busy} onChange={loadCatalogue} />}
    </PageShell>
  );
}

type Send = <T>(path: string, method: 'POST' | 'PUT', body: unknown, done: string) => Promise<T | null>;

// ------------------------------------------------------------------ overview

function Overview({ onOpen, send, busy }: { onOpen: (t: Tab) => void; send: Send; busy: boolean }) {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    fetchApi<Summary>('/inventory/summary').then(setSummary).catch((err) => setError(errorText(err, 'Could not load the summary.')));
  }, []);
  useEffect(load, [load]);

  if (error) return <ErrorNote message={error} />;
  if (!summary) return <Loading />;

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Waiting for parents" value={summary.awaiting_confirmation} tone={summary.awaiting_confirmation ? 'amber' : 'slate'} hint="hand-overs not yet confirmed" />
        <StatTile label="Not received" value={summary.disputed} tone={summary.disputed ? 'red' : 'slate'} hint={summary.disputed ? 'a parent says something is missing' : 'nothing disputed'} />
        <StatTile label="Billed this term" value={nairaAmount(summary.sales.billed)} tone="green" hint="on students’ term invoices" />
        <StatTile label="Stock value" value={nairaAmount(summary.stock_value)} hint={`${summary.items} item(s) at selling price`} />
      </div>

      {summary.sales.awaiting_invoice_count > 0 && (
        <Card>
          <p className="text-sm">
            <span className="font-semibold">{summary.sales.awaiting_invoice_count} charge(s), {nairaAmount(summary.sales.awaiting_invoice)},</span>{' '}
            are waiting for the students’ term invoices. Generate the invoices in Finance, then add them here.
          </p>
          {summary.term_id && (
            <Button className="mt-3" variant="secondary" disabled={busy} onClick={() => void send('/inventory/charges/post', 'POST', { term_id: summary.term_id }, 'Charges added.').then(load)}>
              Add waiting charges to invoices
            </Button>
          )}
        </Card>
      )}

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Running low">
          {summary.low_stock.length === 0 ? <p className="text-sm text-ink-muted">Nothing is at or below its reorder level.</p> : (
            <ul className="divide-y divide-line-soft text-sm">
              {summary.low_stock.map((i) => (
                <li key={i.id} className="flex justify-between py-2"><span>{i.label}</span><span className="font-semibold text-negative">{i.quantity_on_hand} left</span></li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Next steps">
          <div className="flex flex-wrap gap-2">
            <Button variant="accent" onClick={() => onOpen('handover')}>Hand over items</Button>
            {summary.disputed > 0 && <Button variant="secondary" onClick={() => onOpen('issues')}>Sort out {summary.disputed} not received</Button>}
            <Button variant="secondary" onClick={() => onOpen('items')}>Receive stock</Button>
          </div>
        </Card>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ hand over

function HandOver({ items, packs, send, busy, onDone }: { items: Item[] | null; packs: Pack[] | null; send: Send; busy: boolean; onDone: () => void }) {
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [packId, setPackId] = useState('');
  const [lines, setLines] = useState<{ item_id: string; quantity: number }[]>([]);
  const [terms, setTerms] = useState<{ id: number; name: string; session_name?: string | null }[]>([]);
  const [termId, setTermId] = useState('');

  useEffect(() => {
    fetchApi<{ data: { id: number; name: string; session_name?: string | null }[] }>('/terms').then((r) => setTerms(r.data ?? [])).catch(() => undefined);
  }, []);

  if (!items || !packs) return <Loading />;

  const active = items.filter((i) => i.is_active);
  const pack = packs.find((p) => String(p.id) === packId);
  const extra = lines.filter((l) => l.item_id);
  const total =
    (pack?.charged_total ?? 0) +
    extra.reduce((t, l) => {
      const it = active.find((i) => String(i.id) === l.item_id);
      return t + (it && it.charge_to_fees ? Number(it.unit_price) * l.quantity : 0);
    }, 0);
  const ready = student && (pack || extra.length > 0);

  async function submit() {
    if (!student) return;
    const r = await send<{ issue: Issue }>('/inventory/issues', 'POST', {
      student_id: student.id,
      term_id: termId ? Number(termId) : null,
      pack_id: pack ? pack.id : null,
      lines: extra.map((l) => ({ item_id: Number(l.item_id), quantity: l.quantity })),
    }, `Handed over to ${student.name ?? 'the student'}. Their parent has been asked to confirm.`);
    if (r) {
      setStudent(null);
      setPackId('');
      setLines([]);
      onDone();
    }
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_20rem]">
      <Card title="Hand items to a student" description="Stock comes off straight away. The student’s parent confirms in their app that everything arrived.">
        <div className="space-y-4">
          <Field label="Student"><StudentPicker value={student} onChange={setStudent} /></Field>
          <Field label="Pack (optional)">
            <select className={inputClass} value={packId} onChange={(e) => setPackId(e.target.value)}>
              <option value="">No pack</option>
              {packs.filter((p) => p.is_active).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </Field>
          {pack && (
            <ul className="rounded-lg bg-surface-sunken p-3 text-sm">
              {pack.items.map((i) => (
                <li key={i.item_id} className="flex justify-between py-0.5">
                  <span>{i.quantity} × {i.label}</span>
                  <span className={i.quantity_on_hand < i.quantity ? 'font-semibold text-negative' : 'text-ink-muted'}>{i.quantity_on_hand} in stock</span>
                </li>
              ))}
            </ul>
          )}
          <div>
            <p className="mb-2 text-sm font-medium">Other items</p>
            {lines.map((l, idx) => (
              <div key={idx} className="mb-2 flex gap-2">
                <select aria-label="Item" className={inputClass} value={l.item_id} onChange={(e) => setLines(lines.map((x, j) => (j === idx ? { ...x, item_id: e.target.value } : x)))}>
                  <option value="">Choose an item</option>
                  {active.map((i) => <option key={i.id} value={i.id} disabled={i.quantity_on_hand < 1}>{i.label} · {nairaAmount(Number(i.unit_price))} · {i.quantity_on_hand} left</option>)}
                </select>
                <input aria-label="Quantity" className={`${inputClass} w-20`} type="number" min={1} value={l.quantity} onChange={(e) => setLines(lines.map((x, j) => (j === idx ? { ...x, quantity: Math.max(1, Number(e.target.value)) } : x)))} />
                <Button variant="secondary" aria-label="Remove" onClick={() => setLines(lines.filter((_, j) => j !== idx))}>×</Button>
              </div>
            ))}
            <Button variant="secondary" onClick={() => setLines([...lines, { item_id: '', quantity: 1 }])}>Add an item</Button>
          </div>
        </div>
      </Card>
      <Card title="Summary">
        <div className="space-y-4">
          <Field label="Bill to term">
            <select className={inputClass} value={termId} onChange={(e) => setTermId(e.target.value)}>
              <option value="">Current term</option>
              {terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}
            </select>
          </Field>
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">Added to the term invoice</p>
            <p className="font-headline mt-1 text-2xl font-bold text-brand">{nairaAmount(total)}</p>
            <p className="mt-1 text-xs text-ink-muted">In full, this term. Items marked free are handed over at no charge.</p>
          </div>
          <Button variant="accent" className="w-full" disabled={busy || !ready} onClick={() => void submit()}>Hand over</Button>
          {!student && <p className="text-xs text-ink-muted">Find the student first.</p>}
        </div>
      </Card>
    </div>
  );
}

// ------------------------------------------------------------------ hand-overs

function Issues({ send, busy, onChange }: { send: Send; busy: boolean; onChange: () => void }) {
  const [status, setStatus] = useState('');
  const [issues, setIssues] = useState<Issue[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const [note, setNote] = useState('');
  const [restock, setRestock] = useState(true);

  const load = useCallback(async () => {
    try {
      setIssues((await fetchApi<{ data: Issue[] }>(`/inventory/issues${status ? `?status=${status}` : ''}`)).data);
    } catch (err) {
      setError(errorText(err, 'Could not load hand-overs.'));
    }
  }, [status]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function resolve(issue: Issue, resolution: 'redelivered' | 'cancelled') {
    const done = resolution === 'redelivered' ? 'The parent has been asked to confirm again.' : 'Cancelled. The charge has come off the invoice.';
    const r = await send(`/inventory/issues/${issue.id}/resolve`, 'POST', { resolution, restock, note: note || null }, done);
    if (r) {
      setOpen(null);
      setNote('');
      await load();
      onChange();
    }
  }

  return (
    <Card>
      <ErrorNote message={error} />
      <div className="mb-4 max-w-xs">
        <Field label="Show">
          <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All hand-overs</option>
            <option value="awaiting">Awaiting parent</option>
            <option value="disputed">Not received</option>
            <option value="confirmed">Received</option>
            <option value="resolved">Resolved</option>
          </select>
        </Field>
      </div>
      {!issues ? <Loading /> : issues.length === 0 ? <Empty>No hand-overs here.</Empty> : (
        <Table columns={['Student', 'Items', 'Charged', 'Status', '']}>
          {issues.map((i) => (
            <React.Fragment key={i.id}>
              <tr>
                <Cell className="font-medium">
                  {i.student_name ?? `#${i.student_id}`}
                  <span className="block text-xs text-ink-muted">{[i.admission_number, i.class_name, when(i.issued_at)].filter(Boolean).join(' · ')}</span>
                </Cell>
                <Cell>
                  <ul className="text-sm">
                    {i.lines.map((l) => (
                      <li key={l.id} className={l.status === 'cancelled' ? 'text-ink-faint line-through' : l.status === 'missing' ? 'font-semibold text-negative' : ''}>
                        {l.quantity} × {l.item_name}{l.variant ? ` (${l.variant})` : ''}{!l.charged && ' · free'}
                        {l.status !== 'pending' && l.status !== 'received' && <span className="text-xs"> — {LINE_STATUS[l.status]}</span>}
                      </li>
                    ))}
                  </ul>
                  {i.parent_note && <p className="mt-1 text-xs text-ink-muted">Parent: “{i.parent_note}”</p>}
                </Cell>
                <Cell>{nairaAmount(i.charged_total)}</Cell>
                <Cell>
                  <Badge tone={ISSUE_STATUS[i.status]?.tone ?? 'slate'}>{ISSUE_STATUS[i.status]?.label ?? i.status}</Badge>
                  {i.status === 'awaiting' && i.has_parent === false && <span className="mt-1 block text-xs text-negative">No parent linked</span>}
                </Cell>
                <Cell>{i.status === 'disputed' && <Button variant="secondary" onClick={() => setOpen(open === i.id ? null : i.id)}>Sort out</Button>}</Cell>
              </tr>
              {open === i.id && (
                <tr>
                  <td colSpan={5} className="bg-surface-sunken px-4 py-4">
                    <div className="max-w-2xl space-y-3">
                      <Field label="Note for the record (optional)"><input className={inputClass} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Atlas given to her in class on Monday" /></Field>
                      <div className="flex flex-wrap items-center gap-3">
                        <Button disabled={busy} onClick={() => void resolve(i, 'redelivered')}>Handed over now: ask the parent again</Button>
                        <Button variant="danger" disabled={busy} onClick={() => { if (window.confirm('Cancel the missing items? Their charge comes off the invoice.')) void resolve(i, 'cancelled'); }}>Cancel the missing items</Button>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={restock} onChange={(e) => setRestock(e.target.checked)} /> Put them back in stock</label>
                      </div>
                      <p className="text-xs text-ink-muted">If the parent has already paid for a cancelled item, record the refund in Finance first.</p>
                    </div>
                  </td>
                </tr>
              )}
            </React.Fragment>
          ))}
        </Table>
      )}
    </Card>
  );
}

// ------------------------------------------------------------------ items

const blankItem = { name: '', variant: '', category: 'uniform', unit_price: '', charge_to_fees: true, reorder_level: '5', opening_stock: '' };

function Items({ items, send, busy, onChange }: { items: Item[] | null; send: Send; busy: boolean; onChange: () => void }) {
  const [form, setForm] = useState(blankItem);
  const [stock, setStock] = useState<{ id: number; change: string; reason: 'received' | 'adjusted'; note: string } | null>(null);

  if (!items) return <Loading />;

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const r = await send('/inventory/items', 'POST', {
      ...form,
      variant: form.variant || null,
      unit_price: Number(form.unit_price),
      reorder_level: Number(form.reorder_level || 0),
      opening_stock: Number(form.opening_stock || 0),
    }, 'Item added.');
    if (r) {
      setForm({ ...blankItem, category: form.category });
      onChange();
    }
  }

  async function saveStock() {
    if (!stock) return;
    const r = await send(`/inventory/items/${stock.id}/stock`, 'POST', { change: Number(stock.change), reason: stock.reason, note: stock.note || null }, 'Stock updated.');
    if (r) {
      setStock(null);
      onChange();
    }
  }

  async function patch(item: Item, body: Partial<Item>, done: string) {
    if (await send(`/inventory/items/${item.id}`, 'PUT', body, done)) onChange();
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_22rem]">
      <Card>
        {items.length === 0 ? <Empty>No items yet. Add your first on the right.</Empty> : (
          <Table columns={['Item', 'Price', 'Fees', 'In stock', '']}>
            {items.map((i) => (
              <React.Fragment key={i.id}>
                <tr className={i.is_active ? '' : 'opacity-50'}>
                  <Cell className="font-medium">{i.label}<span className="block text-xs text-ink-muted">{CATEGORIES[i.category] ?? i.category}{!i.is_active && ' · retired'}</span></Cell>
                  <Cell>{nairaAmount(Number(i.unit_price))}</Cell>
                  <Cell>
                    <button type="button" className="text-left" disabled={busy} onClick={() => void patch(i, { charge_to_fees: !i.charge_to_fees }, i.charge_to_fees ? 'Now handed over free.' : 'Now charged to fees.')}>
                      <Badge tone={i.charge_to_fees ? 'blue' : 'slate'}>{i.charge_to_fees ? 'Charged' : 'Free'}</Badge>
                    </button>
                  </Cell>
                  <Cell><span className={i.low_stock ? 'font-semibold text-negative' : ''}>{i.quantity_on_hand}</span>{i.low_stock && <span className="block text-xs text-negative">reorder</span>}</Cell>
                  <Cell>
                    <div className="flex flex-wrap gap-2">
                      <Button variant="secondary" onClick={() => setStock({ id: i.id, change: '', reason: 'received', note: '' })}>Stock</Button>
                      <Button variant="secondary" disabled={busy} onClick={() => void patch(i, { is_active: !i.is_active }, i.is_active ? 'Item retired.' : 'Item back in the store.')}>{i.is_active ? 'Retire' : 'Restore'}</Button>
                    </div>
                  </Cell>
                </tr>
                {stock?.id === i.id && (
                  <tr>
                    <td colSpan={5} className="bg-surface-sunken px-4 py-4">
                      <div className="grid max-w-2xl gap-3 sm:grid-cols-[10rem_7rem_1fr_auto] sm:items-end">
                        <Field label="What happened">
                          <select className={inputClass} value={stock.reason} onChange={(e) => setStock({ ...stock, reason: e.target.value as 'received' | 'adjusted' })}>
                            <option value="received">Delivery received</option>
                            <option value="adjusted">Correction after a count</option>
                          </select>
                        </Field>
                        <Field label={stock.reason === 'received' ? 'How many' : '+ or −'}><input className={inputClass} type="number" value={stock.change} onChange={(e) => setStock({ ...stock, change: e.target.value })} /></Field>
                        <Field label="Note"><input className={inputClass} maxLength={255} value={stock.note} onChange={(e) => setStock({ ...stock, note: e.target.value })} placeholder="e.g. From the tailor" /></Field>
                        <div className="flex gap-2"><Button disabled={busy || !Number(stock.change)} onClick={() => void saveStock()}>Save</Button><Button variant="secondary" onClick={() => setStock(null)}>Cancel</Button></div>
                      </div>
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </Table>
        )}
      </Card>
      <Card title="Add an item" description="One line per size or edition, so each has its own stock.">
        <form className="space-y-3" onSubmit={(e) => void add(e)}>
          <Field label="Name"><input className={inputClass} required maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="School shirt" /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Size / edition"><input className={inputClass} maxLength={60} value={form.variant} onChange={(e) => setForm({ ...form, variant: e.target.value })} placeholder="Age 10-11" /></Field>
            <Field label="Kind"><select className={inputClass} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>{Object.entries(CATEGORIES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Price (₦)"><input className={inputClass} type="number" min={0} required value={form.unit_price} onChange={(e) => setForm({ ...form, unit_price: e.target.value })} /></Field>
            <Field label="Opening stock"><input className={inputClass} type="number" min={0} value={form.opening_stock} onChange={(e) => setForm({ ...form, opening_stock: e.target.value })} /></Field>
          </div>
          <Field label="Warn when stock falls to"><input className={inputClass} type="number" min={0} value={form.reorder_level} onChange={(e) => setForm({ ...form, reorder_level: e.target.value })} /></Field>
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1" checked={form.charge_to_fees} onChange={(e) => setForm({ ...form, charge_to_fees: e.target.checked })} />
            <span>Charge to fees<span className="block text-xs text-ink-muted">Added to the term invoice when handed over. Untick for items that are free or already paid for.</span></span>
          </label>
          <Button type="submit" disabled={busy}>Add item</Button>
        </form>
      </Card>
    </div>
  );
}

// ------------------------------------------------------------------ packs

function Packs({ items, packs, send, busy, onChange }: { items: Item[] | null; packs: Pack[] | null; send: Send; busy: boolean; onChange: () => void }) {
  const [classes, setClasses] = useState<Option[]>([]);
  const [form, setForm] = useState<{ id: number | null; name: string; class_id: string; items: { item_id: string; quantity: number }[] }>({ id: null, name: '', class_id: '', items: [{ item_id: '', quantity: 1 }] });

  useEffect(() => {
    fetchApi<{ data?: Option[] }>('/classes').then((r) => setClasses(r.data ?? [])).catch(() => undefined);
  }, []);

  if (!items || !packs) return <Loading />;
  const active = items.filter((i) => i.is_active);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    const body = { name: form.name, class_id: form.class_id ? Number(form.class_id) : null, items: form.items.filter((i) => i.item_id).map((i) => ({ item_id: Number(i.item_id), quantity: i.quantity })) };
    const r = form.id ? await send(`/inventory/packs/${form.id}`, 'PUT', body, 'Pack saved.') : await send('/inventory/packs', 'POST', body, 'Pack added.');
    if (r) {
      setForm({ id: null, name: '', class_id: '', items: [{ item_id: '', quantity: 1 }] });
      onChange();
    }
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_24rem]">
      <div className="grid gap-4 md:grid-cols-2">
        {packs.length === 0 && <Card><Empty>No packs yet. A pack is everything a new student gets, handed over in one go.</Empty></Card>}
        {packs.map((p) => (
          <Card key={p.id} className={p.is_active ? '' : 'opacity-50'}>
            <div className="flex items-start justify-between gap-2">
              <div>
                <h3 className="font-headline font-semibold text-brand">{p.name}</h3>
                <p className="text-xs text-ink-muted">{classes.find((c) => c.id === p.class_id)?.name ?? 'Any class'}</p>
              </div>
              <span className="font-headline font-bold text-brand">{nairaAmount(p.charged_total)}</span>
            </div>
            <ul className="mt-3 space-y-1 text-sm">
              {p.items.map((i) => <li key={i.item_id}>{i.quantity} × {i.label}{!i.charge_to_fees && <span className="text-xs text-ink-muted"> · free</span>}</li>)}
            </ul>
            <div className="mt-4 flex gap-2">
              <Button variant="secondary" onClick={() => setForm({ id: p.id, name: p.name, class_id: p.class_id ? String(p.class_id) : '', items: p.items.map((i) => ({ item_id: String(i.item_id), quantity: i.quantity })) })}>Edit</Button>
              <Button variant="secondary" disabled={busy} onClick={() => void send(`/inventory/packs/${p.id}`, 'PUT', { is_active: !p.is_active }, p.is_active ? 'Pack retired.' : 'Pack restored.').then((r) => r && onChange())}>{p.is_active ? 'Retire' : 'Restore'}</Button>
            </div>
          </Card>
        ))}
      </div>
      <Card title={form.id ? 'Edit pack' : 'New pack'} description="e.g. “JSS 1 new student pack”: uniforms, textbooks and exercise books.">
        <form className="space-y-3" onSubmit={(e) => void save(e)}>
          <Field label="Name"><input className={inputClass} required maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
          <Field label="For class (optional)">
            <select className={inputClass} value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })}>
              <option value="">Any class</option>
              {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          {form.items.map((l, idx) => (
            <div key={idx} className="flex gap-2">
              <select aria-label="Item" className={inputClass} value={l.item_id} onChange={(e) => setForm({ ...form, items: form.items.map((x, j) => (j === idx ? { ...x, item_id: e.target.value } : x)) })}>
                <option value="">Choose an item</option>
                {active.map((i) => <option key={i.id} value={i.id}>{i.label}</option>)}
              </select>
              <input aria-label="Quantity" className={`${inputClass} w-20`} type="number" min={1} value={l.quantity} onChange={(e) => setForm({ ...form, items: form.items.map((x, j) => (j === idx ? { ...x, quantity: Math.max(1, Number(e.target.value)) } : x)) })} />
              <Button type="button" variant="secondary" aria-label="Remove" onClick={() => setForm({ ...form, items: form.items.filter((_, j) => j !== idx) })}>×</Button>
            </div>
          ))}
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="secondary" onClick={() => setForm({ ...form, items: [...form.items, { item_id: '', quantity: 1 }] })}>Add an item</Button>
            <Button type="submit" disabled={busy || !form.items.some((i) => i.item_id)}>{form.id ? 'Save pack' : 'Add pack'}</Button>
            {form.id && <Button type="button" variant="secondary" onClick={() => setForm({ id: null, name: '', class_id: '', items: [{ item_id: '', quantity: 1 }] })}>Cancel</Button>}
          </div>
        </form>
      </Card>
    </div>
  );
}
