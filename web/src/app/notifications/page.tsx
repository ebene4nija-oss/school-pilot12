'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Field, Loading, PageShell, Table, inputClass } from '@/components/Page';

/**
 * Broadcasts and delivery history (§7.13).
 *
 * The history table matters more than it looks. SMS and WhatsApp used to
 * report a fake success whenever their API key was missing, so a school could
 * see a screen full of "sent" for messages no parent ever received. Now an
 * unconfigured or rejected channel records as failed — which is only useful if
 * somebody can see it, hence this page.
 */

type Channel = 'push' | 'sms' | 'whatsapp' | 'email';

type HistoryRow = {
  id: number;
  channel: string;
  status: string;
  category?: string;
  body?: string;
  recipient?: string;
  failure_reason?: string;
  created_at?: string;
};

type Totals = { channel: string; total?: number; sent?: number; failed?: number };

const CHANNELS: { id: Channel; label: string; hint: string }[] = [
  { id: 'push', label: 'Push', hint: 'Free. Only reaches parents with the app installed.' },
  { id: 'sms', label: 'SMS', hint: 'Billed per segment. Reaches every phone.' },
  { id: 'whatsapp', label: 'WhatsApp', hint: 'Cheaper than SMS; needs the parent on WhatsApp.' },
  { id: 'email', label: 'Email', hint: 'For anything too long for a text.' },
];

export default function NotificationsPage() {
  const [history, setHistory] = useState<HistoryRow[]>([]);
  const [totals, setTotals] = useState<Totals[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const [body, setBody] = useState('');
  const [category, setCategory] = useState('general');
  const [userIds, setUserIds] = useState('');
  const [selected, setSelected] = useState<Channel[]>(['push']);

  // No state is set before the first await — a synchronous setState in the
  // effect body below would trigger a cascading re-render.
  const load = useCallback(async () => {
    try {
      const data = await fetchApi<{ data?: HistoryRow[]; totals?: Totals[] }>('/notifications/history');
      setHistory(data.data ?? []);
      setTotals(data.totals ?? []);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load the notification history.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const toggle = (c: Channel) =>
    setSelected((prev) => (prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]));

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setError(null);
    setNotice(null);

    const ids = userIds
      .split(/[,\s]+/)
      .map((s) => Number(s.trim()))
      .filter((n) => Number.isInteger(n) && n > 0);

    try {
      const result = await fetchApi<{ recipients: number }>('/notifications/broadcast', {
        method: 'POST',
        body: JSON.stringify({ user_ids: ids, body, channels: selected, category }),
      });

      // Queued, not delivered — say so rather than implying it arrived.
      setNotice(
        `Queued for ${result.recipients} recipient${result.recipients === 1 ? '' : 's'}. Check the history below for per-channel delivery.`,
      );
      setBody('');
      setUserIds('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not queue that broadcast.');
    } finally {
      setSending(false);
    }
  };

  return (
    <PageShell title="Notifications" subtitle="Send a message and see what actually got delivered.">
      <ErrorNote message={error} />
      {notice && <p className="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

      <div className="grid gap-6 xl:grid-cols-3">
        <Card title="Send a broadcast" className="xl:col-span-1 h-fit">
          <form onSubmit={send} className="space-y-4">
            <Field label="Recipient user IDs">
              <input
                required
                value={userIds}
                onChange={(e) => setUserIds(e.target.value)}
                placeholder="e.g. 14, 22, 37"
                className={inputClass}
              />
            </Field>

            <Field label="Message">
              <textarea
                required
                rows={4}
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder="Second term fees are due on Friday."
                className={inputClass}
              />
            </Field>

            <Field label="Category">
              <select value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass}>
                <option value="general">General</option>
                <option value="fee_reminder">Fee reminder</option>
                <option value="result">Results</option>
                <option value="attendance">Attendance</option>
                <option value="emergency">Emergency</option>
              </select>
            </Field>

            <fieldset>
              <legend className="mb-2 text-sm font-medium text-slate-700">Channels</legend>
              <div className="space-y-2">
                {CHANNELS.map((c) => (
                  <label key={c.id} className="flex items-start gap-2.5 rounded-lg bg-slate-50 px-3 py-2">
                    <input
                      type="checkbox"
                      checked={selected.includes(c.id)}
                      onChange={() => toggle(c.id)}
                      className="mt-1"
                    />
                    <span>
                      <span className="block text-sm font-medium text-slate-800">{c.label}</span>
                      <span className="block text-xs text-slate-500">{c.hint}</span>
                    </span>
                  </label>
                ))}
              </div>
            </fieldset>

            <Button type="submit" disabled={sending || selected.length === 0} className="w-full">
              {sending ? 'Queueing…' : 'Send'}
            </Button>
          </form>
        </Card>

        <div className="space-y-6 xl:col-span-2">
          <Card title="Delivery by channel">
            {totals.length === 0 ? (
              <Empty>Nothing sent yet.</Empty>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {totals.map((t) => (
                  <div key={t.channel} className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs uppercase tracking-wide text-slate-500">{t.channel}</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{t.total ?? 0}</p>
                    {(t.failed ?? 0) > 0 && (
                      <p className="mt-1 text-xs font-medium text-red-600">{t.failed} failed</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card
            title="History"
            description="A failed row means the message did not go out — check the reason before resending."
          >
            {loading ? (
              <Loading />
            ) : history.length === 0 ? (
              <Empty>No messages have been sent from this school yet.</Empty>
            ) : (
              <Table columns={['When', 'Channel', 'To', 'Status', 'Message']}>
                {history.map((row) => (
                  <tr key={row.id}>
                    <Cell className="whitespace-nowrap text-xs text-slate-500">
                      {row.created_at?.slice(0, 16).replace('T', ' ') ?? '—'}
                    </Cell>
                    <Cell className="capitalize">{row.channel}</Cell>
                    <Cell className="text-xs">{row.recipient ?? '—'}</Cell>
                    <Cell>
                      {row.status === 'sent' ? (
                        <Badge tone="green">sent</Badge>
                      ) : (
                        <Badge tone="red">{row.status}</Badge>
                      )}
                    </Cell>
                    <Cell>
                      <span className="line-clamp-2 text-slate-700">{row.body ?? '—'}</span>
                      {row.failure_reason && (
                        <span className="mt-0.5 block text-xs text-red-600">{row.failure_reason}</span>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      </div>
    </PageShell>
  );
}
