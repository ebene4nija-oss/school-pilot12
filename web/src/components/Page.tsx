'use client';

import React from 'react';
import Sidebar from '@/components/Sidebar';

/**
 * Shared page furniture for the admin portal.
 *
 * These pages were sidebar links pointing at routes that did not exist — the
 * navigation advertised Staff, Timetable, CBT, Notifications and Analytics and
 * every one of them 404'd. Building five screens meant either five copies of
 * the same shell markup or this file.
 */

export function PageShell({
  title,
  subtitle,
  actions,
  children,
}: {
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen bg-canvas text-ink">
      <Sidebar />
      <main className="flex-1 overflow-x-hidden p-6 lg:p-8">
        <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="font-headline text-2xl font-bold text-brand">{title}</h1>
            {subtitle && <p className="mt-1 text-sm text-ink-muted">{subtitle}</p>}
          </div>
          {actions}
        </header>
        {children}
      </main>
    </div>
  );
}

export function Card({
  title,
  description,
  children,
  className = '',
}: {
  title?: string;
  description?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section className={`rounded-xl bg-surface p-5 shadow-sm ring-1 ring-line-soft ${className}`}>
      {title && <h2 className="font-headline text-sm font-semibold text-brand">{title}</h2>}
      {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
      <div className={title || description ? 'mt-4' : ''}>{children}</div>
    </section>
  );
}

/** Errors are shown, never swallowed into console.error like the old pages. */
export function ErrorNote({ message }: { message: string | null }) {
  if (!message) return null;

  return (
    <p role="alert" className="mb-6 rounded-lg border border-negative/20 bg-negative-soft px-4 py-3 text-sm text-negative">
      {message}
    </p>
  );
}

export function Empty({ children }: { children: React.ReactNode }) {
  return (
    <p className="rounded-lg border border-dashed border-line px-4 py-8 text-center text-sm text-ink-muted">
      {children}
    </p>
  );
}

export function Loading() {
  return <p className="text-sm text-ink-muted">Loading…</p>;
}

/**
 * Tables scroll inside their own container — a 12-column broadsheet must not
 * make the whole page scroll sideways on a laptop.
 */
export function Table({
  columns,
  children,
}: {
  columns: string[];
  children: React.ReactNode;
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[36rem] text-left text-sm">
        <thead>
          <tr className="border-b border-line-soft text-xs uppercase tracking-wide text-ink-faint">
            {columns.map((c) => (
              <th key={c} className="px-3 py-2 font-medium">
                {c}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-line-soft">{children}</tbody>
      </table>
    </div>
  );
}

export function Cell({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <td className={`px-3 py-2.5 text-ink ${className}`}>{children}</td>;
}

const BADGE_TONES: Record<string, string> = {
  green: 'bg-positive-soft text-positive',
  amber: 'bg-caution-soft text-caution',
  red: 'bg-negative-soft text-negative',
  slate: 'bg-surface-raised text-ink-muted',
  blue: 'bg-info-soft text-info',
};

export function Badge({ tone = 'slate', children }: { tone?: keyof typeof BADGE_TONES; children: React.ReactNode }) {
  return (
    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${BADGE_TONES[tone] ?? BADGE_TONES.slate}`}>
      {children}
    </span>
  );
}

export function Button({
  variant = 'primary',
  className = '',
  ...props
}: { variant?: 'primary' | 'secondary' | 'accent' | 'danger' } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const styles = {
    primary: 'bg-brand text-white hover:bg-brand-soft',
    secondary: 'border border-line bg-surface text-ink hover:bg-surface-sunken',
    // Amber is reserved for the single most important action on a screen.
    // Used on more than one, it stops meaning anything.
    accent: 'bg-accent text-accent-ink hover:bg-accent-strong',
    danger: 'bg-negative text-white hover:opacity-90',
  }[variant];

  return (
    <button
      {...props}
      className={`rounded-lg px-3.5 py-2 text-sm font-medium transition disabled:opacity-60 ${styles} ${className}`}
    />
  );
}

export function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-ink">{label}</span>
      {children}
    </label>
  );
}

export const inputClass =
  'w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20';

/** Naira, not $ — see CLAUDE.md. */
export function naira(kobo: number): string {
  return `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * Naira from a value that is already in Naira.
 *
 * Separate from `naira` because that one takes kobo. Several endpoints report
 * money in Naira directly (`Payment.amount`, `cost_naira`), and feeding one of
 * those to `naira()` divides a school's termly collections by a hundred — a
 * wrong number that looks entirely plausible on a dashboard.
 */
export function nairaAmount(value: number): string {
  return `₦${Number(value ?? 0).toLocaleString('en-NG', { maximumFractionDigits: 0 })}`;
}

/**
 * One headline figure.
 *
 * `hint` is for what the number means, never for invented movement: the old
 * dashboard tiles carried captions like "+4.2% from last term" beside
 * hardcoded values, with nothing anywhere computing a comparison.
 */
export function StatTile({
  label,
  value,
  hint,
  tone = 'slate',
}: {
  label: string;
  value: React.ReactNode;
  hint?: React.ReactNode;
  tone?: 'slate' | 'green' | 'amber' | 'red' | 'blue';
}) {
  // The rule down the left edge is what makes a row of tiles scannable: the
  // figures differ in kind, not just in value, and colour says which is which
  // before the label is read.
  const { text, rule } = {
    slate: { text: 'text-brand', rule: 'border-l-brand' },
    green: { text: 'text-positive', rule: 'border-l-positive' },
    amber: { text: 'text-caution', rule: 'border-l-accent' },
    red: { text: 'text-negative', rule: 'border-l-negative' },
    blue: { text: 'text-info', rule: 'border-l-brand-tint' },
  }[tone];

  return (
    <div className={`rounded-xl border-l-4 bg-surface p-5 shadow-sm ring-1 ring-line-soft ${rule}`}>
      <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</p>
      <p className={`font-headline mt-2 text-2xl font-bold ${text}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </div>
  );
}

/** A labelled progress bar. Width is clamped so bad data cannot overflow it. */
export function Meter({ value, tone = 'slate' }: { value: number; tone?: 'slate' | 'green' | 'amber' | 'red' }) {
  const pct = Math.max(0, Math.min(100, Number.isFinite(value) ? value : 0));
  const fill = {
    slate: 'bg-brand',
    green: 'bg-positive',
    amber: 'bg-accent',
    red: 'bg-negative',
  }[tone];

  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-surface-raised" role="presentation">
      <div
        className={`h-full rounded-full transition-[width] duration-500 ${fill}`}
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}
