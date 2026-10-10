'use client';

import React, { useState } from 'react';
import { Card, Empty } from '@/components/Page';

/**
 * A student's academic record as the history endpoints describe it: progress
 * over time, session averages, then each term opening to its subjects. Used
 * for a student's own record; the server decides what each viewer may see
 * (families get released terms only, no class comparisons).
 */

export interface HistoryTerm {
  id: number;
  session: string;
  term: string;
  class: string;
  verified?: boolean;
  figures: {
    subjects?: { subject: string; total: number | null; grade: string | null }[];
    average?: number | null;
    position?: number | null;
    class_size?: number | null;
    form_teacher_comment?: string | null;
    principal_comment?: string | null;
  };
}

export interface AcademicHistory {
  student?: { name?: string; class?: string; admission_number?: string };
  terms: HistoryTerm[];
  sessions: { session: string; session_average: number | null }[];
  timeline?: { session: string; class: string; outcome: string | null; destination?: string | null }[];
  partial_history?: boolean;
}

function Trend({ terms }: { terms: HistoryTerm[] }) {
  const pts = terms.filter((t) => t.figures.average != null).map((t) => ({ label: `${t.term.split(' ')[0]} ${t.session}`, v: Number(t.figures.average) }));
  if (pts.length < 2) return null;
  const w = 600, h = 140, pad = 24;
  const x = (i: number) => pad + (i * (w - pad * 2)) / (pts.length - 1);
  const y = (v: number) => h - pad - (Math.max(0, Math.min(100, v)) / 100) * (h - pad * 2);
  const path = pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.v).toFixed(1)}`).join(' ');
  const change = pts[pts.length - 1].v - pts[0].v;
  return (
    <Card title="Academic progress" description={`Term averages over time · ${change >= 0 ? '▲' : '▼'} ${Math.abs(change).toFixed(1)} since the first term shown`}>
      <svg viewBox={`0 0 ${w} ${h}`} className="h-36 w-full" role="img" aria-label={`Term averages from ${pts[0].v.toFixed(1)}% to ${pts[pts.length - 1].v.toFixed(1)}%`}>
        {[25, 50, 75].map((g) => <line key={g} x1={pad} x2={w - pad} y1={y(g)} y2={y(g)} className="stroke-line-soft" strokeDasharray="3 4" />)}
        <path d={path} fill="none" className="stroke-brand" strokeWidth={3} strokeLinejoin="round" strokeLinecap="round" />
        {pts.map((p, i) => (
          <g key={i}>
            <circle cx={x(i)} cy={y(p.v)} r={4} className="fill-brand" />
            <text x={x(i)} y={y(p.v) - 9} textAnchor="middle" className="fill-ink text-[11px]">{p.v.toFixed(0)}</text>
          </g>
        ))}
      </svg>
    </Card>
  );
}

export default function AcademicHistoryView({ data }: { data: AcademicHistory }) {
  const [open, setOpen] = useState<number | null>(null);

  if (data.terms.length === 0) {
    return <Empty>No term records yet. Each term appears here once the school closes it and releases the results.</Empty>;
  }

  return (
    <div className="space-y-5">
      {data.partial_history && <p className="text-sm text-caution">Some records here are not yet verified by the school.</p>}
      <Trend terms={data.terms} />
      {data.sessions.length > 0 && (
        <Card title="By session">
          <ul className="divide-y divide-line-soft text-sm">
            {data.sessions.map((s) => <li key={s.session} className="flex justify-between py-2"><span>{s.session}</span><span className="font-semibold">{s.session_average == null ? '—' : `${s.session_average.toFixed(1)}%`}</span></li>)}
          </ul>
        </Card>
      )}
      <Card title="Terms" description="Open a term to see each subject.">
        <ul className="space-y-2">
          {[...data.terms].reverse().map((t) => (
            <li key={t.id} className="rounded-lg ring-1 ring-line-soft">
              <button type="button" className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left" onClick={() => setOpen(open === t.id ? null : t.id)} aria-expanded={open === t.id}>
                <span>
                  <span className="font-medium text-brand">{t.term} · {t.session}</span>
                  <span className="block text-xs text-ink-muted">
                    {[t.class, t.figures.average != null ? `Average ${Number(t.figures.average).toFixed(1)}%` : null, t.figures.position ? `Position ${t.figures.position}${t.figures.class_size ? ` of ${t.figures.class_size}` : ''}` : null].filter(Boolean).join(' · ')}
                  </span>
                </span>
                <span aria-hidden="true">{open === t.id ? '▾' : '▸'}</span>
              </button>
              {open === t.id && (
                <div className="border-t border-line-soft px-4 py-3 text-sm">
                  <table className="w-full">
                    <thead><tr className="text-left text-xs uppercase tracking-wide text-ink-faint"><th className="py-1 font-medium">Subject</th><th className="py-1 text-right font-medium">Total</th><th className="py-1 text-right font-medium">Grade</th></tr></thead>
                    <tbody>
                      {(t.figures.subjects ?? []).map((s) => <tr key={s.subject}><td className="py-1">{s.subject}</td><td className="py-1 text-right font-medium">{s.total ?? '—'}</td><td className="py-1 text-right">{s.grade ?? ''}</td></tr>)}
                    </tbody>
                  </table>
                  {t.figures.form_teacher_comment && <p className="mt-3"><span className="text-ink-muted">Form teacher:</span> {t.figures.form_teacher_comment}</p>}
                  {t.figures.principal_comment && <p className="mt-1"><span className="text-ink-muted">Principal:</span> {t.figures.principal_comment}</p>}
                </div>
              )}
            </li>
          ))}
        </ul>
      </Card>
      {data.timeline && data.timeline.length > 0 && (
        <Card title="School journey">
          <ul className="space-y-1 text-sm">
            {data.timeline.map((e, i) => <li key={i}>{e.session} · {e.class}{e.outcome ? <span className="text-ink-muted"> · {e.outcome.replaceAll('_', ' ')}</span> : null}{e.destination ? <span className="text-ink-muted"> · to {e.destination}</span> : null}</li>)}
          </ul>
        </Card>
      )}
    </div>
  );
}
