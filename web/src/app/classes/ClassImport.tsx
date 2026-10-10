'use client';

import React, { useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, Table, Cell } from '@/components/Page';

/**
 * Many classes and arms at once, from a pasted list or a CSV:
 *
 *   class, level, order, arms
 *   JSS 1, junior_secondary, 1, A;B;C
 *
 * Level and order may be left out (level is worked out from names like JSS 1,
 * SS 2, Primary 3, Nursery 1). Arms are separated by ; so a CSV's commas stay
 * columns. Preview first; nothing is saved until "Create". Safe to run twice:
 * what already exists is left alone.
 */

interface PlanRow { class: string; level: string; exists: boolean; new_arms: string[] }
interface Result { plan: PlanRow[]; summary: { classes_to_create: number; arms_to_create: number; already_there: number }; errors: { row: number; message: string }[]; committed: boolean; message?: string }

const LEVEL: Record<string, string> = { nursery: 'Nursery', primary: 'Primary', junior_secondary: 'JSS', senior_secondary: 'SS', secondary: 'Secondary' };

function parse(text: string) {
  return text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter((l) => l && !/^class\s*,/i.test(l))
    .map((l) => {
      const [cls, level, order, arms] = l.split(',').map((c) => c.trim());
      return { class: cls, level: level || null, order: order && /^\d+$/.test(order) ? Number(order) : null, arms: arms ?? '' };
    })
    .filter((r) => r.class);
}

export default function ClassImport({ onDone }: { onDone: () => void }) {
  const [text, setText] = useState('');
  const [result, setResult] = useState<Result | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send(commit: boolean) {
    const rows = parse(text);
    if (rows.length === 0) {
      setError('Paste at least one line, e.g. "JSS 1, junior_secondary, 1, A;B;C".');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const r = await fetchApi<Result>('/classes/import', { method: 'POST', body: JSON.stringify({ rows, commit }) });
      setResult(r);
      if (r.committed) {
        setText('');
        onDone();
      }
    } catch (err) {
      setError(errorText(err, 'Could not read that list.'));
    } finally {
      setBusy(false);
    }
  }

  const template = 'class,level,order,arms\nJSS 1,junior_secondary,1,A;B;C\nJSS 2,junior_secondary,2,A;B\nSS 1,senior_secondary,4,Science;Arts;Commercial\nPrimary 1,primary,1,Gold;Silver\n';

  return (
    <Card title="Import many classes and arms" description="Paste a list or choose a CSV: class, level, order, arms (arms separated by ;). Level and order are optional. You see a preview before anything is saved, and running it twice never duplicates." className="mb-6">
      <div className="space-y-3">
        <textarea
          className="min-h-[8rem] w-full rounded-lg border border-line bg-surface px-3 py-2 font-mono text-sm"
          placeholder={'JSS 1, junior_secondary, 1, A;B;C\nJSS 2, , 2, A;B\nSS 1, senior_secondary, 4, Science;Arts'}
          value={text}
          onChange={(e) => { setText(e.target.value); setResult(null); }}
        />
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <label className="cursor-pointer text-brand underline">
            Choose a CSV
            <input type="file" accept=".csv,text/csv,text/plain" className="sr-only" onChange={async (e) => { const f = e.target.files?.[0]; if (f) { setText(await f.text()); setResult(null); } e.target.value = ''; }} />
          </label>
          <a className="text-brand underline" download="classes-template.csv" href={`data:text/csv;charset=utf-8,${encodeURIComponent(template)}`}>Download a template</a>
          <Button type="button" variant="secondary" disabled={busy || !text.trim()} onClick={() => send(false)}>Preview</Button>
          {result && !result.committed && result.errors.length === 0 && (result.summary.classes_to_create + result.summary.arms_to_create) > 0 && (
            <Button type="button" disabled={busy} onClick={() => send(true)}>Create {result.summary.classes_to_create} class(es) and {result.summary.arms_to_create} arm(s)</Button>
          )}
        </div>
        {error && <p className="text-sm text-negative">{error}</p>}
        {result && (
          <div className="space-y-2">
            {result.committed && <p className="text-sm text-positive">{result.message}</p>}
            {result.errors.map((e) => <p key={e.row} className="text-sm text-negative">Line {e.row}: {e.message}</p>)}
            {!result.committed && result.errors.length === 0 && result.summary.classes_to_create + result.summary.arms_to_create === 0 && (
              <p className="text-sm text-ink-muted">Everything in this list is already set up. Nothing to add.</p>
            )}
            <Table columns={['Class', 'Level', 'Status', 'Arms to add']}>
              {result.plan.map((p) => (
                <tr key={p.class}>
                  <Cell className="font-medium">{p.class}</Cell>
                  <Cell>{LEVEL[p.level] ?? p.level}</Cell>
                  <Cell className="text-ink-muted">{p.exists ? 'Already there' : result.committed ? 'Created' : 'New'}</Cell>
                  <Cell>{p.new_arms.length ? p.new_arms.join(', ') : '—'}</Cell>
                </tr>
              ))}
            </Table>
          </div>
        )}
      </div>
    </Card>
  );
}
