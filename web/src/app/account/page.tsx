'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import LibraryBooks from '@/components/LibraryBooks';

/**
 * My account, for staff (bursar, nurse, teacher, admin): the contact number
 * the school reaches me on, my digital staff ID card where the school issues
 * them, and my library books. Name and role are the school office's to change.
 * Payslips are on the Payroll page.
 */

interface Me { name: string; email: string; role: string | null; user_profile: { phone: string | null } | null; school: { name: string } | null }
interface DigitalCard { serial: string; status: string; valid: boolean; holder_name: string; role: string; school: string; photo: string | null; expires_on: string | null; qr: string | null }

export default function AccountPage() {
  const [me, setMe] = useState<Me | null>(null);
  const [card, setCard] = useState<DigitalCard | null>(null);
  const [phone, setPhone] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<Me>('/user').then((u) => { setMe(u); setPhone(u.user_profile?.phone ?? ''); }).catch((err) => setError(errorText(err, 'Could not load your account.')));
    // 404 when the school does not issue digital cards: the section is left out.
    fetchApi<{ card: DigitalCard | null }>('/me/id-card').then((r) => setCard(r.card)).catch(() => undefined);
  }, []);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message: string; data: { phone: string | null } }>('/me/profile', { method: 'POST', body: JSON.stringify({ phone: phone.trim() || null }) });
      setPhone(res.data.phone ?? '');
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'Could not save your number.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell title="My account" subtitle={me ? [me.name, me.school?.name].filter(Boolean).join(' · ') : undefined}>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {!me ? (!error && <Loading />) : (
        <div className="grid gap-5 lg:grid-cols-2">
          <Card title="My details" description="Your name, role and sign-in email are kept by the school office.">
            <dl className="space-y-2 text-sm">
              <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Name</dt><dd className="font-medium">{me.name}</dd></div>
              <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Sign-in email</dt><dd>{me.email}</dd></div>
              <div><dt className="text-xs uppercase tracking-wide text-ink-faint">Role</dt><dd className="capitalize">{me.role?.replaceAll('_', ' ')}</dd></div>
            </dl>
            <form onSubmit={save} className="mt-4 space-y-2 border-t border-line-soft pt-4">
              <Field label="Phone number"><input className={inputClass} inputMode="tel" placeholder="080…" value={phone} onChange={(e) => setPhone(e.target.value)} /></Field>
              <Button type="submit" variant="secondary" disabled={busy}>Save number</Button>
            </form>
          </Card>
          {card && (
            <Card title="My staff ID card">
              <div className="flex gap-4">
                {card.photo && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={card.photo} alt="" className="h-24 w-20 rounded object-cover" />
                )}
                <div className="min-w-0 flex-1 text-sm">
                  <p className="text-xs text-ink-muted">{card.school}</p>
                  <p className="font-semibold">{card.holder_name}</p>
                  <p>{card.role}</p>
                  <p className="font-mono text-xs">{card.serial}</p>
                  {card.expires_on && <p className="text-xs text-ink-muted">Valid to {card.expires_on}</p>}
                  <Badge tone={card.valid ? 'green' : 'red'}>{card.status}</Badge>
                </div>
              </div>
              {card.qr && (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={card.qr} alt="Card verification code" className="mx-auto mt-4 h-32 w-32" />
              )}
            </Card>
          )}
          <div className="lg:col-span-2"><LibraryBooks /></div>
        </div>
      )}
    </PageShell>
  );
}
