'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, StatTile, Table, inputClass } from '@/components/Page';

/**
 * Backups and data requests (doc §12).
 *
 * Backups are read from what the nightly script leaves on the server; this
 * screen cannot take, restore or decrypt one. Restore tests are recorded by
 * hand, because only a person knows they tried one.
 */

type Backups = {
  status: 'ok' | 'stale' | 'failing' | 'missing' | 'unknown';
  stale_after_hours: number;
  visible: boolean;
  last_success_at: string | null;
  last_file: string | null;
  last_size: string | null;
  offsite: boolean | null;
  last_error: string | null;
  files_kept: number;
  files: { name: string; size: number; modified_at: string }[];
  restore_tests: { tested_on: string; outcome: 'passed' | 'failed'; notes: string | null; recorded_by: string | null; recorded_at: string }[];
};

type DataRequests = {
  exports: {
    id: number;
    school: string | null;
    status: string;
    includes_medical: boolean;
    file_size: number | null;
    requested_at: string;
    completed_at: string | null;
    expires_at: string | null;
    requested_by_role: string | null;
  }[];
  export_totals: { total: number; with_medical: number; failed: number };
  consent_withdrawals: { school_id: number; school: string | null; withdrawals: number; ai_cross_border_withdrawn: number; latest_at: string }[];
  deletion_requests: null;
  deletion_requests_note: string;
};

const STATUS: Record<Backups['status'], { tone: 'green' | 'amber' | 'red' | 'slate'; label: string }> = {
  ok: { tone: 'green', label: 'Healthy' },
  stale: { tone: 'amber', label: 'Overdue' },
  failing: { tone: 'red', label: 'Last run failed' },
  missing: { tone: 'red', label: 'No backup found' },
  unknown: { tone: 'slate', label: 'Not visible' },
};

const when = (iso: string | null) =>
  iso ? new Date(iso.replace(' ', 'T')).toLocaleString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

const mb = (bytes: number | null) => (bytes === null ? '—' : `${(bytes / 1_048_576).toFixed(1)} MB`);

export default function CompliancePage() {
  const [backups, setBackups] = useState<Backups | null>(null);
  const [requests, setRequests] = useState<DataRequests | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [b, r] = await Promise.all([
        fetchApi<Backups>('/platform/backups'),
        fetchApi<DataRequests>('/platform/data-requests'),
      ]);
      setBackups(b);
      setRequests(r);
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not load compliance status.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  return (
    <PageShell title="Backups & data requests" subtitle="Whether the nightly backup ran, whether anyone has restored one, and what schools asked for.">
      <ErrorNote message={error} />

      {!backups || !requests ? (
        !error && <Loading />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile
              label="Backup"
              value={STATUS[backups.status].label}
              tone={STATUS[backups.status].tone}
              hint={backups.last_success_at ? `Last good: ${when(backups.last_success_at)}` : undefined}
            />
            <StatTile
              label="Off the server"
              value={backups.offsite === null ? '—' : backups.offsite ? 'Yes' : 'No'}
              tone={backups.offsite === false ? 'red' : backups.offsite ? 'green' : 'slate'}
              hint={backups.offsite === false ? 'Set BACKUP_S3_BUCKET' : undefined}
            />
            <StatTile
              label="Last restore test"
              value={backups.restore_tests[0]?.tested_on ?? 'Never'}
              tone={!backups.restore_tests[0] ? 'amber' : backups.restore_tests[0].outcome === 'passed' ? 'green' : 'red'}
              hint="Monthly, per LAUNCH.md §2"
            />
            <StatTile label="Data exports" value={requests.export_totals.total} hint={`${requests.export_totals.with_medical} with medical data`} />
          </div>

          {!backups.visible && (
            <p className="rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">
              This server cannot see the backup folder or log (BACKUP_DIR / BACKUP_LOG). On the production box they are
              /home/schoolpilotorg/backups and logs/backup.log.
            </p>
          )}
          {backups.last_error && (
            <p className="rounded-lg bg-negative-soft px-4 py-3 text-sm text-negative">Last error: {backups.last_error}</p>
          )}

          <div className="grid gap-6 xl:grid-cols-2">
            <Card title="Backup files on the server" description={`${backups.files_kept} kept (14-day retention). Encrypted; the key is not on the server.`}>
              {backups.files.length === 0 ? (
                <Empty>No backup files visible.</Empty>
              ) : (
                <Table columns={['File', 'Size', 'Written']}>
                  {backups.files.map((f) => (
                    <tr key={f.name}>
                      <Cell className="font-mono text-xs">{f.name}</Cell>
                      <Cell>{mb(f.size)}</Cell>
                      <Cell>{when(f.modified_at)}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <RestoreTests tests={backups.restore_tests} onRecorded={load} />
          </div>

          <Card title="Data exports requested by schools" description="A school's full export of its own records. Medical data is included only when asked for.">
            {requests.exports.length === 0 ? (
              <Empty>No school has requested an export.</Empty>
            ) : (
              <Table columns={['School', 'Requested', 'By', 'Status', 'Medical', 'Size', 'Link expires']}>
                {requests.exports.map((e) => (
                  <tr key={e.id}>
                    <Cell className="font-medium text-brand">{e.school ?? '—'}</Cell>
                    <Cell>{when(e.requested_at)}</Cell>
                    <Cell className="text-xs">{e.requested_by_role?.replace('_', ' ') ?? '—'}</Cell>
                    <Cell>
                      <Badge tone={e.status === 'complete' ? 'green' : e.status === 'failed' ? 'red' : 'amber'}>{e.status}</Badge>
                    </Cell>
                    <Cell>{e.includes_medical ? <Badge tone="amber">Included</Badge> : 'No'}</Cell>
                    <Cell>{mb(e.file_size)}</Cell>
                    <Cell>{when(e.expires_at)}</Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          <div className="grid gap-6 xl:grid-cols-2">
            <Card title="Parental consent withdrawn" description="Per school. Names stay with the school.">
              {requests.consent_withdrawals.length === 0 ? (
                <Empty>No consent has been withdrawn.</Empty>
              ) : (
                <Table columns={['School', 'Withdrawals', 'AI abroad refused', 'Latest']}>
                  {requests.consent_withdrawals.map((w) => (
                    <tr key={w.school_id}>
                      <Cell className="font-medium text-brand">{w.school ?? '—'}</Cell>
                      <Cell>{w.withdrawals}</Cell>
                      <Cell>{w.ai_cross_border_withdrawn}</Cell>
                      <Cell>{when(w.latest_at)}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <Card title="Deletion requests">
              <p className="text-sm text-ink-muted">{requests.deletion_requests_note}</p>
            </Card>
          </div>
        </div>
      )}
    </PageShell>
  );
}

function RestoreTests({ tests, onRecorded }: { tests: Backups['restore_tests']; onRecorded: () => void }) {
  const [form, setForm] = useState({ tested_on: new Date().toISOString().slice(0, 10), outcome: 'passed', notes: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await fetchApi('/platform/backups/restore-tests', {
        method: 'POST',
        body: JSON.stringify({ ...form, notes: form.notes.trim() || null }),
      });
      setForm({ ...form, notes: '' });
      onRecorded();
    } catch (err) {
      setError(errorText(err, 'Could not record that.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Restore tests" description="Restore a backup somewhere safe, check it, then record it here.">
      <form onSubmit={submit} className="mb-5 grid gap-3 sm:grid-cols-3">
        <Field label="Tested on">
          <input type="date" required value={form.tested_on} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setForm({ ...form, tested_on: e.target.value })} className={inputClass} />
        </Field>
        <Field label="Result">
          <select value={form.outcome} onChange={(e) => setForm({ ...form, outcome: e.target.value })} className={inputClass}>
            <option value="passed">Restored fine</option>
            <option value="failed">Did not restore</option>
          </select>
        </Field>
        <div className="flex items-end">
          <Button type="submit" disabled={busy} className="w-full">Record</Button>
        </div>
        <div className="sm:col-span-3">
          <Field label="Notes (optional)">
            <input value={form.notes} maxLength={500} onChange={(e) => setForm({ ...form, notes: e.target.value })} className={inputClass} placeholder="Which file, restored where, what you checked" />
          </Field>
        </div>
      </form>
      {error && <p className="mb-3 text-sm text-negative">{error}</p>}

      {tests.length === 0 ? (
        <Empty>No restore has ever been recorded.</Empty>
      ) : (
        <ul className="space-y-2">
          {tests.map((t, i) => (
            <li key={i} className="rounded-lg bg-surface-sunken px-3 py-2 text-sm">
              <div className="flex items-center justify-between">
                <span className="font-medium text-ink">{t.tested_on}</span>
                <Badge tone={t.outcome === 'passed' ? 'green' : 'red'}>{t.outcome}</Badge>
              </div>
              {t.notes && <p className="mt-1 text-xs text-ink-muted">{t.notes}</p>}
              <p className="mt-1 text-[11px] text-ink-faint">Recorded by {t.recorded_by ?? '—'}</p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
