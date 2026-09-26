'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Loading,
  PageShell,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * How much each school sends, and the operator's limits on email.
 *
 * Every school's email leaves through one sending domain, so one runaway
 * broadcast can get it blocklisted for all of them. The platform sets each
 * school's email on/off and daily limit here; a school cannot set its own.
 * SMS is not capped here: each school sends from its own phone or account and
 * pays for its own messages.
 */

interface SchoolMessaging {
  id: number;
  name: string;
  subdomain: string;
  email_enabled: boolean;
  email_daily_limit: number | null;
  effective_email_limit: number | null;
  sent_today: { email: number; sms: number; push: number; whatsapp: number };
  failed_today: number;
  sms_account: { provider: string; is_active: boolean; last_test_status: string | null } | null;
}

export default function PlatformMessagingPage() {
  const [rows, setRows] = useState<SchoolMessaging[]>([]);
  const [defaultLimit, setDefaultLimit] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [limitDrafts, setLimitDrafts] = useState<Record<number, string>>({});

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const res = await fetchApi<{ default_email_limit: number | null; data: SchoolMessaging[] }>('/platform/messaging');

        if (!cancelled) {
          setRows(res.data);
          setDefaultLimit(res.default_email_limit);
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Could not load messaging.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  async function update(row: SchoolMessaging, change: { email_enabled?: boolean; email_daily_limit?: number | null }) {
    setBusyId(row.id);
    setError(null);

    try {
      const res = await fetchApi<{ school: Pick<SchoolMessaging, 'id' | 'email_enabled' | 'email_daily_limit' | 'effective_email_limit'> }>(
        `/platform/schools/${row.id}/messaging`,
        { method: 'POST', body: JSON.stringify(change) },
      );

      setRows((prev) => prev.map((r) => (r.id === row.id ? { ...r, ...res.school } : r)));
      setLimitDrafts((d) => {
        const next = { ...d };
        delete next[row.id];
        return next;
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusyId(null);
    }
  }

  const totals = rows.reduce(
    (acc, r) => ({
      email: acc.email + r.sent_today.email,
      sms: acc.sms + r.sent_today.sms,
      failed: acc.failed + r.failed_today,
    }),
    { email: 0, sms: 0, failed: 0 },
  );

  return (
    <PageShell
      title="Messaging"
      subtitle="What every school sent today, and how much email each is allowed to send."
      actions={
        <Link href="/super-admin/settings">
          <Button variant="secondary">Email server &amp; default limit</Button>
        </Link>
      }
    >
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <p className="text-sm text-ink-muted">
            Today across all schools: <strong className="text-ink">{totals.email.toLocaleString('en-NG')}</strong> emails,{' '}
            <strong className="text-ink">{totals.sms.toLocaleString('en-NG')}</strong> SMS
            {totals.failed > 0 && (
              <>
                , <strong className="text-negative">{totals.failed.toLocaleString('en-NG')}</strong> failed
              </>
            )}
            . Default email limit:{' '}
            <strong className="text-ink">{defaultLimit === null ? 'none' : `${defaultLimit.toLocaleString('en-NG')} per school per day`}</strong>.
          </p>

          <Card
            title="Schools"
            description="Password-reset and account-setup emails always go out, even when a school's email is off or over its limit."
          >
            {rows.length === 0 ? (
              <Empty>No schools yet.</Empty>
            ) : (
              <Table columns={['School', 'Email today', 'Email limit', 'Email', 'SMS today', 'SMS account', 'Failed today']}>
                {rows.map((row) => {
                  const nearLimit =
                    row.effective_email_limit !== null && row.sent_today.email >= row.effective_email_limit * 0.8;

                  return (
                    <tr key={row.id}>
                      <Cell className="font-medium text-brand">
                        {row.name}
                        <span className="block font-mono text-xs text-ink-faint">{row.subdomain}</span>
                      </Cell>
                      <Cell className={nearLimit ? 'font-semibold text-caution' : ''}>
                        {row.sent_today.email.toLocaleString('en-NG')}
                        {row.effective_email_limit !== null && (
                          <span className="text-ink-faint"> / {row.effective_email_limit.toLocaleString('en-NG')}</span>
                        )}
                      </Cell>
                      <Cell>
                        <div className="flex items-center gap-2">
                          <input
                            type="number"
                            min={0}
                            value={limitDrafts[row.id] ?? (row.email_daily_limit === null ? '' : String(row.email_daily_limit))}
                            onChange={(e) => setLimitDrafts((d) => ({ ...d, [row.id]: e.target.value }))}
                            placeholder={defaultLimit === null ? 'No limit' : `Default (${defaultLimit})`}
                            className={`${inputClass} w-32`}
                            aria-label={`Daily email limit for ${row.name}`}
                          />
                          {row.id in limitDrafts && (
                            <Button
                              variant="secondary"
                              disabled={busyId === row.id}
                              onClick={() =>
                                update(row, {
                                  email_daily_limit: limitDrafts[row.id].trim() === '' ? null : Number(limitDrafts[row.id]),
                                })
                              }
                            >
                              Save
                            </Button>
                          )}
                        </div>
                      </Cell>
                      <Cell>
                        <button
                          onClick={() => update(row, { email_enabled: !row.email_enabled })}
                          disabled={busyId === row.id}
                          className="disabled:opacity-60"
                          aria-label={`${row.email_enabled ? 'Switch email off' : 'Switch email on'} for ${row.name}`}
                        >
                          <Badge tone={row.email_enabled ? 'green' : 'red'}>{row.email_enabled ? 'On' : 'Off'}</Badge>
                        </button>
                      </Cell>
                      <Cell>{row.sent_today.sms.toLocaleString('en-NG')}</Cell>
                      <Cell className="text-xs">
                        {row.sms_account ? (
                          <>
                            {row.sms_account.provider === 'phone_gateway' ? 'Phone gateway' : 'Termii'}
                            {row.sms_account.last_test_status && (
                              <span className="ml-1">
                                <Badge tone={row.sms_account.last_test_status === 'passed' ? 'green' : 'red'}>
                                  {row.sms_account.last_test_status}
                                </Badge>
                              </span>
                            )}
                          </>
                        ) : (
                          <span className="text-ink-faint">Not set up</span>
                        )}
                      </Cell>
                      <Cell className={row.failed_today > 0 ? 'font-semibold text-negative' : 'text-ink-faint'}>
                        {row.failed_today.toLocaleString('en-NG')}
                      </Cell>
                    </tr>
                  );
                })}
              </Table>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
