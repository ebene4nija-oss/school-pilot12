'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Empty, ErrorNote, Field, Loading, Meter, PageShell, StatTile, Table, inputClass, nairaAmount } from '@/components/Page';

/**
 * Who has paid and who is owing, for one term, by section, class and arm.
 *
 * The defaulter list on Fees & finance answers "who owes"; this answers the
 * whole question a principal asks the bursar: how much each class and arm
 * has paid against what it was billed, how many children have cleared, and
 * who has not even been invoiced. Amounts are Naira.
 */

type Status = 'paid' | 'part_paid' | 'owing' | 'not_invoiced';
type Totals = { students: number; billed: number; paid: number; outstanding: number; collection_rate: number | null; counts: Record<Status, number> };
type ArmRow = Totals & { arm_id: number | null; arm: string };
type ClassRow = Totals & { class_id: number; class: string; section: string; arms: ArmRow[] };
type StudentRow = { student_id: number; name: string | null; admission_number: string | null; class: string; arm: string | null; class_id: number; arm_id: number | null; billed: number; paid: number; balance: number; status: Status };
type Report = { term: { id: number; name: string }; totals: Totals; sections: (Totals & { section: string; label: string })[]; classes: ClassRow[]; students: StudentRow[] };
type Term = { id: number; name: string; session_name?: string | null };

const STATUS: Record<Status, { label: string; tone: 'green' | 'amber' | 'red' | 'slate' }> = {
  paid: { label: 'Paid', tone: 'green' },
  part_paid: { label: 'Part paid', tone: 'amber' },
  owing: { label: 'Owing', tone: 'red' },
  not_invoiced: { label: 'Not invoiced', tone: 'slate' },
};

const SECTIONS: [string, string][] = [['', 'All sections'], ['nursery', 'Nursery'], ['primary', 'Primary'], ['secondary', 'Secondary']];

export default function FeeStatusPage() {
  const [terms, setTerms] = useState<Term[]>([]);
  const [termId, setTermId] = useState('');
  const [section, setSection] = useState('');
  const [classId, setClassId] = useState('');
  const [armId, setArmId] = useState('');
  const [status, setStatus] = useState<Status | ''>('');
  const [search, setSearch] = useState('');
  const [report, setReport] = useState<Report | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<{ data: Term[]; current_term_id: number | null }>('/terms')
      .then((r) => {
        setTerms(r.data ?? []);
        const pick = r.current_term_id ?? r.data?.[0]?.id;
        if (pick) setTermId(String(pick));
      })
      .catch((err) => setError(errorText(err, 'Could not load terms.')));
  }, []);

  // One fetch per term and section; class, arm, status and search narrow on screen.
  const load = useCallback(async () => {
    if (!termId) return;
    setLoading(true);
    try {
      setReport(await fetchApi<Report>(`/finance/fee-status?term_id=${termId}${section ? `&section=${section}` : ''}`));
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load who has paid.'));
    } finally {
      setLoading(false);
    }
  }, [termId, section]);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const cls = report?.classes.find((c) => String(c.class_id) === classId) ?? null;
  const arm = cls?.arms.find((a) => String(a.arm_id ?? 0) === armId) ?? null;
  const scope: Totals | null = arm ?? cls ?? report?.totals ?? null;

  const students = useMemo(() => {
    const q = search.trim().toLowerCase();
    return (report?.students ?? []).filter((s) =>
      (!classId || String(s.class_id) === classId)
      && (!armId || String(s.arm_id ?? 0) === armId)
      && (!status || s.status === status)
      && (!q || `${s.name ?? ''} ${s.admission_number ?? ''}`.toLowerCase().includes(q)));
  }, [report, classId, armId, status, search]);

  const scopeName = [cls?.class, arm ? arm.arm : null].filter(Boolean).join(' ') || SECTIONS.find(([k]) => k === section)?.[1] || 'All sections';

  return (
    <PageShell title="Who has paid" subtitle="Fees billed, paid and owing this term, by section, class and arm.">
      <ErrorNote message={error} />

      <div className="mb-5 flex flex-wrap items-end gap-3">
        <Field label="Term">
          <select className={`${inputClass} w-56`} value={termId} onChange={(e) => { setTermId(e.target.value); setClassId(''); setArmId(''); }}>
            {terms.map((t) => <option key={t.id} value={t.id}>{t.name}{t.session_name ? ` — ${t.session_name}` : ''}</option>)}
          </select>
        </Field>
        <div className="flex flex-wrap gap-2" role="tablist" aria-label="Section">
          {SECTIONS.map(([k, l]) => (
            <button key={k} type="button" role="tab" aria-selected={section === k}
              onClick={() => { setSection(k); setClassId(''); setArmId(''); }}
              className={`rounded-lg px-3.5 py-2 text-sm font-medium ${section === k ? 'bg-brand text-white' : 'bg-surface text-ink ring-1 ring-line-soft hover:bg-surface-sunken'}`}>
              {l}
            </button>
          ))}
        </div>
        <Link href="/finance" className="ml-auto text-sm text-brand underline">Chase debtors on Fees &amp; finance →</Link>
      </div>

      {!report || loading ? <Loading /> : (
        <>
          {scope && (
            <>
              <p className="mb-2 text-sm font-semibold text-ink">{scopeName} · {report.term.name}</p>
              <div className="mb-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Billed" value={nairaAmount(scope.billed)} hint={`${scope.students} students`} />
                <StatTile label="Paid" value={nairaAmount(scope.paid)} tone="green" hint={scope.collection_rate !== null ? `${scope.collection_rate}% collected` : 'Nothing billed yet'} />
                <StatTile label="Outstanding" value={nairaAmount(scope.outstanding)} tone="red" />
                <StatTile label="Cleared" value={`${scope.counts.paid} of ${scope.students}`} tone="blue" hint={`${scope.counts.part_paid} part paid · ${scope.counts.owing} owing · ${scope.counts.not_invoiced} not invoiced`} />
              </div>
            </>
          )}

          {report.classes.length === 0 ? <Empty>No students on the roll for this section.</Empty> : (
            <Card title="By class" description="Pick a class to see its arms and narrow the list below." className="mb-6">
              <Table columns={['Class', 'Students', 'Billed', 'Paid', 'Outstanding', 'Collected', 'Paid · part · owing · none']}>
                {report.classes.map((c) => (
                  <React.Fragment key={c.class_id}>
                    <ClassLine row={c} label={c.class} on={classId === String(c.class_id) && !armId}
                      onPick={() => { setClassId(classId === String(c.class_id) && !armId ? '' : String(c.class_id)); setArmId(''); }} />
                    {classId === String(c.class_id) && c.arms.length > 1 && c.arms.map((a) => (
                      <ClassLine key={a.arm_id ?? 0} row={a} label={`↳ ${c.class} ${a.arm}`} on={armId === String(a.arm_id ?? 0)} indent
                        onPick={() => setArmId(armId === String(a.arm_id ?? 0) ? '' : String(a.arm_id ?? 0))} />
                    ))}
                  </React.Fragment>
                ))}
              </Table>
            </Card>
          )}

          <Card title={`Students — ${scopeName}`}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
              {(['', 'paid', 'part_paid', 'owing', 'not_invoiced'] as (Status | '')[]).map((k) => (
                <button key={k || 'all'} type="button" onClick={() => setStatus(k)}
                  className={`rounded-full px-3 py-1 text-xs font-semibold ring-1 ${status === k ? 'bg-brand text-white ring-brand' : 'bg-surface text-ink-muted ring-line-soft'}`}>
                  {k ? `${STATUS[k].label} (${scope?.counts[k] ?? 0})` : `Everyone (${scope?.students ?? 0})`}
                </button>
              ))}
              <input className={`${inputClass} ml-auto w-56`} placeholder="Search name or admission no." value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search students" />
            </div>
            {students.length === 0 ? <Empty>No students match.</Empty> : (
              <Table columns={['Student', 'Class', 'Billed', 'Paid', 'Balance', 'Status']}>
                {students.map((s) => (
                  <tr key={s.student_id}>
                    <Cell><span className="font-medium">{s.name ?? '—'}</span><span className="block text-xs text-ink-muted">{s.admission_number}</span></Cell>
                    <Cell>{s.class}{s.arm ? ` ${s.arm}` : ''}</Cell>
                    <Cell>{s.status === 'not_invoiced' ? '—' : nairaAmount(s.billed)}</Cell>
                    <Cell>{s.status === 'not_invoiced' ? '—' : nairaAmount(s.paid)}</Cell>
                    <Cell className={s.balance > 0 ? 'font-semibold text-negative' : ''}>{s.status === 'not_invoiced' ? '—' : nairaAmount(s.balance)}</Cell>
                    <Cell><Badge tone={STATUS[s.status].tone}>{STATUS[s.status].label}</Badge></Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </>
      )}
    </PageShell>
  );
}

function ClassLine({ row, label, on, indent = false, onPick }: { row: Totals; label: string; on: boolean; indent?: boolean; onPick: () => void }) {
  const rate = row.collection_rate ?? 0;
  return (
    <tr className={`cursor-pointer ${on ? 'bg-brand/10' : 'hover:bg-surface-sunken'}`} onClick={onPick}>
      <Cell className={indent ? 'pl-8 text-sm' : 'font-medium text-brand'}>
        <button type="button" className="text-left underline-offset-2 hover:underline" aria-pressed={on}>{label}</button>
      </Cell>
      <Cell>{row.students}</Cell>
      <Cell>{nairaAmount(row.billed)}</Cell>
      <Cell>{nairaAmount(row.paid)}</Cell>
      <Cell className={row.outstanding > 0 ? 'text-negative' : ''}>{nairaAmount(row.outstanding)}</Cell>
      <Cell className="w-32">
        {row.collection_rate === null ? <span className="text-xs text-ink-muted">—</span> : (
          <><Meter value={rate} tone={rate >= 80 ? 'green' : rate >= 50 ? 'amber' : 'red'} /><span className="text-xs text-ink-muted">{row.collection_rate}%</span></>
        )}
      </Cell>
      <Cell className="text-xs">{row.counts.paid} · {row.counts.part_paid} · {row.counts.owing} · {row.counts.not_invoiced}</Cell>
    </tr>
  );
}
