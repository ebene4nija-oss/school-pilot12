'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Button, Card, Cell, Empty, ErrorNote, Loading, PageShell, Table } from '@/components/Page';

/**
 * Staff leave and employment records (§7.11).
 *
 * The sidebar has linked to /staff since the portal was built; the route did
 * not exist. StaffHrController has had the endpoints all along.
 */

type LeaveRequest = {
  id: number;
  staff_name?: string;
  leave_type: string;
  start_date: string;
  end_date: string;
  days?: number;
  status: 'pending' | 'approved' | 'declined' | 'cancelled' | string;
  reason?: string;
};

type CalendarEntry = { staff_name?: string; leave_type?: string; end_date?: string };

const STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'slate'> = {
  approved: 'green',
  pending: 'amber',
  declined: 'red',
  cancelled: 'slate',
};

export default function StaffPage() {
  const [requests, setRequests] = useState<LeaveRequest[]>([]);
  const [offToday, setOffToday] = useState<CalendarEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  // Nothing here sets state before the first await: the effect below calls
  // this directly, and a synchronous setState in an effect body triggers a
  // cascading re-render (react-hooks/set-state-in-effect).
  const load = useCallback(async () => {
    try {
      const [leave, calendar] = await Promise.all([
        fetchApi<{ data?: LeaveRequest[] } | LeaveRequest[]>('/hr/leave'),
        fetchApi<{ data?: CalendarEntry[] } | CalendarEntry[]>('/hr/leave-calendar'),
      ]);

      setRequests(Array.isArray(leave) ? leave : (leave.data ?? []));
      setOffToday(Array.isArray(calendar) ? calendar : (calendar.data ?? []));
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not load staff records.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  const decide = async (id: number, decision: 'approved' | 'declined') => {
    setBusyId(id);
    setError(null);

    try {
      await fetchApi(`/hr/leave/${id}/decision`, {
        method: 'POST',
        body: JSON.stringify({ status: decision }),
      });
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not record that decision.');
    } finally {
      setBusyId(null);
    }
  };

  const pending = requests.filter((r) => r.status === 'pending');
  const decided = requests.filter((r) => r.status !== 'pending');

  return (
    <PageShell title="Staff" subtitle="Leave requests and who is off today.">
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : (
        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <Card title="Awaiting your decision" description="A request stays here until it is approved or declined.">
              {pending.length === 0 ? (
                <Empty>Nothing waiting. Every request has been dealt with.</Empty>
              ) : (
                <Table columns={['Staff', 'Type', 'Dates', 'Days', '']}>
                  {pending.map((r) => (
                    <tr key={r.id}>
                      <Cell className="font-medium text-slate-900">{r.staff_name ?? `Staff #${r.id}`}</Cell>
                      <Cell className="capitalize">{r.leave_type}</Cell>
                      <Cell>
                        {r.start_date} → {r.end_date}
                      </Cell>
                      <Cell>{r.days ?? '—'}</Cell>
                      <Cell>
                        <div className="flex gap-2">
                          <Button disabled={busyId === r.id} onClick={() => decide(r.id, 'approved')}>
                            Approve
                          </Button>
                          <Button
                            variant="secondary"
                            disabled={busyId === r.id}
                            onClick={() => decide(r.id, 'declined')}
                          >
                            Decline
                          </Button>
                        </div>
                      </Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>

            <Card title="Already decided">
              {decided.length === 0 ? (
                <Empty>No decided requests yet.</Empty>
              ) : (
                <Table columns={['Staff', 'Type', 'Dates', 'Status']}>
                  {decided.map((r) => (
                    <tr key={r.id}>
                      <Cell className="font-medium text-slate-900">{r.staff_name ?? `Staff #${r.id}`}</Cell>
                      <Cell className="capitalize">{r.leave_type}</Cell>
                      <Cell>
                        {r.start_date} → {r.end_date}
                      </Cell>
                      <Cell>
                        <Badge tone={STATUS_TONE[r.status] ?? 'slate'}>{r.status}</Badge>
                      </Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          </div>

          <Card
            title="Off today"
            description="Check this before assigning cover for a class."
            className="h-fit"
          >
            {offToday.length === 0 ? (
              <Empty>Everyone is in today.</Empty>
            ) : (
              <ul className="space-y-3">
                {offToday.map((entry, i) => (
                  <li key={i} className="rounded-lg bg-slate-50 px-3 py-2.5">
                    <p className="text-sm font-medium text-slate-900">{entry.staff_name ?? 'Staff member'}</p>
                    <p className="text-xs text-slate-500">
                      <span className="capitalize">{entry.leave_type ?? 'leave'}</span>
                      {entry.end_date ? ` · back after ${entry.end_date}` : ''}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
