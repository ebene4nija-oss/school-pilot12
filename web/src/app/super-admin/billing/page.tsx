'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';
import PlatformSettings from '@/components/PlatformSettings';

/**
 * Billing (docs/super-admin-menu.md items 5 and 6).
 *
 * Each school is on one of two plans, per active student per term:
 *  - Result Management (₦500): the whole platform plus result services.
 *  - School Management Subscription (₦300, after a 30-day free trial): the
 *    platform without SchoolPilot's result computation and printing.
 * Boarders are ₦1,000 each on either plan. Rates are edited below the table.
 *
 * SchoolPilot invoices and collects outside the product; here the operator
 * issues a term's bill (freezing the student count and rate), then records
 * it paid or waived.
 */

interface Statement {
  plan: string | null;
  active_students: number;
  day_students: number;
  boarding_students: number;
  rate_naira: number;
  boarding_rate_naira: number;
  amount_naira: number;
  in_trial: boolean;
  bill: { id: number; status: 'issued' | 'paid' | 'waived'; issued_at: string | null; paid_at: string | null; note: string | null } | null;
}
interface Row {
  school_id: number;
  school: string;
  subdomain: string;
  status: string;
  plan: string | null;
  trial_ends_on: string | null;
  term: { id: number; name: string; start_date: string | null; end_date: string | null } | null;
  statement: Statement | null;
}
interface Billing {
  plans: Record<string, string>;
  rates: { results: number; subscription: number; boarding: number; trial_days: number };
  totals: Record<'issued' | 'paid' | 'waived', { bills: number; naira: number }>;
  data: Row[];
}

const BILL_TONE = { issued: 'amber', paid: 'green', waived: 'slate' } as const;

export default function BillingPage() {
  const [version, setVersion] = useState(0);

  return (
    <PlatformSettings
      section="billing"
      title="Billing"
      subtitle="Each school's plan, what it owes for the current term, and the bills you have issued. Payment is collected outside SchoolPilot and recorded here."
      before={<BillingTable key={version} />}
      onSaved={() => setVersion((v) => v + 1)}
    />
  );
}

function BillingTable() {
  const [data, setData] = useState<Billing | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await fetchApi<Billing>('/platform/billing'));
    } catch (err) {
      setError(errorText(err, 'Could not load billing.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function act(id: number, path: string, method: 'PUT' | 'POST', body: unknown) {
    setBusy(id);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message: string }>(path, { method, body: JSON.stringify(body) });
      setNotice(res.message);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(null);
    }
  }

  if (!data) return error ? <ErrorNote message={error} /> : <Loading />;

  const due = data.data.reduce((t, r) => t + (r.statement && !r.statement.bill && !r.statement.in_trial ? r.statement.amount_naira : 0), 0);

  return (
    <Card
      title="Schools and this term"
      description={`School Management with Result Management ${nairaAmount(data.rates.results)}, School Management Subscription ${nairaAmount(data.rates.subscription)} after a ${data.rates.trial_days}-day trial, School Management with Boarding School Management ${nairaAmount(data.rates.boarding)} per boarder on either plan — per student per term. Active means enrolled in a class during the term.`}
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Not yet billed this term" value={nairaAmount(due)} hint="current terms, outside any trial" />
        <StatTile label="Issued, unpaid" value={nairaAmount(data.totals.issued.naira)} hint={`${data.totals.issued.bills} bill(s)`} tone={data.totals.issued.bills ? 'amber' : 'slate'} />
        <StatTile label="Paid" value={nairaAmount(data.totals.paid.naira)} hint={`${data.totals.paid.bills} bill(s)`} tone="green" />
        <StatTile label="Waived" value={nairaAmount(data.totals.waived.naira)} hint={`${data.totals.waived.bills} bill(s)`} />
      </div>

      {data.data.length === 0 ? (
        <Empty>No schools yet.</Empty>
      ) : (
        <Table columns={['School', 'Plan', 'Term', 'Students', 'Amount', 'Bill']}>
          {data.data.map((r) => (
            <tr key={r.school_id}>
              <Cell className="font-medium text-brand">
                {r.school}
                <span className="block text-xs text-ink-faint">{r.subdomain}</span>
              </Cell>
              <Cell className="min-w-[13rem]">
                <select
                  aria-label={`Plan for ${r.school}`}
                  className={inputClass}
                  value={r.plan ?? ''}
                  disabled={busy === r.school_id}
                  onChange={(e) => void act(r.school_id, `/platform/schools/${r.school_id}/billing-plan`, 'PUT', { plan: e.target.value || null })}
                >
                  <option value="">No plan yet</option>
                  {Object.entries(data.plans).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
                {r.trial_ends_on && (
                  <span className="mt-1 block text-xs text-ink-muted">Trial {new Date(r.trial_ends_on) >= new Date() ? 'ends' : 'ended'} {r.trial_ends_on}</span>
                )}
              </Cell>
              <Cell>{r.term ? <>{r.term.name}<span className="block text-xs text-ink-faint">{r.term.start_date} – {r.term.end_date}</span></> : <span className="text-ink-faint">No term set up</span>}</Cell>
              <Cell>
                {r.statement ? (
                  <>
                    {r.statement.active_students}
                    <span className="block text-xs text-ink-muted">{r.statement.day_students} day · {r.statement.boarding_students} boarding</span>
                  </>
                ) : '—'}
              </Cell>
              <Cell>
                {!r.statement || (!r.plan && !r.statement.bill) ? (
                  <span className="text-ink-faint">—</span>
                ) : r.statement.in_trial ? (
                  <Badge tone="blue">Free trial</Badge>
                ) : (
                  <>
                    {nairaAmount(r.statement.amount_naira)}
                    <span className="block text-xs text-ink-muted">
                      {r.statement.day_students} × {nairaAmount(r.statement.rate_naira)}
                      {r.statement.boarding_students > 0 && ` + ${r.statement.boarding_students} × ${nairaAmount(r.statement.boarding_rate_naira)}`}
                    </span>
                  </>
                )}
              </Cell>
              <Cell className="whitespace-nowrap">
                <BillActions row={r} busy={busy === r.school_id} act={act} />
              </Cell>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  );
}

function BillActions({ row: r, busy, act }: { row: Row; busy: boolean; act: (id: number, path: string, method: 'PUT' | 'POST', body: unknown) => Promise<void> }) {
  const bill = r.statement?.bill;

  if (!r.term || !r.statement) return null;

  if (!bill) {
    if (!r.plan || r.statement.in_trial) return null;

    return (
      <Button
        disabled={busy}
        onClick={() => {
          if (window.confirm(`Issue ${r.school}'s ${r.term?.name} bill for ${nairaAmount(r.statement?.amount_naira ?? 0)}? The student count and rate are fixed once issued.`)) {
            void act(r.school_id, `/platform/schools/${r.school_id}/bills`, 'POST', { term_id: r.term?.id });
          }
        }}
      >
        Issue bill
      </Button>
    );
  }

  return (
    <div className="space-y-1">
      <Badge tone={BILL_TONE[bill.status]}>{bill.status === 'issued' ? 'Unpaid' : bill.status}</Badge>
      {bill.note && <span className="block max-w-[14rem] truncate text-xs text-ink-muted" title={bill.note}>{bill.note}</span>}
      <div className="flex gap-1">
        {bill.status === 'issued' ? (
          <>
            <Button
              variant="secondary"
              disabled={busy}
              onClick={() => {
                const note = window.prompt('Payment reference (optional), e.g. the transfer reference:');
                if (note !== null) void act(r.school_id, `/platform/bills/${bill.id}/status`, 'POST', { status: 'paid', note: note.trim() || null });
              }}
            >
              Mark paid
            </Button>
            <Button
              variant="secondary"
              disabled={busy}
              onClick={() => {
                const note = window.prompt('Why is this bill being waived?');
                if (note && note.trim()) void act(r.school_id, `/platform/bills/${bill.id}/status`, 'POST', { status: 'waived', note: note.trim() });
              }}
            >
              Waive
            </Button>
          </>
        ) : (
          <Button variant="secondary" disabled={busy} onClick={() => { if (window.confirm('Reopen this bill as unpaid?')) void act(r.school_id, `/platform/bills/${bill.id}/status`, 'POST', { status: 'issued' }); }}>
            Reopen
          </Button>
        )}
      </div>
    </div>
  );
}
