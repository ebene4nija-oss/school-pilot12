'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, PageShell, Table } from '@/components/Page';

/**
 * Cache and housekeeping for the platform operator: the artisan clears that
 * used to need SSH after a deploy or a settings change, each one audited.
 */

type Status = {
  environment: string;
  debug: boolean;
  release: string;
  php: string;
  laravel: string;
  drivers: { cache: string; queue: string; session: string };
  cached: { config: boolean; routes: boolean; events: boolean; compiled_views: number };
  tasks: { task: string; label: string }[];
  recent: { task: string; by: string | null; at: string | null; ok: boolean }[];
};

// What each button costs, said before it is pressed.
const NOTES: Record<string, string> = {
  cache: 'Rate-limit counters and short-lived codes start again. Queued jobs are not touched.',
  views: 'Pages recompile on their next visit.',
  config: 'Settings are read from the files on each request until caches are rebuilt.',
  routes: 'Routes are read from the files on each request until caches are rebuilt.',
  events: 'Event listeners are discovered on each request until caches are rebuilt.',
  all: 'Clears all of the above. Follow with "Rebuild caches" on production.',
  rebuild: 'Caches config, routes, views and events again. Run after clearing on production.',
  queue: 'Workers finish their current job, then restart with the new code.',
};

export default function MaintenancePage() {
  const [status, setStatus] = useState<Status | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ text: string; output: string } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setStatus(await fetchApi<Status>('/platform/maintenance'));
    } catch (err) {
      setError(errorText(err, 'Could not load the server status.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const run = async (task: string, label: string) => {
    if (!window.confirm(`${label}?\n\n${NOTES[task] ?? ''}`)) return;
    setBusy(task);
    setError(null);
    try {
      const r = await fetchApi<{ message: string; output: string }>('/platform/maintenance', { method: 'POST', body: JSON.stringify({ task }) });
      setNotice({ text: r.message, output: r.output });
      await load();
    } catch (err) {
      setError(errorText(err, `${label} failed.`));
      setNotice(null);
    } finally {
      setBusy(null);
    }
  };

  return (
    <PageShell title="Cache & maintenance" subtitle="Clear caches and compiled views, rebuild them, and restart the queue workers — no SSH needed.">
      <ErrorNote message={error} />
      {notice && (
        <div role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          <p className="font-semibold">{notice.text}</p>
          {notice.output && <pre className="mt-1 whitespace-pre-wrap text-xs">{notice.output}</pre>}
        </div>
      )}

      {!status ? <Loading /> : (
        <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
          <Card title="Tasks">
            <ul className="divide-y divide-line-soft">
              {status.tasks.map((t) => (
                <li key={t.task} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <span>
                    <span className="block text-sm font-medium text-ink">{t.label}</span>
                    <span className="block text-xs text-ink-muted">{NOTES[t.task]}</span>
                  </span>
                  <Button variant={t.task === 'rebuild' ? 'primary' : 'secondary'} disabled={busy !== null} onClick={() => run(t.task, t.label)}>
                    {busy === t.task ? 'Running…' : 'Run'}
                  </Button>
                </li>
              ))}
            </ul>
          </Card>

          <div className="space-y-5">
            <Card title="Server">
              <dl className="grid grid-cols-2 gap-y-2 text-sm">
                <dt className="text-ink-muted">Environment</dt><dd>{status.environment}{status.debug && <Badge tone="red">debug on</Badge>}</dd>
                <dt className="text-ink-muted">Release</dt><dd className="truncate font-mono text-xs">{status.release}</dd>
                <dt className="text-ink-muted">PHP / Laravel</dt><dd>{status.php} / {status.laravel}</dd>
                <dt className="text-ink-muted">Cache</dt><dd>{status.drivers.cache}</dd>
                <dt className="text-ink-muted">Queue</dt><dd>{status.drivers.queue}</dd>
                <dt className="text-ink-muted">Sessions</dt><dd>{status.drivers.session}</dd>
              </dl>
            </Card>
            <Card title="Cached now">
              <ul className="space-y-1.5 text-sm">
                {([['Configuration', status.cached.config], ['Routes', status.cached.routes], ['Events', status.cached.events]] as [string, boolean][]).map(([label, on]) => (
                  <li key={label} className="flex justify-between">{label}<Badge tone={on ? 'green' : 'slate'}>{on ? 'cached' : 'not cached'}</Badge></li>
                ))}
                <li className="flex justify-between">Compiled views<span>{status.cached.compiled_views}</span></li>
              </ul>
            </Card>
          </div>

          <Card title="Recent runs" className="lg:col-span-2">
            {status.recent.length === 0 ? <Empty>Nothing run from here yet.</Empty> : (
              <Table columns={['Task', 'By', 'When', 'Result']}>
                {status.recent.map((r, i) => (
                  <tr key={i}>
                    <Cell>{status.tasks.find((t) => t.task === r.task)?.label ?? r.task}</Cell>
                    <Cell>{r.by ?? '—'}</Cell>
                    <Cell className="text-xs">{r.at ? new Date(r.at).toLocaleString('en-NG') : '—'}</Cell>
                    <Cell><Badge tone={r.ok ? 'green' : 'red'}>{r.ok ? 'done' : 'failed'}</Badge></Cell>
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
