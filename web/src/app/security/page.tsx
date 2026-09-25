'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi } from '@/lib/api';

/**
 * Two-factor setup for administrator accounts.
 *
 * The backend has verified TOTP codes at login for a while, but nothing could
 * ever enrol an account — this screen is the missing half. LAUNCH.md §1 makes
 * mandatory TOTP for School Admin and Super Admin a pre-flight item, and it is
 * not meetable until every admin has been through this page.
 */

type Status = {
  enabled: boolean;
  confirmed: boolean;
  required: boolean;
  recovery_codes_remaining: number;
};

type SetupPayload = {
  secret: string;
  otpauth_uri: string;
  qr_code: string | null;
};

export default function SecurityPage() {
  const [status, setStatus] = useState<Status | null>(null);
  const [loading, setLoading] = useState(true);
  const [setup, setSetup] = useState<SetupPayload | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');

  // Awaits before its first setState: a synchronous setState in the effect
  // body below would trigger a cascading re-render.
  const loadStatus = useCallback(async () => {
    try {
      const next = await fetchApi<Status>('/auth/2fa');
      setStatus(next);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not read your security settings.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await loadStatus();
    })();
  }, [loadStatus]);

  const begin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setBusy(true);

    try {
      setSetup(
        await fetchApi<SetupPayload>('/auth/2fa/setup', {
          method: 'POST',
          body: JSON.stringify({ password }),
        }),
      );
      setPassword('');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not start setup.');
    } finally {
      setBusy(false);
    }
  };

  const confirm = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setBusy(true);

    try {
      const result = await fetchApi<{ recovery_codes: string[] }>('/auth/2fa/confirm', {
        method: 'POST',
        body: JSON.stringify({ code }),
      });

      setRecoveryCodes(result.recovery_codes);
      setSetup(null);
      setCode('');
      await loadStatus();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That code was not accepted.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-screen bg-surface-sunken">
      <Sidebar />

      <main className="flex-1 p-8">
        <header className="mb-6">
          <h1 className="text-2xl font-bold text-brand">Security</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Two-factor authentication for your administrator account.
          </p>
        </header>

        {error && (
          <p role="alert" className="mb-6 max-w-2xl rounded-lg bg-negative-soft px-4 py-3 text-sm text-negative">
            {error}
          </p>
        )}

        {loading ? (
          <p className="text-sm text-ink-muted">Loading…</p>
        ) : (
          <div className="max-w-2xl space-y-6">
            <StatusCard status={status} />

            {/* One-time reveal. Shown once, never retrievable. */}
            {recoveryCodes && <RecoveryCodes codes={recoveryCodes} onDismiss={() => setRecoveryCodes(null)} />}

            {!status?.confirmed && !setup && (
              <Card title="Turn on two-factor authentication">
                <p className="text-sm text-ink-muted">
                  You will need an authenticator app — Google Authenticator, Authy, or the
                  one built into your password manager.
                </p>

                <form onSubmit={begin} className="mt-4 space-y-3">
                  <label className="block text-sm font-medium text-ink" htmlFor="password">
                    Confirm your password
                  </label>
                  <input
                    id="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="w-full rounded-lg border border-line px-3 py-2 text-sm outline-none focus:border-brand focus:ring-1 focus:ring-brand/20"
                  />
                  <button
                    type="submit"
                    disabled={busy}
                    className="rounded-lg bg-brand px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-soft disabled:opacity-60"
                  >
                    {busy ? 'Working…' : 'Begin setup'}
                  </button>
                </form>
              </Card>
            )}

            {setup && (
              <Card title="Scan this with your authenticator">
                {setup.qr_code && (
                  // eslint-disable-next-line @next/next/no-img-element -- a data: URI, nothing for the image optimiser to do
                  <img
                    src={setup.qr_code}
                    alt="Two-factor setup QR code"
                    className="h-48 w-48 rounded-lg border border-line-soft bg-white p-2"
                  />
                )}

                <p className="mt-4 text-sm text-ink-muted">
                  Setting this up on the phone you are reading this on? Type the key in
                  by hand instead:
                </p>
                <code className="mt-2 block break-all rounded-lg bg-surface-sunken px-3 py-2 font-mono text-sm text-ink">
                  {setup.secret}
                </code>

                <form onSubmit={confirm} className="mt-5 space-y-3">
                  <label className="block text-sm font-medium text-ink" htmlFor="code">
                    Enter the 6-digit code it shows
                  </label>
                  <input
                    id="code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    required
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    className="w-40 rounded-lg border border-line px-3 py-2 font-mono text-lg tracking-widest outline-none focus:border-brand focus:ring-1 focus:ring-brand/20"
                  />
                  <button
                    type="submit"
                    disabled={busy}
                    className="block rounded-lg bg-brand px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-soft disabled:opacity-60"
                  >
                    {busy ? 'Checking…' : 'Confirm and turn on'}
                  </button>
                </form>
              </Card>
            )}
          </div>
        )}
      </main>
    </div>
  );
}

function StatusCard({ status }: { status: Status | null }) {
  const on = status?.confirmed;

  return (
    <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-line-soft">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium text-brand">Two-factor authentication</p>
          <p className="mt-0.5 text-sm text-ink-muted">
            {on
              ? `On — ${status?.recovery_codes_remaining ?? 0} recovery codes left`
              : 'Off — your password is the only thing protecting this school’s records'}
          </p>
        </div>
        <span
          className={`rounded-full px-3 py-1 text-xs font-semibold ${
            on ? 'bg-positive-soft text-positive' : 'bg-caution-soft text-caution'
          }`}
        >
          {on ? 'Active' : 'Not set up'}
        </span>
      </div>

      {status?.required && !on && (
        <p className="mt-3 rounded-lg bg-caution-soft px-3 py-2 text-sm text-caution">
          Your school requires two-factor authentication. Until you finish setting it up,
          the rest of the admin portal stays locked.
        </p>
      )}
    </div>
  );
}

function RecoveryCodes({ codes, onDismiss }: { codes: string[]; onDismiss: () => void }) {
  return (
    <div className="rounded-xl bg-white p-5 shadow-sm ring-2 ring-accent/40">
      <h2 className="text-sm font-semibold text-brand">Save your recovery codes</h2>
      <p className="mt-1 text-sm text-ink-muted">
        Each code works once, and gets you in if you lose your phone. This is the only
        time they will be shown — print them, or write them somewhere safe.
      </p>

      <ul className="mt-4 grid grid-cols-2 gap-2 font-mono text-sm text-ink">
        {codes.map((c) => (
          <li key={c} className="rounded-lg bg-surface-sunken px-3 py-2 text-center">
            {c}
          </li>
        ))}
      </ul>

      <button
        onClick={onDismiss}
        className="mt-4 rounded-lg border border-line px-4 py-2 text-sm font-medium text-ink hover:bg-surface-sunken"
      >
        I have saved them
      </button>
    </div>
  );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-line-soft">
      <h2 className="text-sm font-semibold text-brand">{title}</h2>
      <div className="mt-3">{children}</div>
    </section>
  );
}
