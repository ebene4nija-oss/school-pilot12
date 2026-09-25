'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Loading,
  Meter,
  PageShell,
  StatTile,
  Table,
  nairaAmount,
} from '@/components/Page';

/**
 * The proprietor's view of the school.
 *
 * This page was entirely fictional: ₦48,650,000 collected, "160 Defaulters
 * Flagged", two invented families with outstanding balances, and two "AI
 * Executive Insights" written as static HTML. Its buttons called `alert()`.
 *
 * Every figure now comes from `/analytics/principal` and `/finance/defaulters`,
 * and the insight feed is the rule-based one the API actually computes. Where
 * there is no data the page says so rather than showing a plausible number.
 */

interface Principal {
  population: { active: number; total: number; by_status: Record<string, number> };
  revenue: {
    total_collected: number;
    total_invoiced: number;
    fee_recovery_rate_percentage: number;
  };
  attendance_today: { present_count: number; marked_count: number; rate_percentage: number };
  best_performing_classes: { class_name: string; average_score: number | string }[];
  insights?: {
    grade_drops?: Insight[];
    revenue_forecast?: Record<string, unknown> | null;
    attrition_risk?: Insight[];
  };
}

/** The insight services are free-form, so nothing here assumes a field list. */
type Insight = Record<string, unknown>;

interface Defaulters {
  summary: {
    defaulting_students: number;
    invoices_outstanding: number;
    total_outstanding: number;
    by_ageing?: Record<string, { invoices: number; amount?: number }>;
  };
  data?: DefaulterRow[];
}

interface DefaulterRow {
  invoice_id: number;
  student_id: number;
  student_name: string | null;
  admission_number: string | null;
  class: string | null;
  balance: number;
  days_overdue: number;
  ageing_bucket: string;
}

export default function DirectorConsole() {
  const [principal, setPrincipal] = useState<Principal | null>(null);
  const [debtors, setDebtors] = useState<Defaulters | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const [stats, owing] = await Promise.all([
          fetchApi<Principal>('/analytics/principal'),
          // A school with nothing billed yet is not an error.
          fetchApi<Defaulters>('/finance/defaulters').catch(() => null),
        ]);

        if (cancelled) return;

        setPrincipal(stats);
        setDebtors(owing);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the director console.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const revenue = principal?.revenue;
  const outstanding = debtors?.summary.total_outstanding ?? 0;
  const insights = [
    ...(principal?.insights?.grade_drops ?? []).map((i) => ({ kind: 'Academic' as const, body: i })),
    ...(principal?.insights?.attrition_risk ?? []).map((i) => ({ kind: 'Attrition' as const, body: i })),
  ];

  // Top balances first — a proprietor chases the largest debt, not the oldest.
  const topDebtors = [...(debtors?.data ?? [])].sort((a, b) => b.balance - a.balance).slice(0, 8);

  return (
    <PageShell
      title="Director console"
      subtitle="Enrolment, fee collection and risk for the whole school."
    >
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : !principal ? (
        <Empty>No figures yet.</Empty>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile
              label="Students on the roll"
              value={principal.population.active.toLocaleString('en-NG')}
              hint={`${principal.population.total.toLocaleString('en-NG')} records including leavers`}
            />
            <StatTile
              label="Fees collected"
              value={nairaAmount(revenue?.total_collected ?? 0)}
              tone="green"
              hint={
                revenue && revenue.total_invoiced > 0
                  ? `${revenue.fee_recovery_rate_percentage}% of ${nairaAmount(revenue.total_invoiced)} billed`
                  : 'Nothing billed yet'
              }
            />
            <StatTile
              label="Outstanding"
              value={debtors ? nairaAmount(outstanding) : '—'}
              tone={outstanding > 0 ? 'red' : 'green'}
              hint={
                debtors
                  ? `${debtors.summary.defaulting_students} student(s) across ${debtors.summary.invoices_outstanding} invoice(s)`
                  : 'No invoices raised yet'
              }
            />
            <StatTile
              label="Attendance today"
              value={
                principal.attendance_today.marked_count > 0
                  ? `${principal.attendance_today.rate_percentage}%`
                  : 'Not marked'
              }
              tone={principal.attendance_today.marked_count > 0 ? 'blue' : 'slate'}
              hint={
                principal.attendance_today.marked_count > 0
                  ? `${principal.attendance_today.present_count.toLocaleString('en-NG')} of ${principal.attendance_today.marked_count.toLocaleString('en-NG')} marked`
                  : 'No register taken yet today'
              }
            />
          </div>

          <Card
            title="Risk feed"
            description="Rule-based alerts computed from this school's own scores and payment history."
          >
            {insights.length === 0 ? (
              <Empty>
                No alerts. These appear once there is enough score and payment history to compare
                against.
              </Empty>
            ) : (
              <ul className="space-y-2">
                {insights.map((item, i) => (
                  <li
                    key={i}
                    className="flex items-start gap-3 rounded-lg border border-line-soft bg-surface-sunken p-3"
                  >
                    <Badge tone={item.kind === 'Attrition' ? 'red' : 'amber'}>{item.kind}</Badge>
                    <p className="text-sm text-ink">{describe(item.body)}</p>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card
              title="Fee collection"
              description="Against what has actually been billed this term."
            >
              {!revenue || revenue.total_invoiced === 0 ? (
                <Empty>Nothing has been billed yet, so there is nothing to collect against.</Empty>
              ) : (
                <div className="space-y-4">
                  <div>
                    <div className="mb-1 flex justify-between text-sm">
                      <span className="text-ink">Collected</span>
                      <span className="font-semibold text-positive">
                        {nairaAmount(revenue.total_collected)} ({revenue.fee_recovery_rate_percentage}%)
                      </span>
                    </div>
                    <Meter
                      value={revenue.fee_recovery_rate_percentage}
                      tone={
                        revenue.fee_recovery_rate_percentage >= 80
                          ? 'green'
                          : revenue.fee_recovery_rate_percentage >= 50
                            ? 'amber'
                            : 'red'
                      }
                    />
                  </div>

                  <dl className="space-y-2 text-sm">
                    <div className="flex justify-between">
                      <dt className="text-ink-muted">Billed</dt>
                      <dd className="font-medium text-brand">
                        {nairaAmount(revenue.total_invoiced)}
                      </dd>
                    </div>
                    <div className="flex justify-between">
                      <dt className="text-ink-muted">Outstanding</dt>
                      <dd className="font-medium text-negative">{nairaAmount(outstanding)}</dd>
                    </div>
                  </dl>

                  {debtors?.summary.by_ageing && Object.keys(debtors.summary.by_ageing).length > 0 && (
                    <div>
                      <p className="mb-2 text-xs uppercase tracking-wide text-ink-muted">
                        How overdue
                      </p>
                      <ul className="space-y-1 text-sm">
                        {Object.entries(debtors.summary.by_ageing).map(([bucket, info]) => (
                          <li key={bucket} className="flex justify-between">
                            <span className="text-ink-muted">{bucket}</span>
                            <span className="text-brand">{info.invoices} invoice(s)</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}

                  <Link
                    href="/finance"
                    className="inline-block text-sm font-medium text-info hover:underline"
                  >
                    Fees &amp; finance →
                  </Link>
                </div>
              )}
            </Card>

            <Card title="Class averages" description="Mean score across every entry, best first.">
              {principal.best_performing_classes.length === 0 ? (
                <Empty>No scores have been entered yet.</Empty>
              ) : (
                <ul className="space-y-3">
                  {principal.best_performing_classes.map((row) => {
                    const average = Number(row.average_score) || 0;

                    return (
                      <li key={row.class_name} className="space-y-1">
                        <div className="flex justify-between text-sm">
                          <span className="font-medium text-ink">{row.class_name}</span>
                          <span className="text-ink-muted">{average.toFixed(1)}%</span>
                        </div>
                        <Meter
                          value={average}
                          tone={average >= 70 ? 'green' : average >= 50 ? 'amber' : 'red'}
                        />
                      </li>
                    );
                  })}
                </ul>
              )}
            </Card>
          </div>

          <Card
            title="Largest outstanding balances"
            description={
              topDebtors.length > 0
                ? 'Chase these from Fees & finance, where reminders are sent and contacts are recorded.'
                : undefined
            }
          >
            {topDebtors.length === 0 ? (
              <Empty>No outstanding balances.</Empty>
            ) : (
              <Table columns={['Student', 'Class', 'Outstanding', 'Overdue', 'Ageing']}>
                {topDebtors.map((row) => (
                  <tr key={row.invoice_id}>
                    <Cell className="font-medium text-brand">
                      {row.student_name ?? 'Unknown'}
                      {row.admission_number && (
                        <span className="block text-xs text-ink-muted">{row.admission_number}</span>
                      )}
                    </Cell>
                    <Cell>{row.class ?? '—'}</Cell>
                    <Cell className="font-semibold text-negative">{nairaAmount(row.balance)}</Cell>
                    <Cell>{row.days_overdue > 0 ? `${row.days_overdue} day(s)` : 'Not yet due'}</Cell>
                    <Cell className="text-xs">{row.ageing_bucket}</Cell>
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

/**
 * Render one insight without assuming its shape.
 *
 * The insight services return different keys per rule and have changed before;
 * a hardcoded field list would silently drop a new alert, which for a risk feed
 * is the one failure that matters.
 */
function describe(insight: Insight): string {
  if (typeof insight === 'string') return insight;

  const preferred = ['message', 'summary', 'description', 'detail', 'note'];

  for (const key of preferred) {
    const value = insight[key];
    if (typeof value === 'string' && value.trim()) return value;
  }

  return Object.entries(insight)
    .filter(([, v]) => v !== null && typeof v !== 'object')
    .map(([k, v]) => `${k.replace(/_/g, ' ')}: ${String(v)}`)
    .join(' · ');
}
