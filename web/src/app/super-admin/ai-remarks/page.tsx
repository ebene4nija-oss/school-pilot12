'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Cell, Empty, ErrorNote, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * AI report-card remarks across schools: are the drafts good enough, and is
 * anyone reviewing them?
 *
 * Counts only — no remark text, no students. Read-only: approving a remark is
 * a teacher's decision, and nothing on this screen can make it.
 */

type Row = {
  school_id: number;
  name: string;
  subdomain: string;
  ai_enabled: boolean;
  remarks_feature_on: boolean;
  generated: number;
  approved_as_is: number;
  edited: number;
  rejected: number;
  pending: number;
  oldest_pending_at: string | null;
  reviewed_unrecorded: number;
  rework_rate: number | null;
};

type Report = {
  window_days: number;
  totals: { schools: number; generated: number; approved_as_is: number; edited: number; rejected: number; pending: number; rework_rate: number | null };
  schools: Row[];
};

const pct = (r: number | null) => (r === null ? '—' : `${Math.round(r * 100)}%`);

const age = (iso: string | null) => {
  if (!iso) return null;
  const days = Math.floor((Date.now() - new Date(iso.replace(' ', 'T')).getTime()) / 86_400_000);
  return days <= 0 ? 'today' : `${days} day${days === 1 ? '' : 's'}`;
};

export default function AiRemarksPage() {
  const [days, setDays] = useState('90');
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchApi<Report>(`/platform/ai-remarks?days=${days}`)
      .then((res) => {
        if (!cancelled) {
          setReport(res);
          setError(null);
        }
      })
      .catch((err) => !cancelled && setError(errorText(err, 'Could not load the remarks report.')));
    return () => {
      cancelled = true;
    };
  }, [days]);

  return (
    <PageShell
      title="AI remarks"
      subtitle="How schools review AI report-card remark drafts. Counts only; remarks are approved by teachers, never from here."
      actions={
        <select value={days} onChange={(e) => setDays(e.target.value)} className={`${inputClass} w-auto`} aria-label="Period">
          <option value="30">Last 30 days</option>
          <option value="90">Last 90 days</option>
          <option value="365">Last year</option>
        </select>
      }
    >
      <ErrorNote message={error} />

      {!report ? (
        !error && <Loading />
      ) : (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatTile label="Drafts generated" value={report.totals.generated.toLocaleString()} />
            <StatTile label="Approved as written" value={report.totals.approved_as_is.toLocaleString()} tone="green" />
            <StatTile label="Edited" value={report.totals.edited.toLocaleString()} tone="blue" />
            <StatTile label="Rejected" value={report.totals.rejected.toLocaleString()} tone="red" />
            <StatTile
              label="Waiting for a teacher"
              value={report.totals.pending.toLocaleString()}
              tone={report.totals.pending > 0 ? 'amber' : 'slate'}
              hint={`Rework rate ${pct(report.totals.rework_rate)}`}
            />
          </div>

          <Card
            title="By school"
            description="Rework rate is the share of reviewed drafts a teacher edited or rejected. A high rate means the drafts are not saving teachers time."
          >
            {report.schools.length === 0 ? (
              <Empty>No school has generated an AI remark yet.</Empty>
            ) : (
              <Table columns={['School', 'Generated', 'As written', 'Edited', 'Rejected', 'Rework', 'Waiting', 'Oldest waiting', 'AI']}>
                {report.schools.map((s) => (
                  <tr key={s.school_id}>
                    <Cell className="font-medium text-brand">
                      {s.name}
                      <span className="block text-xs font-normal text-ink-faint">{s.subdomain}</span>
                    </Cell>
                    <Cell>{s.generated}</Cell>
                    <Cell>{s.approved_as_is}</Cell>
                    <Cell>{s.edited}</Cell>
                    <Cell>{s.rejected}</Cell>
                    <Cell>
                      <Badge tone={s.rework_rate === null ? 'slate' : s.rework_rate > 0.5 ? 'red' : s.rework_rate > 0.25 ? 'amber' : 'green'}>
                        {pct(s.rework_rate)}
                      </Badge>
                    </Cell>
                    <Cell className={s.pending > 0 ? 'font-semibold text-caution' : ''}>{s.pending}</Cell>
                    <Cell>{age(s.oldest_pending_at) ?? '—'}</Cell>
                    <Cell>
                      {!s.ai_enabled ? (
                        <Badge tone="slate">Off</Badge>
                      ) : !s.remarks_feature_on ? (
                        <Badge tone="slate">Remarks off</Badge>
                      ) : (
                        <Badge tone="green">On</Badge>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
            {report.schools.some((s) => s.reviewed_unrecorded > 0) && (
              <p className="mt-3 text-xs text-ink-muted">
                Remarks reviewed before 26 Sep 2026 have no outcome recorded and are not counted as edited or as written.
              </p>
            )}
          </Card>
        </>
      )}
    </PageShell>
  );
}
