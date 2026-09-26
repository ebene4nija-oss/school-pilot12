'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * Broadcasts, delivery history, and the school's SMS account (§7.13).
 *
 * "Notifications are not working" was several faults stacked, and this page had
 * three of them itself: it asked the admin to type recipient *user ids*, which
 * nobody knows; it offered Email and the API refused it, failing the whole
 * send; and it read the paginated history as if it were a list, so it crashed
 * as soon as a single message had been sent. It now addresses people the way a
 * school does — "JSS 2 parents" — and says, per channel, whether a message can
 * go out and if not, why.
 */

type Channel = 'push' | 'sms' | 'whatsapp' | 'email';
type AudienceType = 'parents' | 'students' | 'teachers' | 'staff';

interface HistoryRow {
  id: number;
  channel: string;
  status: string;
  category?: string;
  body?: string;
  recipient?: string;
  failure_reason?: string;
  created_at?: string;
  user?: { name?: string } | null;
}

interface TotalRow {
  channel: string;
  status: string;
  total: number;
}

interface ClassOption {
  id: number;
  name: string;
  arms?: { id: number; name: string }[];
}

interface Readiness {
  push: { ready: boolean; reason: string | null };
  sms: { ready: boolean; source: string; provider: string | null; reason: string | null };
  whatsapp: { ready: boolean; reason: string | null };
  email: {
    ready: boolean;
    daily_limit: number | null;
    sent_today: number;
    remaining_today: number | null;
    reason: string | null;
  };
}

interface Preview {
  recipients: number;
  with_phone: number;
  with_email: number;
  too_many: boolean;
  max_recipients: number;
}

interface Gateway {
  provider: 'phone_gateway' | 'termii';
  server_url: string | null;
  device: string | null;
  sender_id: string | null;
  is_active: boolean;
  key_hint: string;
  last_tested_at: string | null;
  last_test_status: 'passed' | 'failed' | null;
  last_test_message: string | null;
}

const CHANNELS: { id: Channel; label: string; hint: string }[] = [
  { id: 'sms', label: 'SMS', hint: 'Reaches every phone. Sent from your school’s own SMS account.' },
  { id: 'email', label: 'Email', hint: 'For anything longer than a text.' },
  { id: 'push', label: 'Push', hint: 'Free. Only reaches parents with the app installed.' },
  { id: 'whatsapp', label: 'WhatsApp', hint: 'Needs the parent on WhatsApp.' },
];

const AUDIENCES: { id: AudienceType; label: string; byClass: boolean }[] = [
  { id: 'parents', label: 'Parents', byClass: true },
  { id: 'students', label: 'Students', byClass: true },
  { id: 'teachers', label: 'Teachers', byClass: false },
  { id: 'staff', label: 'All staff', byClass: false },
];

export default function NotificationsPage() {
  const [history, setHistory] = useState<HistoryRow[]>([]);
  const [totals, setTotals] = useState<TotalRow[]>([]);
  const [readiness, setReadiness] = useState<Readiness | null>(null);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const [audienceType, setAudienceType] = useState<AudienceType>('parents');
  const [classId, setClassId] = useState('');
  const [armId, setArmId] = useState('');
  const [preview, setPreview] = useState<Preview | null>(null);

  const [body, setBody] = useState('');
  const [category, setCategory] = useState('general');
  const [selected, setSelected] = useState<Channel[]>(['sms']);

  const fetchHistory = useCallback(async () => {
    const res = await fetchApi<{ totals?: TotalRow[]; data?: { data?: HistoryRow[] } | HistoryRow[] }>(
      '/notifications/history',
    );

    // Paginated: the rows are one level down. Reading `data` as the list is
    // what crashed this page as soon as anything had been sent.
    const rows = Array.isArray(res.data) ? res.data : (res.data?.data ?? []);

    return { rows, totals: res.totals ?? [] };
  }, []);

  const reloadHistory = useCallback(async () => {
    const { rows, totals: t } = await fetchHistory();
    setHistory(rows);
    setTotals(t);
  }, [fetchHistory]);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const [h, r, c] = await Promise.all([
          fetchHistory(),
          fetchApi<Readiness>('/notifications/channels').catch(() => null),
          fetchApi<{ data?: ClassOption[] }>('/classes').catch(() => null),
        ]);

        if (cancelled) return;

        setHistory(h.rows);
        setTotals(h.totals);
        setReadiness(r);
        setClasses(c?.data ?? []);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Could not load notifications.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [fetchHistory]);

  const audience = useMemo(() => {
    const byClass = AUDIENCES.find((a) => a.id === audienceType)?.byClass;

    return {
      type: audienceType,
      ...(byClass && classId ? { class_id: Number(classId) } : {}),
      ...(byClass && armId ? { arm_id: Number(armId) } : {}),
    };
  }, [audienceType, classId, armId]);

  // Recount whenever the audience changes, so the admin sees who a message
  // will reach before sending it.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const p = await fetchApi<Preview>('/notifications/audience-preview', {
          method: 'POST',
          body: JSON.stringify({ audience }),
        });

        if (!cancelled) setPreview(p);
      } catch {
        if (!cancelled) setPreview(null);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [audience]);

  const toggle = (c: Channel) =>
    setSelected((prev) => (prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]));

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setError(null);
    setNotice(null);

    try {
      const result = await fetchApi<{ recipients: number }>('/notifications/broadcast', {
        method: 'POST',
        body: JSON.stringify({ audience, body, channels: selected, category }),
      });

      // Queued, not delivered — say so rather than implying it arrived.
      setNotice(
        `Queued for ${result.recipients} recipient${result.recipients === 1 ? '' : 's'}. The history below shows what was delivered and what failed.`,
      );
      setBody('');

      // Give the queue a moment, then refresh what actually went out.
      setTimeout(() => void reloadHistory().catch(() => undefined), 3000);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not queue that message.');
    } finally {
      setSending(false);
    }
  };

  const channelTotals = useMemo(() => {
    const map = new Map<string, { sent: number; failed: number }>();

    for (const row of totals) {
      const entry = map.get(row.channel) ?? { sent: 0, failed: 0 };
      if (row.status === 'sent') entry.sent += Number(row.total);
      else entry.failed += Number(row.total);
      map.set(row.channel, entry);
    }

    return [...map.entries()];
  }, [totals]);

  const arms = classes.find((c) => String(c.id) === classId)?.arms ?? [];
  const byClass = AUDIENCES.find((a) => a.id === audienceType)?.byClass;
  const blocked = selected.filter((c) => readiness && !readiness[c].ready);

  return (
    <PageShell title="Notifications" subtitle="Message parents, students and staff, and see what was actually delivered.">
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          {readiness && <ReadinessPanel readiness={readiness} />}

          <div className="grid gap-6 xl:grid-cols-3">
            <Card title="Send a message" className="h-fit xl:col-span-1">
              <form onSubmit={send} className="space-y-4">
                <Field label="Send to">
                  <select
                    value={audienceType}
                    onChange={(e) => setAudienceType(e.target.value as AudienceType)}
                    className={inputClass}
                  >
                    {AUDIENCES.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.label}
                      </option>
                    ))}
                  </select>
                </Field>

                {byClass && (
                  <div className="grid grid-cols-2 gap-3">
                    <Field label="Class">
                      <select
                        value={classId}
                        onChange={(e) => {
                          setClassId(e.target.value);
                          setArmId('');
                        }}
                        className={inputClass}
                      >
                        <option value="">Whole school</option>
                        {classes.map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.name}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Arm">
                      <select
                        value={armId}
                        onChange={(e) => setArmId(e.target.value)}
                        disabled={arms.length === 0}
                        className={inputClass}
                      >
                        <option value="">All arms</option>
                        {arms.map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.name}
                          </option>
                        ))}
                      </select>
                    </Field>
                  </div>
                )}

                {preview && (
                  <p
                    className={`rounded-lg px-3 py-2 text-xs ${preview.too_many ? 'bg-negative-soft text-negative' : 'bg-surface-sunken text-ink-muted'}`}
                  >
                    {preview.too_many
                      ? `${preview.recipients.toLocaleString('en-NG')} people — too many for one message. Choose a class (at most ${preview.max_recipients.toLocaleString('en-NG')}).`
                      : `Reaches ${preview.recipients.toLocaleString('en-NG')} ${preview.recipients === 1 ? 'person' : 'people'}: ${preview.with_phone.toLocaleString('en-NG')} with a phone number, ${preview.with_email.toLocaleString('en-NG')} with an email.`}
                  </p>
                )}

                <Field label="Message">
                  <textarea
                    required
                    rows={4}
                    maxLength={1000}
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    placeholder="Second term fees are due on Friday."
                    className={inputClass}
                  />
                  <span className="mt-1 block text-right text-xs text-ink-faint">
                    {body.length} characters
                    {body.length > 160 && ` · ${Math.ceil(body.length / 153)} SMS each`}
                  </span>
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
                  <legend className="mb-2 text-sm font-medium text-ink">Channels</legend>
                  <div className="space-y-2">
                    {CHANNELS.map((c) => {
                      const state = readiness?.[c.id];

                      return (
                        <label key={c.id} className="flex items-start gap-2.5 rounded-lg bg-surface-sunken px-3 py-2">
                          <input
                            type="checkbox"
                            checked={selected.includes(c.id)}
                            onChange={() => toggle(c.id)}
                            className="mt-1"
                          />
                          <span>
                            <span className="flex items-center gap-2 text-sm font-medium text-ink">
                              {c.label}
                              {state && !state.ready && <Badge tone="red">Not ready</Badge>}
                            </span>
                            <span className="block text-xs text-ink-muted">{c.hint}</span>
                          </span>
                        </label>
                      );
                    })}
                  </div>
                </fieldset>

                {blocked.length > 0 && (
                  <p className="text-xs text-caution">
                    {blocked.map((c) => CHANNELS.find((x) => x.id === c)?.label).join(', ')}{' '}
                    {blocked.length === 1 ? 'is' : 'are'} not ready — those messages will be recorded as failed.
                  </p>
                )}

                <Button
                  type="submit"
                  disabled={sending || selected.length === 0 || !preview || preview.recipients === 0 || preview.too_many}
                  className="w-full"
                >
                  {sending ? 'Queueing…' : 'Send'}
                </Button>
              </form>
            </Card>

            <div className="space-y-6 xl:col-span-2">
              <Card title="Delivery by channel">
                {channelTotals.length === 0 ? (
                  <Empty>Nothing sent yet.</Empty>
                ) : (
                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {channelTotals.map(([channel, t]) => (
                      <div key={channel} className="rounded-lg bg-surface-sunken p-3">
                        <p className="text-xs uppercase tracking-wide text-ink-muted">{channel}</p>
                        <p className="mt-1 text-2xl font-semibold text-brand">{t.sent.toLocaleString('en-NG')}</p>
                        <p className="text-xs text-ink-muted">delivered</p>
                        {t.failed > 0 && (
                          <p className="mt-1 text-xs font-medium text-negative">{t.failed.toLocaleString('en-NG')} failed</p>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </Card>

              <Card
                title="History"
                description="A failed row means the message did not go out — the reason is under it."
              >
                {history.length === 0 ? (
                  <Empty>No messages have been sent from this school yet.</Empty>
                ) : (
                  <Table columns={['When', 'Channel', 'To', 'Status', 'Message']}>
                    {history.map((row) => (
                      <tr key={row.id}>
                        <Cell className="whitespace-nowrap text-xs text-ink-muted">
                          {row.created_at
                            ? new Date(row.created_at).toLocaleString('en-NG', { dateStyle: 'short', timeStyle: 'short' })
                            : '—'}
                        </Cell>
                        <Cell className="capitalize">{row.channel}</Cell>
                        <Cell className="text-xs">
                          {row.user?.name && <span className="block text-ink">{row.user.name}</span>}
                          <span className="text-ink-muted">{row.recipient ?? '—'}</span>
                        </Cell>
                        <Cell>
                          {row.status === 'sent' ? <Badge tone="green">sent</Badge> : <Badge tone="red">{row.status}</Badge>}
                        </Cell>
                        <Cell>
                          <span className="line-clamp-2 text-ink">{row.body ?? '—'}</span>
                          {row.failure_reason && (
                            <span className="mt-0.5 block text-xs text-negative">{row.failure_reason}</span>
                          )}
                        </Cell>
                      </tr>
                    ))}
                  </Table>
                )}
              </Card>

              <SmsSettings onSaved={async () => setReadiness(await fetchApi<Readiness>('/notifications/channels'))} />
            </div>
          </div>
        </div>
      )}
    </PageShell>
  );
}

/** One line per channel: can it send right now, and if not, what to do. */
function ReadinessPanel({ readiness }: { readiness: Readiness }) {
  const rows: { label: string; ready: boolean; detail: string | null }[] = [
    {
      label: 'SMS',
      ready: readiness.sms.ready,
      detail: readiness.sms.ready
        ? readiness.sms.source === 'school'
          ? `Your own ${readiness.sms.provider === 'phone_gateway' ? 'phone gateway' : 'Termii account'}`
          : 'SchoolPilot’s shared account'
        : readiness.sms.reason,
    },
    {
      label: 'Email',
      ready: readiness.email.ready,
      detail: readiness.email.ready
        ? readiness.email.daily_limit === null
          ? `${readiness.email.sent_today.toLocaleString('en-NG')} sent today`
          : `${readiness.email.sent_today.toLocaleString('en-NG')} of ${readiness.email.daily_limit.toLocaleString('en-NG')} sent today`
        : readiness.email.reason,
    },
    { label: 'Push', ready: readiness.push.ready, detail: readiness.push.reason },
    { label: 'WhatsApp', ready: readiness.whatsapp.ready, detail: readiness.whatsapp.reason },
  ];

  return (
    <Card title="Can messages go out?">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {rows.map((row) => (
          <div
            key={row.label}
            className={`rounded-lg border-l-4 bg-surface-sunken p-3 ${row.ready ? 'border-l-positive' : 'border-l-negative'}`}
          >
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-ink">{row.label}</span>
              <Badge tone={row.ready ? 'green' : 'red'}>{row.ready ? 'Ready' : 'Not ready'}</Badge>
            </div>
            {row.detail && <p className="mt-1 text-xs text-ink-muted">{row.detail}</p>}
          </div>
        ))}
      </div>
    </Card>
  );
}

/**
 * The school's own SMS account.
 *
 * A phone gateway sends from the school's own phone and SIM, so parents see the
 * school's number and the school pays its own airtime.
 */
function SmsSettings({ onSaved }: { onSaved: () => Promise<void> }) {
  const [gateway, setGateway] = useState<Gateway | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const [provider, setProvider] = useState<Gateway['provider']>('phone_gateway');
  const [serverUrl, setServerUrl] = useState('');
  const [apiKey, setApiKey] = useState('');
  const [device, setDevice] = useState('');
  const [senderId, setSenderId] = useState('');
  const [testPhone, setTestPhone] = useState('');
  const [balance, setBalance] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const res = await fetchApi<{ data: Gateway | null }>('/school/sms-gateway');

        if (cancelled) return;

        setGateway(res.data);
        if (res.data) {
          setProvider(res.data.provider);
          setServerUrl(res.data.server_url ?? '');
          setDevice(res.data.device ?? '');
          setSenderId(res.data.sender_id ?? '');
        } else {
          setEditing(true);
        }
      } catch {
        // Teachers cannot see the account; the card simply does not render.
      } finally {
        if (!cancelled) setLoaded(true);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    setFieldErrors({});

    try {
      const res = await fetchApi<{ data: Gateway; message: string }>('/school/sms-gateway', {
        method: 'PUT',
        body: JSON.stringify({
          provider,
          server_url: provider === 'phone_gateway' ? serverUrl.trim() : null,
          api_key: apiKey.trim() || null,
          device: device.trim() || null,
          sender_id: provider === 'termii' ? senderId.trim() || null : null,
        }),
      });

      setGateway(res.data);
      setApiKey('');
      setEditing(false);
      setNotice(res.message);
      await onSaved();
    } catch (err) {
      const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;

      if (payload?.errors) {
        setFieldErrors(Object.fromEntries(Object.entries(payload.errors).map(([k, v]) => [k, v[0]])));
      } else {
        setError(err instanceof Error ? err.message : 'Could not save the SMS account.');
      }
    } finally {
      setBusy(false);
    }
  }

  async function sendTest() {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ message: string; data: Gateway }>('/school/sms-gateway/test', {
        method: 'POST',
        body: JSON.stringify({ phone: testPhone }),
      });

      setGateway(res.data);
      setNotice(res.message);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The test message failed.');
      // The failed attempt is recorded; show it.
      const res = await fetchApi<{ data: Gateway | null }>('/school/sms-gateway').catch(() => null);
      if (res?.data) setGateway(res.data);
    } finally {
      setBusy(false);
    }
  }

  async function checkBalance() {
    setBalance(null);
    setError(null);

    try {
      const res = await fetchApi<{ credits: string }>('/school/sms-gateway/balance');
      setBalance(res.credits);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not read the balance.');
    }
  }

  async function remove() {
    if (!confirm('Remove this SMS account? SMS will stop until another is added.')) return;

    setBusy(true);

    try {
      await fetchApi('/school/sms-gateway', { method: 'DELETE' });
      setGateway(null);
      setEditing(true);
      setServerUrl('');
      setDevice('');
      setSenderId('');
      await onSaved();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not remove the SMS account.');
    } finally {
      setBusy(false);
    }
  }

  if (!loaded) return null;

  const fe = (name: string) =>
    fieldErrors[name] ? <span className="mt-1 block text-xs text-negative">{fieldErrors[name]}</span> : null;

  return (
    <Card
      title="SMS settings"
      description="Your school's own SMS account. With a phone gateway, messages go out from your school's phone and SIM, so parents see your number."
    >
      <ErrorNote message={error} />
      {notice && <p className="mb-4 rounded-lg bg-positive-soft px-3 py-2 text-sm text-positive">{notice}</p>}

      {gateway && !editing && (
        <div className="space-y-4">
          <dl className="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-ink-faint">Account</dt>
              <dd className="font-medium text-ink">
                {gateway.provider === 'phone_gateway' ? 'Phone SMS gateway' : 'Termii'}
              </dd>
            </div>
            <div>
              <dt className="text-ink-faint">API key</dt>
              <dd className="font-mono">{gateway.key_hint}</dd>
            </div>
            {gateway.server_url && (
              <div>
                <dt className="text-ink-faint">Gateway address</dt>
                <dd className="break-all">{gateway.server_url}</dd>
              </div>
            )}
            <div>
              <dt className="text-ink-faint">{gateway.provider === 'phone_gateway' ? 'Sending phone' : 'Sender ID'}</dt>
              <dd>{gateway.provider === 'phone_gateway' ? gateway.device || 'Primary device' : gateway.sender_id || '—'}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-ink-faint">Last test</dt>
              <dd>
                {gateway.last_test_status ? (
                  <>
                    <Badge tone={gateway.last_test_status === 'passed' ? 'green' : 'red'}>{gateway.last_test_status}</Badge>{' '}
                    <span className="text-xs text-ink-muted">
                      {gateway.last_test_message}
                      {gateway.last_tested_at &&
                        ` · ${new Date(gateway.last_tested_at).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' })}`}
                    </span>
                  </>
                ) : (
                  <span className="text-ink-muted">Not tested yet — send a test below.</span>
                )}
              </dd>
            </div>
          </dl>

          <div className="flex flex-wrap items-end gap-2 border-t border-line-soft pt-4">
            <Field label="Send a test to">
              <input
                value={testPhone}
                onChange={(e) => setTestPhone(e.target.value)}
                placeholder="0803 123 4567"
                className={`${inputClass} w-48`}
              />
            </Field>
            <Button onClick={sendTest} disabled={busy || testPhone.trim().length < 7}>
              {busy ? 'Sending…' : 'Send test'}
            </Button>
            {gateway.provider === 'phone_gateway' && (
              <Button variant="secondary" onClick={checkBalance} disabled={busy}>
                Check balance
              </Button>
            )}
            <Button variant="secondary" onClick={() => setEditing(true)}>
              Change
            </Button>
            <Button variant="danger" onClick={remove} disabled={busy}>
              Remove
            </Button>
          </div>

          {balance !== null && <p className="text-sm text-ink">Credits left: <strong>{balance}</strong></p>}
        </div>
      )}

      {editing && (
        <form onSubmit={save} className="grid gap-4 sm:grid-cols-2">
          <Field label="Account type">
            <select
              value={provider}
              onChange={(e) => setProvider(e.target.value as Gateway['provider'])}
              className={inputClass}
            >
              <option value="phone_gateway">Phone SMS gateway (sends from your phone)</option>
              <option value="termii">Termii bulk SMS</option>
            </select>
          </Field>

          {provider === 'phone_gateway' ? (
            <Field label="Gateway address">
              <input
                required
                type="url"
                value={serverUrl}
                onChange={(e) => setServerUrl(e.target.value)}
                placeholder="https://your-gateway.com"
                className={inputClass}
              />
              {fe('server_url')}
            </Field>
          ) : (
            <Field label="Sender ID">
              <input
                maxLength={11}
                value={senderId}
                onChange={(e) => setSenderId(e.target.value)}
                placeholder="Registered with Termii"
                className={inputClass}
              />
              {fe('sender_id')}
            </Field>
          )}

          <Field label={gateway ? 'API key (leave empty to keep the current one)' : 'API key'}>
            <input
              required={!gateway}
              type="password"
              autoComplete="new-password"
              value={apiKey}
              onChange={(e) => setApiKey(e.target.value)}
              className={`${inputClass} font-mono`}
            />
            {fe('api_key')}
          </Field>

          {provider === 'phone_gateway' && (
            <Field label="Sending phone (optional)">
              <input
                value={device}
                onChange={(e) => setDevice(e.target.value)}
                placeholder="e.g. 1, or 1|0 for SIM slot 1"
                className={inputClass}
              />
              {fe('device')}
            </Field>
          )}

          <div className="flex gap-2 sm:col-span-2">
            <Button type="submit" disabled={busy}>
              {busy ? 'Saving…' : 'Save SMS account'}
            </Button>
            {gateway && (
              <Button type="button" variant="secondary" onClick={() => setEditing(false)}>
                Cancel
              </Button>
            )}
          </div>
        </form>
      )}
    </Card>
  );
}
