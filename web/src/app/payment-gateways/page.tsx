'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Online payments into the school's own account (school admin only).
 *
 * The school pastes the keys from its own Paystack or Flutterwave dashboard,
 * and parents' online fee payments then settle into the school's account
 * instead of SchoolPilot's. Keys are write-only: this page sends them and is
 * only ever told the last four characters back. Every change is audited.
 */

type GatewayKey = 'paystack' | 'flutterwave';
interface Gateway {
  gateway: GatewayKey;
  connected: boolean;
  is_active: boolean;
  usable: boolean;
  mode: 'test' | 'live' | null;
  secret_key_hint: string | null;
  has_public_key: boolean;
  webhook_secret_required: boolean;
  has_webhook_secret: boolean;
  updated_at: string | null;
  webhook_url: string;
}

const INFO: Record<GatewayKey, { name: string; where: string; secret: string; public: string }> = {
  paystack: {
    name: 'Paystack',
    where: 'Paystack dashboard → Settings → API Keys & Webhooks',
    secret: 'sk_live_…',
    public: 'pk_live_…',
  },
  flutterwave: {
    name: 'Flutterwave',
    where: 'Flutterwave dashboard → Settings → API keys, and Settings → Webhooks for the secret hash',
    secret: 'FLWSECK-…',
    public: 'FLWPUBK-…',
  },
};

export default function PaymentGatewaysPage() {
  const [rows, setRows] = useState<Gateway[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setRows((await fetchApi<{ data: Gateway[] }>('/finance/gateways')).data);
    } catch (err) {
      setError(errorText(err, 'Could not load payment settings.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  return (
    <PageShell
      title="Online payments"
      subtitle="Connect your school's own Paystack or Flutterwave account, so parents' online fee payments go straight to the school."
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {!rows ? (
        !error && <Loading />
      ) : (
        <div className="grid gap-6 xl:grid-cols-2">
          {rows.map((g) => (
            <GatewayCard
              key={`${g.gateway}-${g.updated_at ?? 'new'}`}
              gateway={g}
              onDone={async (m) => {
                setNotice(m);
                setError(null);
                await load();
              }}
              onError={(m) => {
                setError(m);
                setNotice(null);
              }}
            />
          ))}
        </div>
      )}
      <p className="mt-6 max-w-3xl text-xs text-ink-muted">
        Keys are encrypted before they are stored and are never shown again, only their last four characters. Use test keys
        (sk_test_… or FLWSECK_TEST-…) first and make one small payment before switching to live keys. Cash and bank transfers are
        recorded by the bursar and don&apos;t use these keys.
      </p>
    </PageShell>
  );
}

function GatewayCard({ gateway: g, onDone, onError }: { gateway: Gateway; onDone: (m: string) => Promise<void>; onError: (m: string) => void }) {
  const info = INFO[g.gateway];
  const [editing, setEditing] = useState(!g.connected);
  const [form, setForm] = useState({ secret_key: '', public_key: '', webhook_secret: '' });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);

  async function save() {
    setBusy(true);
    setFieldErrors({});
    try {
      const res = await fetchApi<{ message: string }>(`/finance/gateways/${g.gateway}`, {
        method: 'PUT',
        body: JSON.stringify({
          secret_key: form.secret_key.trim(),
          public_key: form.public_key.trim() || null,
          webhook_secret: form.webhook_secret.trim() || null,
          is_active: true,
        }),
      });
      setForm({ secret_key: '', public_key: '', webhook_secret: '' });
      await onDone(res.message);
    } catch (err) {
      const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
      if (payload?.errors) {
        setFieldErrors(Object.fromEntries(Object.entries(payload.errors).map(([k, v]) => [k, v[0]])));
      } else {
        onError(errorText(err, `Could not connect ${info.name}.`));
      }
    } finally {
      setBusy(false);
    }
  }

  async function disconnect() {
    if (!window.confirm(`Disconnect ${info.name}? Parents will not be able to pay online through it until it is connected again.`)) return;
    setBusy(true);
    try {
      const res = await fetchApi<{ message?: string }>(`/finance/gateways/${g.gateway}`, { method: 'DELETE' });
      await onDone(res.message ?? `${info.name} disconnected.`);
    } catch (err) {
      onError(errorText(err, `Could not disconnect ${info.name}.`));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card title={info.name} description={`Find your keys in the ${info.where}.`}>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        {g.connected ? (
          <>
            <Badge tone={g.usable ? 'green' : 'amber'}>{g.usable ? 'Connected' : 'Connected, not usable yet'}</Badge>
            {g.mode && <Badge tone={g.mode === 'live' ? 'blue' : 'amber'}>{g.mode === 'live' ? 'Live keys' : 'Test keys: no real money'}</Badge>}
            {g.secret_key_hint && <span className="text-xs text-ink-muted">Secret key {g.secret_key_hint}</span>}
          </>
        ) : (
          <Badge tone="slate">Not connected</Badge>
        )}
      </div>

      {g.connected && g.webhook_secret_required && !g.has_webhook_secret && (
        <p className="mb-4 rounded-lg bg-caution-soft px-3 py-2 text-sm text-caution">
          Add the secret hash from your Flutterwave dashboard, or payments cannot be confirmed automatically.
        </p>
      )}

      <div className="mb-4">
        <p className="text-sm font-semibold text-ink">Webhook URL</p>
        <p className="mb-1 text-xs text-ink-muted">Paste this into your {info.name} dashboard so payments confirm on their own.</p>
        <div className="flex gap-2">
          <input className={`${inputClass} font-mono text-xs`} readOnly value={g.webhook_url} onFocus={(e) => e.target.select()} />
          <Button
            variant="secondary"
            onClick={() => {
              void navigator.clipboard.writeText(g.webhook_url).then(() => {
                setCopied(true);
                setTimeout(() => setCopied(false), 2000);
              });
            }}
          >
            {copied ? 'Copied' : 'Copy'}
          </Button>
        </div>
      </div>

      {editing ? (
        <form
          className="space-y-3 border-t border-line-soft pt-4"
          autoComplete="off"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <Field label="Secret key">
            <input className={`${inputClass} font-mono`} type="password" autoComplete="new-password" required placeholder={info.secret} value={form.secret_key} onChange={(e) => setForm({ ...form, secret_key: e.target.value })} />
            {fieldErrors.secret_key && <span className="mt-1 block text-xs text-negative">{fieldErrors.secret_key}</span>}
          </Field>
          <Field label="Public key (optional)">
            <input className={`${inputClass} font-mono`} autoComplete="off" placeholder={info.public} value={form.public_key} onChange={(e) => setForm({ ...form, public_key: e.target.value })} />
            {fieldErrors.public_key && <span className="mt-1 block text-xs text-negative">{fieldErrors.public_key}</span>}
          </Field>
          {g.webhook_secret_required && (
            <Field label="Webhook secret hash">
              <input className={`${inputClass} font-mono`} type="password" autoComplete="new-password" required value={form.webhook_secret} onChange={(e) => setForm({ ...form, webhook_secret: e.target.value })} />
              {fieldErrors.webhook_secret && <span className="mt-1 block text-xs text-negative">{fieldErrors.webhook_secret}</span>}
            </Field>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={busy}>{busy ? 'Saving…' : g.connected ? 'Replace keys' : `Connect ${info.name}`}</Button>
            {g.connected && <Button type="button" variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>}
          </div>
        </form>
      ) : (
        <div className="flex gap-2 border-t border-line-soft pt-4">
          <Button variant="secondary" onClick={() => setEditing(true)}>Replace keys</Button>
          <Button variant="danger" disabled={busy} onClick={() => void disconnect()}>Disconnect</Button>
        </div>
      )}
    </Card>
  );
}
