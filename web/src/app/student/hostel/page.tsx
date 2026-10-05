'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, inputClass, nairaAmount } from '@/components/Page';
import { when } from '@/lib/student';

/**
 * Hostel (TASK 17, the boarder's own view): where I sleep, whether I am
 * signed in, my leave requests, hostel charges, and — where the school
 * allows it — asking for leave and reporting a repair. A leave request still
 * goes to a parent for consent and to the house staff for approval.
 */

interface Me {
  boarding: { status: string; category: string; expected_arrival: string | null } | null;
  hostel: string | null; room: string | null; bed: string | null; presence: string | null;
  leave: { id: number; leave_type: string; depart_at: string; return_at: string; status: string }[];
  charges: { title: string; amount: number; status: string }[];
  can_request_leave: boolean; can_report_maintenance: boolean;
}

const PRESENCE: Record<string, string> = { in_hostel: 'In the hostel', on_leave: 'On leave', checked_out: 'Checked out', not_arrived: 'Not yet arrived' };
const blankLeave = { leave_type: 'weekend', reason: '', depart_at: '', return_at: '', destination: '' };

export default function StudentHostel() {
  const [me, setMe] = useState<Me | null>(null);
  const [leave, setLeave] = useState<typeof blankLeave | null>(null);
  const [repair, setRepair] = useState<{ category: string; description: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => fetchApi<Me>('/me/hostel').then(setMe).catch((err) => setError(errorText(err, 'Could not load your hostel details.'))), []);
  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function send(path: string, body: unknown, done: string) {
    setBusy(true);
    setError(null);
    try {
      await fetchApi(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(done);
      setLeave(null);
      setRepair(null);
      await load();
    } catch (err) {
      setError(errorText(err, 'That did not go through.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell title="Hostel">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      {!me ? (!error && <Loading />) : !me.boarding ? <Empty>You are a day student. Nothing to show here.</Empty> : (
        <div className="grid gap-5 lg:grid-cols-2">
          <Card title="Where I stay">
            <div className="flex flex-wrap items-center gap-2">
              {me.presence ? <Badge tone={me.presence === 'in_hostel' ? 'green' : me.presence === 'on_leave' ? 'blue' : 'slate'}>{PRESENCE[me.presence] ?? me.presence}</Badge> : <Badge tone="amber">Boarding {me.boarding.status.replaceAll('_', ' ')}</Badge>}
              <span className="text-sm text-ink-muted">{me.boarding.category.replaceAll('_', ' ')}</span>
            </div>
            {me.hostel ? <p className="mt-3 text-sm">{me.hostel}{me.room ? ` · Room ${me.room}` : ''}{me.bed ? ` · Bed ${me.bed}` : ''}</p> : <p className="mt-3 text-sm text-ink-muted">No bed allocated yet{me.boarding.expected_arrival ? ` · expected ${me.boarding.expected_arrival}` : ''}.</p>}
            {me.charges.length > 0 && <p className="mt-3 text-sm text-ink-muted">Hostel charges: {me.charges.map((c) => `${c.title} ${nairaAmount(c.amount)} (${c.status})`).join(', ')}.</p>}
          </Card>

          <Card title="Leave">
            {me.leave.length === 0 ? <p className="text-sm text-ink-muted">No leave requested.</p> : (
              <ul className="space-y-2 text-sm">
                {me.leave.map((l) => (
                  <li key={l.id} className="flex flex-wrap items-center gap-2">
                    <span>{l.leave_type} · {when(l.depart_at)} → {when(l.return_at)}</span>
                    <Badge tone={l.status === 'approved' ? 'green' : l.status === 'rejected' ? 'slate' : 'amber'}>{l.status.replaceAll('_', ' ')}</Badge>
                  </li>
                ))}
              </ul>
            )}
            {me.can_request_leave && me.presence && !leave && <Button className="mt-3" variant="secondary" onClick={() => setLeave(blankLeave)}>Request leave</Button>}
            {leave && (
              <form className="mt-4 grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void send('/me/hostel/leave', leave, 'Leave requested. Your parent and the house staff will confirm it.'); }}>
                <Field label="Type"><select className={inputClass} value={leave.leave_type} onChange={(e) => setLeave({ ...leave, leave_type: e.target.value })}>{['weekend', 'medical', 'outing', 'family', 'other'].map((t) => <option key={t}>{t}</option>)}</select></Field>
                <Field label="Reason"><input className={inputClass} required maxLength={255} value={leave.reason} onChange={(e) => setLeave({ ...leave, reason: e.target.value })} /></Field>
                <Field label="Leaving"><input className={inputClass} type="datetime-local" required value={leave.depart_at} onChange={(e) => setLeave({ ...leave, depart_at: e.target.value })} /></Field>
                <Field label="Returning"><input className={inputClass} type="datetime-local" required value={leave.return_at} onChange={(e) => setLeave({ ...leave, return_at: e.target.value })} /></Field>
                <Field label="Going to (optional)"><input className={inputClass} maxLength={255} value={leave.destination} onChange={(e) => setLeave({ ...leave, destination: e.target.value })} /></Field>
                <div className="flex items-end gap-2"><Button disabled={busy}>Send request</Button><Button type="button" variant="secondary" onClick={() => setLeave(null)}>Cancel</Button></div>
              </form>
            )}
          </Card>

          {me.can_report_maintenance && me.hostel && (
            <Card title="Report a repair" description="Something broken in your room or hostel.">
              {!repair ? <Button variant="secondary" onClick={() => setRepair({ category: 'plumbing', description: '' })}>Report a problem</Button> : (
                <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void send('/me/hostel/maintenance', repair, 'Reported. The hostel staff will look at it.'); }}>
                  <Field label="What kind of problem"><select className={inputClass} value={repair.category} onChange={(e) => setRepair({ ...repair, category: e.target.value })}>{['plumbing', 'electrical', 'furniture', 'bedding', 'building', 'security', 'cleaning', 'other'].map((t) => <option key={t}>{t}</option>)}</select></Field>
                  <Field label="Describe it"><textarea className={`${inputClass} min-h-[6rem]`} required minLength={5} maxLength={2000} value={repair.description} onChange={(e) => setRepair({ ...repair, description: e.target.value })} /></Field>
                  <div className="flex gap-2"><Button disabled={busy}>Send</Button><Button type="button" variant="secondary" onClick={() => setRepair(null)}>Cancel</Button></div>
                </form>
              )}
            </Card>
          )}
        </div>
      )}
    </PageShell>
  );
}
