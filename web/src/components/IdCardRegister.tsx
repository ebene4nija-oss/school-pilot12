'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, StatTile, Table, inputClass } from '@/components/Page';

/**
 * The card register (TASK 18): every card, where it is in production
 * (generated → print file made → printed → handed over), whether it is still
 * valid, and the bulk steps that move cards along. Printing a PDF never
 * marks a card printed, and printing never marks it issued: each is a
 * separate, recorded step.
 */

type HolderType = 'student' | 'staff';
const REASONS = ['lost', 'stolen', 'damaged', 'left_school', 'data_error', 'other'];

/** One of the reasons the API accepts, typed or chosen; null if cancelled. */
function askReason(question: string): string | null {
  const answer = window.prompt(`${question}

Reason — one of: ${REASONS.join(', ')}`, 'lost');
  if (answer === null) return null;
  const reason = answer.trim().toLowerCase().replace(/\s+/g, '_');
  if (!REASONS.includes(reason)) { window.alert(`Please type one of: ${REASONS.join(', ')}`); return null; }
  return reason;
}

interface Row { id: number; serial: string; name: string | null; identifier: string | null; group: string | null; status: string; production_status: string; revoked_reason: string | null; expires_on: string | null; collected_by: string | null; handed_over_at: string | null; misprints: number }
interface Summary { eligible: number; not_generated: number; generated: number; pending_print: number; printed: number; pending_collection: number; issued: number; expiring_soon: number; lost_replacement_pending: number; deactivated: number; expired: number }
interface Settings { valid_only_when_issued: boolean; staff_validity_months: number | null; expiry_warning_days: number; digital_cards_enabled: boolean; parents_see_digital_card: boolean; log_verifications: boolean }
interface Verification { found: boolean; valid: boolean; status: string; headline: string; holder_name?: string; role?: string; photo?: string | null; expires_on?: string | null }

const PRODUCTION: Record<string, { label: string; tone: 'slate' | 'amber' | 'blue' | 'green' }> = {
  generated: { label: 'Generated', tone: 'slate' },
  print_ready: { label: 'Print file made', tone: 'amber' },
  printed: { label: 'Printed, awaiting collection', tone: 'blue' },
  issued: { label: 'Handed over', tone: 'green' },
};

export default function IdCardRegister() {
  const [holderType, setHolderType] = useState<HolderType>('student');
  const [summary, setSummary] = useState<Summary | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [filter, setFilter] = useState({ production_status: '', status: '', q: '' });
  const [selected, setSelected] = useState<number[]>([]);
  const [history, setHistory] = useState<{ serial: string; events: { event: string; note: string | null; created_at: string; by: string | null }[] } | null>(null);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [lookup, setLookup] = useState('');
  const [verified, setVerified] = useState<Verification | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const qs = new URLSearchParams({ holder_type: holderType, ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)) });
    const [d, c, s] = await Promise.all([
      fetchApi<{ summary: Summary }>(`/id-cards/dashboard?holder_type=${holderType}`),
      fetchApi<{ data: Row[] }>(`/id-cards/cards?${qs}`),
      fetchApi<Settings>('/id-cards/settings'),
    ]);
    setSummary(d.summary);
    setRows(c.data);
    setSettings(s);
    setSelected([]);
  }, [holderType, filter]);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        await load();
        setError(null);
      } catch (err) {
        setError(errorText(err, 'Could not load the card register.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function post(path: string, body: unknown, method: 'POST' | 'PUT' = 'POST') {
    setBusy(true);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string; handed_over?: number; results?: { ok: boolean; reason?: string }[] }>(path, { method, body: JSON.stringify(body) });
      const failed = res.results?.filter((r) => !r.ok) ?? [];
      setNotice(res.message ?? (res.handed_over !== undefined ? `${res.handed_over} card(s) handed over.${failed.length ? ` ${failed.length} skipped: ${failed[0].reason}` : ''}` : 'Saved.'));
      setError(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  async function showHistory(row: Row) {
    try {
      const res = await fetchApi<{ data: { event: string; note: string | null; created_at: string; by: string | null }[] }>(`/id-cards/${row.id}/events`);
      setHistory({ serial: row.serial, events: res.data });
    } catch (err) {
      setError(errorText(err, 'Could not load history.'));
    }
  }

  async function check(e: React.FormEvent) {
    e.preventDefault();
    try {
      setVerified(await fetchApi<Verification>('/id-cards/lookup', { method: 'POST', body: JSON.stringify({ serial: lookup }) }));
    } catch {
      setVerified({ found: false, valid: false, status: 'unknown', headline: 'Not a recognised card' });
    }
  }

  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        {(['student', 'staff'] as const).map((t) => (
          <Button key={t} variant={holderType === t ? 'primary' : 'secondary'} onClick={() => setHolderType(t)}>{t === 'student' ? 'Students' : 'Staff'}</Button>
        ))}
      </div>
      <ErrorNote message={error} />
      {notice && <p className="rounded-lg bg-positive-soft px-4 py-2 text-sm text-positive">{notice}</p>}

      {summary && (
        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <StatTile label="Eligible" value={summary.eligible} hint={`${summary.not_generated} without a card`} />
          <StatTile label="Awaiting print" value={summary.pending_print} tone="amber" />
          <StatTile label="Awaiting collection" value={summary.pending_collection} tone="blue" />
          <StatTile label="Handed over" value={summary.issued} tone="green" hint={`${summary.expiring_soon} expiring soon`} />
          <StatTile label="Lost / replace" value={summary.lost_replacement_pending} tone={summary.lost_replacement_pending ? 'red' : 'slate'} hint={`${summary.deactivated} deactivated · ${summary.expired} expired`} />
        </div>
      )}

      <Card title="Check a card" description="Type the card number printed on the card. The answer shows only what a gate check needs, and the check is logged.">
        <form className="flex flex-wrap items-end gap-3" onSubmit={check}>
          <Field label="Card number"><input className={inputClass} value={lookup} onChange={(e) => setLookup(e.target.value)} required /></Field>
          <Button variant="secondary">Check</Button>
        </form>
        {verified && (
          <div className="mt-4 flex items-center gap-4">
            {verified.photo && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={verified.photo} alt="" className="h-20 w-16 rounded object-cover" />
            )}
            <div>
              <Badge tone={verified.valid ? 'green' : 'red'}>{verified.headline}</Badge>
              {verified.holder_name && <p className="mt-1 font-medium">{verified.holder_name}</p>}
              {verified.role && <p className="text-sm text-ink-muted">{verified.role}{verified.expires_on ? ` · valid to ${verified.expires_on}` : ''}</p>}
            </div>
          </div>
        )}
      </Card>

      <Card title="Card register">
        <div className="mb-4 grid gap-3 sm:grid-cols-4">
          <input className={inputClass} placeholder="Name, admission no. or card no." defaultValue={filter.q} onKeyDown={(e) => { if (e.key === 'Enter') setFilter({ ...filter, q: (e.target as HTMLInputElement).value }); }} />
          <select className={inputClass} value={filter.production_status} onChange={(e) => setFilter({ ...filter, production_status: e.target.value })}>
            <option value="">Any production stage</option>
            {Object.entries(PRODUCTION).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
          <select className={inputClass} value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value })}>
            <option value="">Any validity</option>
            {['active', 'expired', 'revoked', 'replaced'].map((s) => <option key={s}>{s}</option>)}
          </select>
          <div className="flex gap-2">
            <Button disabled={busy || selected.length === 0} onClick={() => { const who = window.prompt('Collected by? Leave blank if the holder collected it.') ?? null; if (who !== null) post('/id-cards/hand-over', { card_ids: selected, collected_by: who || null }); }}>Hand over ({selected.length})</Button>
            <Button variant="secondary" disabled={busy || selected.length === 0} onClick={() => { if (window.confirm(`Issue new cards for ${selected.length} holder(s)? The old cards stay on record, superseded.`)) post('/id-cards/renew', { card_ids: selected }); }}>Renew</Button>
          </div>
        </div>
        {loading ? <Loading /> : rows.length === 0 ? <Empty>No cards match.</Empty> : (
          <Table columns={['', 'Card', 'Holder', 'Production', 'Validity', '']}>
            {rows.map((r) => (
              <tr key={r.id}>
                <Cell><input type="checkbox" aria-label={`Select ${r.serial}`} checked={selected.includes(r.id)} onChange={() => toggle(r.id)} /></Cell>
                <Cell className="font-mono text-xs">{r.serial}</Cell>
                <Cell>{r.name}<span className="block text-xs text-ink-muted">{[r.identifier, r.group].filter(Boolean).join(' · ')}</span></Cell>
                <Cell>
                  <Badge tone={PRODUCTION[r.production_status]?.tone ?? 'slate'}>{PRODUCTION[r.production_status]?.label ?? r.production_status}</Badge>
                  {r.collected_by && <span className="block text-xs text-ink-muted">Collected by {r.collected_by}</span>}
                  {r.misprints > 0 && <span className="block text-xs text-caution">{r.misprints} misprint(s)</span>}
                </Cell>
                <Cell>
                  <Badge tone={r.status === 'active' ? 'green' : r.status === 'expired' ? 'amber' : 'red'}>{r.status}{r.revoked_reason ? ` · ${r.revoked_reason.replaceAll('_', ' ')}` : ''}</Badge>
                  {r.expires_on && <span className="block text-xs text-ink-muted">to {r.expires_on}</span>}
                </Cell>
                <Cell className="space-x-2 whitespace-nowrap text-right">
                  <Button variant="secondary" onClick={() => showHistory(r)}>History</Button>
                  {r.status === 'active' && <>
                    {/* Lost or stolen: stop the card at once — the QR then verifies as invalid. */}
                    <Button variant="danger" disabled={busy} onClick={() => { const reason = askReason(`Revoke ${r.serial}? It stops verifying at once.`); if (reason) post(`/id-cards/${r.id}/revoke`, { reason }); }}>Revoke</Button>
                    <Button variant="secondary" disabled={busy} onClick={() => { const reason = askReason(`Replace ${r.serial}? The old card is revoked and a new one is queued for printing.`); if (reason) post(`/id-cards/${r.id}/replace`, { reason }); }}>Replace</Button>
                  </>}
                </Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      {history && (
        <Card title={`History · ${history.serial}`}>
          <ol className="space-y-1 text-sm">
            {history.events.map((e, i) => (
              <li key={i}><span className="text-ink-muted">{new Date(e.created_at).toLocaleString('en-NG')}</span> · {e.event.replaceAll('_', ' ')}{e.note ? ` (${e.note})` : ''}{e.by ? ` · ${e.by}` : ''}</li>
            ))}
          </ol>
          <Button className="mt-3" variant="secondary" onClick={() => setHistory(null)}>Close</Button>
        </Card>
      )}

      {settings && (
        <Card title="Card policy">
          <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); post('/id-cards/settings', settings, 'PUT'); }}>
            <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={settings.valid_only_when_issued} onChange={(e) => setSettings({ ...settings, valid_only_when_issued: e.target.checked })} /> <span>A card verifies as valid only after it has been handed over. <span className="block text-xs text-ink-muted">Turning this on makes cards already in pockets but never recorded as handed over read &quot;not yet issued&quot;.</span></span></label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.log_verifications} onChange={(e) => setSettings({ ...settings, log_verifications: e.target.checked })} /> Keep a log of card checks</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.digital_cards_enabled} onChange={(e) => setSettings({ ...settings, digital_cards_enabled: e.target.checked })} /> Digital ID cards in the student and staff app</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" disabled={!settings.digital_cards_enabled} checked={settings.parents_see_digital_card} onChange={(e) => setSettings({ ...settings, parents_see_digital_card: e.target.checked })} /> Parents can see their child&apos;s digital card</label>
            <Field label="Staff cards renew every (months; blank = while employed)"><input className={inputClass} type="number" min={1} max={120} value={settings.staff_validity_months ?? ''} onChange={(e) => setSettings({ ...settings, staff_validity_months: e.target.value ? Number(e.target.value) : null })} /></Field>
            <Field label="Warn about expiry this many days ahead"><input className={inputClass} type="number" min={1} max={180} value={settings.expiry_warning_days} onChange={(e) => setSettings({ ...settings, expiry_warning_days: Number(e.target.value) })} /></Field>
            <div><Button disabled={busy}>Save policy</Button></div>
          </form>
        </Card>
      )}
    </div>
  );
}
