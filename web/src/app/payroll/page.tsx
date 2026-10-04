'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { roleFromCookie } from '@/components/Sidebar';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * Payroll and staff employment (TASK 19, 20). A bursar prepares; a school
 * admin approves and records payment. An approved run is locked: a
 * correction makes a new version and keeps the old one. Statutory
 * deductions are components the school defines; nothing is hard-coded.
 * Every staff member sees their own payslips here.
 */

type Tab = 'runs' | 'staff' | 'components' | 'mine';

interface Run { id: number; period: string; version: number; status: string; total_gross: string; total_deductions: string; total_net: string; staff_count: number; self_approval_reason: string | null; payment_reference: string | null }
interface Line { name: string; amount: number; statutory?: boolean }
interface Slip { id: number; name?: string; period?: string; basic: string; earnings: Line[]; deductions: Line[]; gross: string; total_deductions: string; net: string; leave_days: number; run_status?: string }
interface StaffPay { id: number; name: string | null; staff_number: string | null; designation: string | null; employment_status: string; basic_monthly: number; structure_from: string | null; active_loans: number }
interface Component { id: number; name: string; kind: string; calc: string; default_value: string; statutory: boolean; is_active: boolean }

const STATUS_TONE: Record<string, 'slate' | 'amber' | 'green' | 'blue' | 'red'> = { draft: 'amber', approved: 'blue', paid: 'green', superseded: 'slate' };
const n = (v: string | number) => nairaAmount(Number(v));

export default function PayrollPage() {
  const [role] = useState(roleFromCookie);
  const manager = role === 'school_admin' || role === 'bursar';
  const admin = role === 'school_admin';
  const [tab, setTab] = useState<Tab>(manager ? 'runs' : 'mine');
  const [runs, setRuns] = useState<Run[]>([]);
  const [detail, setDetail] = useState<{ run: Run; payslips: Slip[] } | null>(null);
  const [staff, setStaff] = useState<StaffPay[]>([]);
  const [components, setComponents] = useState<Component[]>([]);
  const [mine, setMine] = useState<Slip[]>([]);
  const [period, setPeriod] = useState(() => new Date().toISOString().slice(0, 7));
  const [comp, setComp] = useState({ name: '', kind: 'deduction', calc: 'fixed', default_value: '', statutory: false });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (tab === 'runs') setRuns((await fetchApi<{ data: Run[] }>('/payroll/runs')).data);
    if (tab === 'staff') setStaff((await fetchApi<{ data: StaffPay[] }>('/hr/staff-pay')).data);
    if (tab === 'components') setComponents((await fetchApi<{ data: Component[] }>('/hr/pay-components')).data);
    if (tab === 'mine') setMine((await fetchApi<{ data: Slip[] }>('/me/payslips')).data);
  }, [tab]);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        await load();
        setError(null);
      } catch (err) {
        setError(errorText(err, 'Could not load.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function act(path: string, body: unknown, method: 'POST' | 'PUT' = 'POST') {
    setBusy(true);
    setNotice(null);
    try {
      const res = await fetchApi<{ message?: string; run?: Run }>(path, { method, body: JSON.stringify(body) });
      setNotice(res?.message ?? 'Saved.');
      setError(null);
      await load();
      if (detail && res?.run) await openRun(res.run.id);
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  async function openRun(id: number) {
    try {
      setDetail(await fetchApi<{ run: Run; payslips: Slip[] }>(`/payroll/runs/${id}`));
    } catch (err) {
      setError(errorText(err, 'Could not open the run.'));
    }
  }

  const tabs: [Tab, string][] = manager ? [['runs', 'Payroll runs'], ['staff', 'Staff pay'], ['components', 'Pay components'], ['mine', 'My payslips']] : [['mine', 'My payslips']];

  return (
    <PageShell title="Payroll" subtitle="Monthly payroll in Naira: prepared, approved, then paid through your bank.">
      <nav className="mb-5 flex flex-wrap gap-2" aria-label="Payroll sections">
        {tabs.map(([k, l]) => <Button key={k} variant={tab === k ? 'primary' : 'secondary'} onClick={() => { setTab(k); setDetail(null); setNotice(null); }}>{l}</Button>)}
      </nav>
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-4 py-2 text-sm text-positive">{notice}</p>}
      {loading && <Loading />}

      {!loading && tab === 'runs' && !detail && (
        <div className="space-y-5">
          <Card title="Prepare a month" description="Uses each staff member's salary structure for that month. Re-preparing a draft recalculates it.">
            <div className="flex flex-wrap items-end gap-3">
              <Field label="Month"><input type="month" className={inputClass} value={period} onChange={(e) => setPeriod(e.target.value)} /></Field>
              <Button disabled={busy} onClick={() => act('/payroll/runs', { period })}>Prepare</Button>
            </div>
          </Card>
          <Card title="Runs">
            {runs.length === 0 ? <Empty>No payroll yet.</Empty> : (
              <Table columns={['Month', 'Status', 'Staff', 'Net pay', '']}>
                {runs.map((r) => (
                  <tr key={r.id}>
                    <Cell>{r.period}{r.version > 1 && <span className="text-xs text-ink-muted"> · v{r.version}</span>}</Cell>
                    <Cell><Badge tone={STATUS_TONE[r.status] ?? 'slate'}>{r.status}</Badge></Cell>
                    <Cell>{r.staff_count}</Cell>
                    <Cell>{n(r.total_net)}</Cell>
                    <Cell className="text-right"><Button variant="secondary" onClick={() => openRun(r.id)}>Open</Button></Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}

      {tab === 'runs' && detail && (
        <div className="space-y-5">
          <Button variant="secondary" onClick={() => setDetail(null)}>← All runs</Button>
          <div className="grid gap-4 sm:grid-cols-4">
            <StatTile label={`${detail.run.period} · v${detail.run.version}`} value={<Badge tone={STATUS_TONE[detail.run.status] ?? 'slate'}>{detail.run.status}</Badge>} hint={detail.run.payment_reference ? `Ref ${detail.run.payment_reference}` : undefined} />
            <StatTile label="Gross" value={n(detail.run.total_gross)} />
            <StatTile label="Deductions" value={n(detail.run.total_deductions)} tone="amber" />
            <StatTile label="Net pay" value={n(detail.run.total_net)} tone="green" />
          </div>
          {detail.run.self_approval_reason && <p className="text-sm text-caution">Approved by the person who prepared it: {detail.run.self_approval_reason}</p>}
          <div className="flex flex-wrap gap-2">
            {admin && detail.run.status === 'draft' && <Button disabled={busy} onClick={() => act(`/payroll/runs/${detail.run.id}/approve`, {}).catch(() => undefined)}>Approve</Button>}
            {admin && detail.run.status === 'draft' && <Button variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt('You prepared this run. Why are you approving it yourself?'); if (reason) act(`/payroll/runs/${detail.run.id}/approve`, { self_approval_reason: reason }); }}>Approve my own run…</Button>}
            {admin && detail.run.status === 'approved' && <Button disabled={busy} onClick={() => { const ref = window.prompt('Bank payment reference?'); if (ref) act(`/payroll/runs/${detail.run.id}/paid`, { payment_reference: ref, paid_on: new Date().toISOString().slice(0, 10) }); }}>Record payment</Button>}
            {admin && detail.run.status === 'approved' && <Button variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt('What needs correcting? A new version will be drafted; this one is kept.'); if (reason) act(`/payroll/runs/${detail.run.id}/reopen`, { reason }); }}>Reopen for correction</Button>}
            <a className="rounded-lg border border-line px-3.5 py-2 text-sm hover:bg-surface-sunken" href={`/api/backend/payroll/runs/${detail.run.id}/export`}>Export CSV</a>
          </div>
          <Card title="Payslips">
            <Table columns={['Staff', 'Basic', 'Earnings', 'Deductions', 'Net']}>
              {detail.payslips.map((p) => (
                <tr key={p.id}>
                  <Cell>{p.name}{p.leave_days > 0 && <span className="block text-xs text-ink-muted">{p.leave_days} day(s) of leave</span>}</Cell>
                  <Cell>{n(p.basic)}</Cell>
                  <Cell className="text-xs">{p.earnings.map((e) => `${e.name} ${n(e.amount)}`).join(' · ') || '—'}</Cell>
                  <Cell className="text-xs">{p.deductions.map((d) => `${d.name} ${n(d.amount)}`).join(' · ') || '—'}</Cell>
                  <Cell className={Number(p.net) < 0 ? 'font-semibold text-negative' : 'font-semibold'}>{n(p.net)}</Cell>
                </tr>
              ))}
            </Table>
          </Card>
        </div>
      )}

      {!loading && tab === 'staff' && (
        <Card title="Staff pay" description="Changing pay adds a new structure from a date; earlier months keep the pay they had.">
          {staff.length === 0 ? <Empty>No staff.</Empty> : (
            <Table columns={['Staff', 'Status', 'Basic / month', 'Loans', '']}>
              {staff.map((s) => (
                <tr key={s.id}>
                  <Cell>{s.name}<span className="block text-xs text-ink-muted">{[s.staff_number, s.designation].filter(Boolean).join(' · ')}</span></Cell>
                  <Cell><Badge tone={s.employment_status === 'active' ? 'green' : s.employment_status === 'separated' ? 'slate' : 'amber'}>{s.employment_status.replace('_', ' ')}</Badge></Cell>
                  <Cell>{n(s.basic_monthly)}{s.structure_from && <span className="block text-xs text-ink-muted">from {s.structure_from}</span>}</Cell>
                  <Cell>{s.active_loans ? n(s.active_loans) : '—'}</Cell>
                  <Cell className="space-x-2 whitespace-nowrap text-right">
                    <Button variant="secondary" disabled={busy} onClick={() => { const basic = window.prompt(`New monthly basic for ${s.name} (₦)?`); const from = basic ? window.prompt('Effective from (YYYY-MM-DD)?', new Date().toISOString().slice(0, 8) + '01') : null; if (basic && from) act(`/hr/staff/${s.id}/salary`, { basic_monthly: Number(basic), effective_from: from }); }}>Set pay</Button>
                    <Button variant="secondary" disabled={busy} onClick={() => { const amt = window.prompt('Advance or loan amount (₦)?'); const monthly = amt ? window.prompt('Repay per month (₦)?') : null; if (amt && monthly) act('/hr/loans', { staff_id: s.id, kind: 'advance', principal: Number(amt), monthly_deduction: Number(monthly) }); }}>Loan</Button>
                    {admin && s.employment_status !== 'separated' && <Button variant="danger" disabled={busy} onClick={() => { const date = window.prompt(`${s.name}: last working day (YYYY-MM-DD)?`); const reason = date ? window.prompt('Reason: resigned, retired, dismissed, contract_ended, deceased or other?', 'resigned') : null; if (date && reason) act(`/hr/staff/${s.id}/status`, { status: 'separated', separated_on: date, separation_reason: reason }, 'PUT'); }}>Separate</Button>}
                  </Cell>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      )}

      {!loading && tab === 'components' && (
        <div className="space-y-5">
          <Card title="Add a pay component" description="Allowances and deductions, including statutory ones like PAYE or pension. Your school sets the rates.">
            <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); act('/hr/pay-components', { ...comp, default_value: Number(comp.default_value) }); }}>
              <Field label="Name"><input className={inputClass} required value={comp.name} onChange={(e) => setComp({ ...comp, name: e.target.value })} /></Field>
              <Field label="Kind"><select className={inputClass} value={comp.kind} onChange={(e) => setComp({ ...comp, kind: e.target.value })}><option value="earning">Earning</option><option value="deduction">Deduction</option></select></Field>
              <Field label="Calculated as"><select className={inputClass} value={comp.calc} onChange={(e) => setComp({ ...comp, calc: e.target.value })}><option value="fixed">Fixed ₦</option><option value="percent_of_basic">% of basic</option>{comp.kind === 'deduction' && <option value="percent_of_gross">% of gross</option>}</select></Field>
              <Field label={comp.calc === 'fixed' ? 'Default ₦' : 'Default %'}><input className={inputClass} type="number" min={0} step="0.01" required value={comp.default_value} onChange={(e) => setComp({ ...comp, default_value: e.target.value })} /></Field>
              <div className="flex items-end gap-3"><label className="flex items-center gap-1 text-sm"><input type="checkbox" checked={comp.statutory} onChange={(e) => setComp({ ...comp, statutory: e.target.checked })} /> Statutory</label><Button disabled={busy}>Add</Button></div>
            </form>
          </Card>
          <Card title="Components">
            {components.length === 0 ? <Empty>None yet.</Empty> : (
              <Table columns={['Name', 'Kind', 'Calculation', 'Default']}>
                {components.map((c) => (
                  <tr key={c.id}><Cell>{c.name}{c.statutory && <span className="ml-1"><Badge tone="blue">statutory</Badge></span>}</Cell><Cell>{c.kind}</Cell><Cell>{c.calc.replaceAll('_', ' ')}</Cell><Cell>{c.calc === 'fixed' ? n(c.default_value) : `${Number(c.default_value)}%`}</Cell></tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}

      {!loading && tab === 'mine' && (mine.length === 0 ? <Empty>No payslips yet.</Empty> : (
        <div className="grid gap-4 md:grid-cols-2">
          {mine.map((p) => (
            <Card key={p.id} title={`Payslip · ${p.period}`} description={p.run_status === 'paid' ? 'Paid' : 'Approved, awaiting payment'}>
              <dl className="space-y-1 text-sm">
                <div className="flex justify-between"><dt>Basic</dt><dd>{n(p.basic)}</dd></div>
                {p.earnings.map((e) => <div key={e.name} className="flex justify-between"><dt>{e.name}</dt><dd>{n(e.amount)}</dd></div>)}
                <div className="flex justify-between font-medium"><dt>Gross</dt><dd>{n(p.gross)}</dd></div>
                {p.deductions.map((d) => <div key={d.name} className="flex justify-between text-ink-muted"><dt>{d.name}</dt><dd>−{n(d.amount)}</dd></div>)}
                <div className="flex justify-between border-t border-line-soft pt-1 font-semibold"><dt>Net pay</dt><dd>{n(p.net)}</dd></div>
              </dl>
            </Card>
          ))}
        </div>
      ))}
    </PageShell>
  );
}
