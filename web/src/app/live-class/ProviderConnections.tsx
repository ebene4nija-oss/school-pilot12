'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Field, inputClass } from '@/components/Page';

/**
 * Connect the school's own Zoom or Google Workspace account so attendance is
 * read from the provider's participant report (TASK 14 §3.3). Credentials
 * are checked, then stored encrypted; they are never shown again.
 */

interface Connection { provider: 'zoom' | 'google_meet'; status: string; last_error: string | null; last_synced_at: string | null }

export default function ProviderConnections() {
  const [conns, setConns] = useState<Connection[]>([]);
  const [open, setOpen] = useState<'zoom' | 'google_meet' | null>(null);
  const [zoom, setZoom] = useState({ account_id: '', client_id: '', client_secret: '' });
  const [google, setGoogle] = useState({ service_account_json: '', impersonate: '' });
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = () => fetchApi<{ data: Connection[] }>('/live-classes/providers').then((r) => r.data);

  useEffect(() => {
    let cancelled = false;
    load().then((d) => { if (!cancelled) setConns(d); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  async function save(provider: 'zoom' | 'google_meet') {
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message: string }>(`/live-classes/providers/${provider}`, { method: 'PUT', body: JSON.stringify(provider === 'zoom' ? zoom : google) });
      setNotice(res.message);
      setOpen(null);
      setZoom({ account_id: '', client_id: '', client_secret: '' });
      setGoogle({ service_account_json: '', impersonate: '' });
      setConns(await load());
    } catch (err) {
      setError(errorText(err, 'Those details were not accepted.'));
    }
  }

  async function disconnect(provider: string) {
    if (!window.confirm('Disconnect? Attendance will be counted from the lesson page only.')) return;
    try {
      await fetchApi(`/live-classes/providers/${provider}`, { method: 'DELETE' });
      setConns(await load());
    } catch (err) {
      setError(errorText(err, 'Could not disconnect.'));
    }
  }

  const row = (provider: 'zoom' | 'google_meet', label: string) => {
    const c = conns.find((x) => x.provider === provider);
    return (
      <div className="flex flex-wrap items-center gap-3 border-b border-line-soft py-3 last:border-0">
        <span className="min-w-[8rem] font-medium">{label}</span>
        {c ? <Badge tone={c.status === 'connected' ? 'green' : 'red'}>{c.status}</Badge> : <Badge>not connected</Badge>}
        {c?.last_error && <span className="text-xs text-negative">{c.last_error}</span>}
        <Button variant="secondary" onClick={() => setOpen(open === provider ? null : provider)}>{c ? 'Replace details' : 'Connect'}</Button>
        {c && <Button variant="secondary" onClick={() => disconnect(provider)}>Disconnect</Button>}
      </div>
    );
  };

  return (
    <Card title="Read attendance from Zoom or Google Meet" description="Connect the school's own account and SchoolPilot reads who was in each class, and for how long, after it ends." className="mt-6">
      {error && <p role="alert" className="mb-3 text-sm text-negative">{error}</p>}
      {notice && <p role="status" className="mb-3 text-sm text-positive">{notice}</p>}
      {row('zoom', 'Zoom')}
      {open === 'zoom' && (
        <form className="grid gap-3 py-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); save('zoom'); }}>
          <p className="text-xs text-ink-muted sm:col-span-3">In the Zoom App Marketplace, create a Server-to-Server OAuth app with the report:read:admin scope, then copy its details here.</p>
          <Field label="Account ID"><input required value={zoom.account_id} onChange={(e) => setZoom({ ...zoom, account_id: e.target.value })} className={inputClass} /></Field>
          <Field label="Client ID"><input required value={zoom.client_id} onChange={(e) => setZoom({ ...zoom, client_id: e.target.value })} className={inputClass} /></Field>
          <Field label="Client secret"><input required type="password" autoComplete="off" value={zoom.client_secret} onChange={(e) => setZoom({ ...zoom, client_secret: e.target.value })} className={inputClass} /></Field>
          <div><Button type="submit">Check and save</Button></div>
        </form>
      )}
      {row('google_meet', 'Google Meet')}
      {open === 'google_meet' && (
        <form className="grid gap-3 py-3" onSubmit={(e) => { e.preventDefault(); save('google_meet'); }}>
          <p className="text-xs text-ink-muted">Your Google Workspace admin creates a service account with domain-wide delegation for the scope https://www.googleapis.com/auth/meetings.space.readonly, and gives you its JSON key.</p>
          <Field label="Service account JSON key"><textarea required rows={5} value={google.service_account_json} onChange={(e) => setGoogle({ ...google, service_account_json: e.target.value })} className={`${inputClass} font-mono text-xs`} /></Field>
          <Field label="Workspace user it acts as (e.g. the IT admin)"><input required type="email" value={google.impersonate} onChange={(e) => setGoogle({ ...google, impersonate: e.target.value })} className={`${inputClass} max-w-sm`} /></Field>
          <div><Button type="submit">Check and save</Button></div>
        </form>
      )}
    </Card>
  );
}
