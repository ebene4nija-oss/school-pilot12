'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Card, Empty, ErrorNote, Loading, PageShell } from '@/components/Page';

/**
 * Principal's analytics (§7.14).
 *
 * Rendered generically from whatever `/analytics/principal` returns rather
 * than against a hardcoded field list. The endpoint's shape has changed twice
 * already, and a dashboard that silently drops a new metric — or crashes on a
 * removed one — is worse than one that shows what it was given.
 */

type Json = Record<string, unknown>;

export default function AnalyticsPage() {
  const [data, setData] = useState<Json | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<Json>('/analytics/principal')
      .then(setData)
      .catch((err) => setError(err instanceof Error ? err.message : 'Could not load analytics.'))
      .finally(() => setLoading(false));
  }, []);

  const entries = Object.entries(data ?? {});
  const scalars = entries.filter(([, v]) => typeof v === 'number' || typeof v === 'string');
  const groups = entries.filter(([, v]) => Array.isArray(v) || (typeof v === 'object' && v !== null));

  return (
    <PageShell title="Analytics" subtitle="How the school is doing this term.">
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : entries.length === 0 ? (
        <Empty>No analytics yet. Figures appear once attendance and scores have been entered.</Empty>
      ) : (
        <div className="space-y-6">
          {scalars.length > 0 && (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {scalars.map(([key, value]) => (
                <div key={key} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                  <p className="text-xs uppercase tracking-wide text-slate-500">{humanise(key)}</p>
                  <p className="mt-2 text-2xl font-semibold text-slate-900">{format(key, value)}</p>
                </div>
              ))}
            </div>
          )}

          {groups.map(([key, value]) => (
            <Card key={key} title={humanise(key)}>
              <Breakdown value={value} />
            </Card>
          ))}
        </div>
      )}
    </PageShell>
  );
}

function Breakdown({ value }: { value: unknown }) {
  if (Array.isArray(value)) {
    if (value.length === 0) return <Empty>Nothing to show yet.</Empty>;

    // Arrays of objects render as a table keyed on the first row's fields.
    if (typeof value[0] === 'object' && value[0] !== null) {
      const columns = Object.keys(value[0] as Json);

      return (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[30rem] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                {columns.map((c) => (
                  <th key={c} className="px-3 py-2 font-medium">
                    {humanise(c)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {(value as Json[]).map((row, i) => (
                <tr key={i}>
                  {columns.map((c) => (
                    <td key={c} className="px-3 py-2.5 text-slate-700">
                      {format(c, row[c])}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      );
    }

    return (
      <ul className="list-inside list-disc text-sm text-slate-700">
        {value.map((v, i) => (
          <li key={i}>{String(v)}</li>
        ))}
      </ul>
    );
  }

  return (
    <dl className="grid gap-3 sm:grid-cols-2">
      {Object.entries(value as Json).map(([k, v]) => (
        <div key={k} className="rounded-lg bg-slate-50 px-3 py-2.5">
          <dt className="text-xs uppercase tracking-wide text-slate-500">{humanise(k)}</dt>
          <dd className="mt-0.5 text-sm font-medium text-slate-900">{format(k, v)}</dd>
        </div>
      ))}
    </dl>
  );
}

function humanise(key: string): string {
  return key.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Money is Naira (CLAUDE.md), and the API returns minor units for anything
 * fee-shaped — rendering those raw would overstate every figure a hundredfold.
 */
function format(key: string, value: unknown): string {
  if (value === null || value === undefined) return '—';

  if (typeof value === 'number') {
    if (/kobo$/i.test(key)) {
      return `₦${(value / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`;
    }
    if (/(amount|revenue|fees?|outstanding|paid|balance)/i.test(key)) {
      return `₦${value.toLocaleString('en-NG')}`;
    }
    if (/(rate|percent|percentage|average)/i.test(key)) {
      return `${value}%`;
    }
    return value.toLocaleString('en-NG');
  }

  if (typeof value === 'boolean') return value ? 'Yes' : 'No';

  return String(value);
}
