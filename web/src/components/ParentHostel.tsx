'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Field, inputClass, nairaAmount } from '@/components/Page';

/**
 * A parent's view of their boarding children: where they sleep (if the
 * school shares it), whether they are in the hostel now, leave and charges.
 * Renders nothing for a family with no boarders or a school without the
 * hostel module.
 */

interface Leave { id: number; leave_type: string; depart_at: string; return_at: string; status: string; guardian_consent: string | null }
interface Child {
  student_id: number; name: string | null;
  boarding: { status: string; category: string; expected_arrival: string | null } | null;
  hostel: string | null; room: string | null; bed: string | null; presence: string | null;
  leave: Leave[]; charges: { title: string; amount: number; status: string }[];
}

const PRESENCE: Record<string, string> = { in_hostel: 'In the hostel', on_leave: 'On leave', checked_out: 'Checked out', not_arrived: 'Not yet arrived' };
const when = (iso: string) => new Date(iso).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export default function ParentHostel() {
  const [children, setChildren] = useState<Child[]>([]);
  const [canRequest, setCanRequest] = useState(false);
  const [form, setForm] = useState<{ student_id: number; leave_type: string; reason: string; depart_at: string; return_at: string } | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    const res = await fetchApi<{ can_request_leave: boolean; children: Child[] }>('/parent/hostel');
    setChildren(res.children.filter((c) => c.boarding));
    setCanRequest(res.can_request_leave);
  }

  useEffect(() => {
    (async () => {
      try {
        await load();
      } catch {
        // Module off or no access: no card, not a broken home page.
      }
    })();
  }, []);

  async function send(path: string, body: unknown, done: string) {
    setBusy(true);
    try {
      await fetchApi(path, { method: 'POST', body: JSON.stringify(body) });
      setMessage(done);
      setForm(null);
      await load();
    } catch (err) {
      setMessage(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  if (children.length === 0) return null;

  return (
    <Card title="Hostel" className="mt-6">
      {message && <p className="mb-3 text-sm text-ink-muted">{message}</p>}
      <div className="space-y-5">
        {children.map((c) => (
          <div key={c.student_id}>
            <div className="flex flex-wrap items-center gap-2">
              <p className="font-medium">{c.name}</p>
              {c.presence ? <Badge tone={c.presence === 'in_hostel' ? 'green' : c.presence === 'on_leave' ? 'blue' : 'slate'}>{PRESENCE[c.presence] ?? c.presence}</Badge> : c.boarding && <Badge tone="amber">Application {c.boarding.status.replaceAll('_', ' ')}</Badge>}
            </div>
            {c.hostel && <p className="mt-1 text-sm text-ink-muted">{c.hostel}{c.room ? ` · Room ${c.room}` : ''}{c.bed ? ` · Bed ${c.bed}` : ''}</p>}

            {c.leave.length > 0 && (
              <ul className="mt-2 space-y-1 text-sm">
                {c.leave.slice(0, 3).map((l) => (
                  <li key={l.id} className="flex flex-wrap items-center gap-2">
                    <span>{l.leave_type} leave, {when(l.depart_at)} → {when(l.return_at)}</span>
                    <Badge tone={l.status === 'approved' ? 'green' : l.status === 'rejected' ? 'slate' : 'amber'}>{l.status.replaceAll('_', ' ')}</Badge>
                    {l.status === 'awaiting_consent' && <>
                      <Button variant="secondary" disabled={busy} onClick={() => send(`/parent/hostel/leave/${l.id}/consent`, { given: true }, 'Consent given.')}>I consent</Button>
                      <Button variant="danger" disabled={busy} onClick={() => send(`/parent/hostel/leave/${l.id}/consent`, { given: false }, 'Consent refused.')}>Refuse</Button>
                    </>}
                  </li>
                ))}
              </ul>
            )}

            {c.charges.length > 0 && <p className="mt-2 text-sm text-ink-muted">Hostel charges: {c.charges.map((ch) => `${ch.title} ${nairaAmount(ch.amount)}`).join(', ')}. Pay through your school invoice.</p>}

            {canRequest && c.presence && form?.student_id !== c.student_id && (
              <Button className="mt-3" variant="secondary" onClick={() => setForm({ student_id: c.student_id, leave_type: 'weekend', reason: '', depart_at: '', return_at: '' })}>Request leave</Button>
            )}
            {form?.student_id === c.student_id && (
              <form className="mt-3 grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); send('/parent/hostel/leave', form, 'Leave requested. The hostel will confirm.'); }}>
                <Field label="Type"><select className={inputClass} value={form.leave_type} onChange={(e) => setForm({ ...form, leave_type: e.target.value })}>{['weekend', 'medical', 'outing', 'family', 'other'].map((t) => <option key={t}>{t}</option>)}</select></Field>
                <Field label="Reason"><input className={inputClass} required value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
                <Field label="Leaves"><input className={inputClass} type="datetime-local" required value={form.depart_at} onChange={(e) => setForm({ ...form, depart_at: e.target.value })} /></Field>
                <Field label="Returns"><input className={inputClass} type="datetime-local" required value={form.return_at} onChange={(e) => setForm({ ...form, return_at: e.target.value })} /></Field>
                <div className="flex gap-2"><Button disabled={busy}>Send request</Button><Button type="button" variant="secondary" onClick={() => setForm(null)}>Cancel</Button></div>
              </form>
            )}
          </div>
        ))}
      </div>
    </Card>
  );
}
