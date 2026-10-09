'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Card, Empty, ErrorNote, Loading, PageShell } from '@/components/Page';
import ParentTransfers from '@/components/ParentTransfers';
import ParentHostel from '@/components/ParentHostel';
import ParentStore from '@/components/ParentStore';
import ParentCbtResults from '@/components/ParentCbtResults';
import ParentTransport from '@/components/ParentTransport';
import ParentIdCards from '@/components/ParentIdCards';
import { ParentLibrary } from '@/components/LibraryBooks';

/**
 * A parent's page on the web portal: their children, each child's recent
 * attendance, and whether they are told when that child is absent.
 *
 * Parents signed in to the web portal landed on the school's own dashboard,
 * which refuses them, and saw an error. This is where they land now.
 */

interface Child {
  id: number;
  name: string | null;
  admission_number: string | null;
  class_name: string | null;
  arm_name: string | null;
}

interface AttendanceDay {
  date: string;
  status: string;
}

interface Alerts {
  offered: boolean;
  channels: string[];
  children: { student_id: number; enabled: boolean }[];
}

const STATUS_TONE: Record<string, 'green' | 'red' | 'amber' | 'slate'> = {
  present: 'green',
  absent: 'red',
  late: 'amber',
  excused: 'slate',
};

export default function ParentHome() {
  const [children, setChildren] = useState<Child[]>([]);
  const [attendance, setAttendance] = useState<Record<number, AttendanceDay[]>>({});
  const [alerts, setAlerts] = useState<Alerts | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    const [kids, offered] = await Promise.all([
      fetchApi<{ data: Child[] }>('/parent/children'),
      fetchApi<Alerts>('/parent/absence-alerts').catch(() => null),
    ]);

    setChildren(kids.data ?? []);
    setAlerts(offered);

    const feeds = await Promise.all(
      (kids.data ?? []).map((child) =>
        fetchApi<{ recent_attendance?: AttendanceDay[] }>(`/parent/feed/${child.id}`)
          .then((feed) => [child.id, feed.recent_attendance ?? []] as const)
          .catch(() => [child.id, []] as const),
      ),
    );
    setAttendance(Object.fromEntries(feeds));
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        await load();
      } catch (err) {
        setError(errorText(err, 'Could not load your children.'));
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function setAlert(child: Child, enabled: boolean) {
    setBusyId(child.id);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ message: string }>(`/parent/children/${child.id}/absence-alerts`, {
        method: 'PUT',
        body: JSON.stringify({ enabled }),
      });
      setNotice(res.message);
      setAlerts((prev) =>
        prev
          ? { ...prev, children: prev.children.map((c) => (c.student_id === child.id ? { ...c, enabled } : c)) }
          : prev,
      );
    } catch (err) {
      setError(errorText(err, 'That change could not be saved.'));
    } finally {
      setBusyId(null);
    }
  }

  const bySms = alerts?.channels.includes('sms');

  return (
    <PageShell title="My children" subtitle="Attendance, and whether the school tells you when your child is absent.">
      <ErrorNote message={error} />
      <ParentStore />
      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : children.length === 0 ? (
        <Empty>No child is linked to your account yet. Ask the school office to link your child.</Empty>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          {children.map((child) => {
            const days = attendance[child.id] ?? [];
            const count = (status: string) => days.filter((d) => d.status === status).length;
            const alert = alerts?.children.find((c) => c.student_id === child.id);

            return (
              <Card
                key={child.id}
                title={child.name ?? 'Your child'}
                description={[child.class_name, child.arm_name].filter(Boolean).join(' ') || undefined}
              >
                <p className="text-xs text-ink-muted">Admission number {child.admission_number ?? '—'}</p>

                <h3 className="mt-4 text-sm font-semibold text-brand">Recent attendance</h3>
                {days.length === 0 ? (
                  <p className="mt-1 text-sm text-ink-muted">Attendance appears here once the register is taken.</p>
                ) : (
                  <>
                    <p className="mt-1 text-sm text-ink">
                      {count('present')} present · {count('absent')} absent · {count('late')} late
                    </p>
                    <ul className="mt-2 flex flex-wrap gap-2">
                      {days
                        .filter((d) => d.status !== 'present')
                        .slice(0, 8)
                        .map((d) => (
                          <li key={d.date}>
                            <Badge tone={STATUS_TONE[d.status] ?? 'slate'}>
                              {new Date(d.date).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' })} · {d.status}
                            </Badge>
                          </li>
                        ))}
                    </ul>
                  </>
                )}

                {alerts?.offered && alert && (
                  <label className="mt-5 flex items-start gap-3 border-t border-line-soft pt-4 text-sm">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={alert.enabled}
                      disabled={busyId === child.id}
                      onChange={(e) => setAlert(child, e.target.checked)}
                    />
                    <span>
                      <span className="font-medium text-ink">Tell me if {child.name?.split(' ')[0] ?? 'my child'} is absent</span>
                      <span className="block text-xs text-ink-muted">
                        {bySms
                          ? 'A text message and an app notification, the day it happens.'
                          : 'A notification in the SchoolPilot app, the day it happens.'}
                      </span>
                    </span>
                  </label>
                )}
              </Card>
            );
          })}
        </div>
      )}
      <ParentCbtResults kids={children} />
      <ParentTransport kids={children} />
      <ParentHostel />
      <ParentIdCards />
      <ParentLibrary kids={children} />
      <ParentTransfers />
    </PageShell>
  );
}
