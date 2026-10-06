'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';
import LibraryBooks from '@/components/LibraryBooks';

/**
 * My account, for staff (bursar, nurse, teacher, admin): the contact number
 * the school reaches me on, my digital staff ID card where the school issues
 * them, my library books, and clocking in at the school gate with my phone's
 * location (teachers and admins; a phone, never a fingerprint reader — doc §3).
 * Name and role are the school office's to change. Payslips are on Payroll.
 */

interface Me { id: number; name: string; email: string; role: string | null; user_profile: { phone: string | null } | null; school: { name: string } | null }
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

  const [clock, setClock] = useState<{ busy: boolean; text: string | null; ok: boolean }>({ busy: false, text: null, ok: false });

  /** Clock in with this device's location; the server checks it is inside the school's radius. */
  function clockIn() {
    if (!navigator.geolocation) { setClock({ busy: false, ok: false, text: 'This browser cannot share your location.' }); return; }
    setClock({ busy: true, ok: false, text: 'Finding your location…' });
    navigator.geolocation.getCurrentPosition(async (pos) => {
      try {
        const terms = await fetchApi<{ current_term_id: number | null; data: { id: number }[] }>('/terms');
        const termId = terms.current_term_id ?? terms.data?.[0]?.id;
        const r = await fetchApi<{ message?: string }>('/attendance/staff-gps', { method: 'POST', body: JSON.stringify({ term_id: termId, latitude: pos.coords.latitude, longitude: pos.coords.longitude }) });
        setClock({ busy: false, ok: true, text: r.message ?? 'Clocked in.' });
      } catch (err) {
        setClock({ busy: false, ok: false, text: errorText(err, 'Could not clock you in.') });
      }
    }, (err) => setClock({ busy: false, ok: false, text: err.code === err.PERMISSION_DENIED ? 'Allow location access for this site, then try again.' : 'Your location could not be found. Step outside or turn on GPS and try again.' }), { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  }

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
          {(me.role === 'teacher' || me.role === 'school_admin') && (
            <Card title="Clock in at school" description="Uses your phone's location to confirm you are on the premises. Your position is checked once, not tracked.">
              <Button variant="accent" disabled={clock.busy} onClick={clockIn}>{clock.busy ? 'Checking…' : 'Clock in now'}</Button>
              {clock.text && <p className={`mt-2 text-sm ${clock.ok ? 'text-positive' : 'text-ink-muted'}`}>{clock.text}</p>}
            </Card>
          )}
          {me.role === 'parent' && <ParentProfile userId={me.id} />}
          {me.role !== 'parent' && <div className="lg:col-span-2"><LibraryBooks /></div>}
        </div>
      )}
    </PageShell>
  );
}

/** A parent's own contact details for the school. Custody is recorded by the office. */
function ParentProfile({ userId }: { userId: number }) {
  const [f, setF] = useState({ occupation: '', workplace: '', relationship_to_student: '', alternate_phone: '', alternate_email: '', preferred_contact_method: '' });
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    fetchApi<{ data: Record<string, string | null> }>(`/parents/${userId}/profile`)
      .then((r) => setF((prev) => Object.fromEntries(Object.keys(prev).map((k) => [k, r.data[k] ?? ''])) as typeof prev))
      .catch(() => undefined);
  }, [userId]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const body = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v.trim() || null]));
      const r = await fetchApi<{ message: string }>(`/parents/${userId}/profile`, { method: 'PUT', body: JSON.stringify(body) });
      setMsg(r.message);
    } catch (err) {
      setMsg(errorText(err, 'Could not save.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card title="How the school reaches you" description="Custody arrangements and emergency order are recorded by the school office.">
      <form className="grid gap-3 sm:grid-cols-2" onSubmit={save}>
        <Field label="Occupation"><input className={inputClass} value={f.occupation} onChange={(e) => setF({ ...f, occupation: e.target.value })} /></Field>
        <Field label="Workplace"><input className={inputClass} value={f.workplace} onChange={(e) => setF({ ...f, workplace: e.target.value })} /></Field>
        <Field label="Relationship to the child"><input className={inputClass} value={f.relationship_to_student} onChange={(e) => setF({ ...f, relationship_to_student: e.target.value })} placeholder="Mother, uncle…" /></Field>
        <Field label="Second phone"><input className={inputClass} inputMode="tel" value={f.alternate_phone} onChange={(e) => setF({ ...f, alternate_phone: e.target.value })} /></Field>
        <Field label="Second email"><input className={inputClass} type="email" value={f.alternate_email} onChange={(e) => setF({ ...f, alternate_email: e.target.value })} /></Field>
        <Field label="Best way to reach you">
          <select className={inputClass} value={f.preferred_contact_method} onChange={(e) => setF({ ...f, preferred_contact_method: e.target.value })}>
            <option value="">No preference</option><option value="phone">Phone call</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option>
          </select>
        </Field>
        <div className="flex items-center gap-3 sm:col-span-2"><Button type="submit" variant="secondary" disabled={busy}>Save</Button>{msg && <span className="text-sm text-ink-muted">{msg}</span>}</div>
      </form>
    </Card>
  );
}
