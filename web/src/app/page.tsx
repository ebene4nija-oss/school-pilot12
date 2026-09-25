'use client';

import React, { useCallback, useEffect, useState } from 'react';
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
  Meter,
  PageShell,
  StatTile,
  Table,
  nairaAmount,
} from '@/components/Page';

/**
 * A school's own dashboard.
 *
 * This page used to be a static mockup: "Grace Land International College",
 * 1,248 students, ₦14.2M collected, two invented children with invented AI
 * remarks, and a sidebar of buttons that only set local state. Every figure
 * here now comes from `/analytics/principal`, and the approval queue is the
 * real one.
 *
 * It is deliberately not the super admin's landing page. A platform operator
 * has no school, so this endpoint would report them zeroes for everything —
 * they go to `/super-admin` instead (see `landingPathFor`).
 */

interface PrincipalDashboard {
  population: { active: number; total: number; by_status: Record<string, number> };
  revenue: {
    total_collected: number;
    total_invoiced: number;
    fee_recovery_rate_percentage: number;
    currency: string;
  };
  attendance_today: { present_count: number; marked_count: number; rate_percentage: number };
  cbt: {
    exams_published: number;
    attempts_sat: number;
    awaiting_marking: number;
    average_percentage: number;
  };
  pending_approvals: {
    ai_comments_awaiting_review: number;
    cbt_attempts_awaiting_marking: number;
  };
  best_performing_classes: { class_name: string; average_score: number | string }[];
  teacher_workload: { teacher_name: string; homework_assigned: number }[];
  insights?: {
    grade_drops?: unknown[];
    revenue_forecast?: unknown;
    attrition_risk?: unknown[];
  };
}

interface PendingComment {
  score_entry_id: number;
  student_name: string;
  admission_number: string | null;
  class: string | null;
  subject: string;
  term: string;
  grade: string | null;
  total_score: number;
  comment: string | null;
}

interface CurrentUser {
  name: string;
  email: string;
  user_profile?: { role?: string; school_id?: number | null } | null;
}

export default function SchoolDashboard() {
  const [data, setData] = useState<PrincipalDashboard | null>(null);
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [pending, setPending] = useState<PendingComment[]>([]);
  const [pendingTotal, setPendingTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [editing, setEditing] = useState<number | null>(null);
  const [draft, setDraft] = useState('');

  const loadQueue = useCallback(async () => {
    // The dashboard shows the first few; the count comes from `meta` so the
    // heading is honest about how many are really waiting.
    const res = await fetchApi<{ data: PendingComment[]; meta: { total: number } }>(
      '/assessment/ai-comments/pending?per_page=5',
    );
    setPending(res.data ?? []);
    setPendingTotal(res.meta?.total ?? 0);
  }, []);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const [dashboard, me] = await Promise.all([
          fetchApi<PrincipalDashboard>('/analytics/principal'),
          fetchApi<CurrentUser>('/user').catch(() => null),
        ]);

        if (cancelled) return;

        setData(dashboard);
        setUser(me);

        // A teacher without an approval queue is normal, and a failure here
        // must not blank the whole dashboard.
        await loadQueue().catch(() => undefined);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the dashboard.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [loadQueue]);

  async function review(id: number, action: 'approve' | 'edit' | 'reject', editedComment?: string) {
    setBusyId(id);
    setError(null);

    try {
      await fetchApi(`/assessment/score/${id}/review-comment`, {
        method: 'POST',
        body: JSON.stringify(
          action === 'edit' ? { action, edited_comment: editedComment } : { action },
        ),
      });

      setEditing(null);
      setDraft('');
      await loadQueue();

      // The tile counts the same queue, so it has to move with it.
      setData((prev) =>
        prev
          ? {
              ...prev,
              pending_approvals: {
                ...prev.pending_approvals,
                ai_comments_awaiting_review: Math.max(
                  prev.pending_approvals.ai_comments_awaiting_review - 1,
                  0,
                ),
              },
            }
          : prev,
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That review could not be saved.');
    } finally {
      setBusyId(null);
    }
  }

  const revenue = data?.revenue;
  const attendance = data?.attendance_today;

  return (
    <PageShell
      title="Dashboard"
      subtitle={user ? `Signed in as ${user.name}.` : 'This term at a glance.'}
      actions={
        <Link href="/results">
          <Button>Results &amp; broadsheet</Button>
        </Link>
      }
    >
      <ErrorNote message={error} />

      {loading ? (
        <Loading />
      ) : !data ? (
        <Empty>No dashboard figures yet.</Empty>
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile
              label="Active students"
              value={data.population.active.toLocaleString('en-NG')}
              hint={
                data.population.total !== data.population.active
                  ? `${data.population.total.toLocaleString('en-NG')} on the roll in total`
                  : 'On the roll now'
              }
            />
            <StatTile
              label="Fees collected"
              value={nairaAmount(revenue?.total_collected ?? 0)}
              tone="green"
              hint={
                revenue && revenue.total_invoiced > 0
                  ? `${revenue.fee_recovery_rate_percentage}% of ${nairaAmount(revenue.total_invoiced)} billed`
                  : 'Nothing billed yet'
              }
            />
            <StatTile
              label="Attendance today"
              value={
                attendance && attendance.marked_count > 0
                  ? `${attendance.rate_percentage}%`
                  : 'Not marked'
              }
              tone={attendance && attendance.marked_count > 0 ? 'blue' : 'slate'}
              hint={
                attendance && attendance.marked_count > 0
                  ? `${attendance.present_count.toLocaleString('en-NG')} present of ${attendance.marked_count.toLocaleString('en-NG')} marked`
                  : 'No register taken yet today'
              }
            />
            <StatTile
              label="Awaiting review"
              value={(
                data.pending_approvals.ai_comments_awaiting_review +
                data.pending_approvals.cbt_attempts_awaiting_marking
              ).toLocaleString('en-NG')}
              tone={
                data.pending_approvals.ai_comments_awaiting_review +
                  data.pending_approvals.cbt_attempts_awaiting_marking >
                0
                  ? 'amber'
                  : 'green'
              }
              hint={`${data.pending_approvals.ai_comments_awaiting_review} AI comment(s), ${data.pending_approvals.cbt_attempts_awaiting_marking} CBT script(s)`}
            />
          </div>

          <div className="grid gap-6 lg:grid-cols-3">
            <Card
              className="lg:col-span-2"
              title="AI comments awaiting review"
              description={
                pendingTotal > 0
                  ? `${pendingTotal} comment(s) waiting. None of these reaches a report card until it is approved or edited here.`
                  : 'Nothing waiting. AI-written remarks appear here before they can be printed.'
              }
            >
              {pending.length === 0 ? (
                <Empty>No comments are waiting for review.</Empty>
              ) : (
                <ul className="space-y-3">
                  {pending.map((item) => (
                    <li
                      key={item.score_entry_id}
                      className="rounded-lg border border-slate-200 bg-slate-50 p-4"
                    >
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                          <p className="text-sm font-semibold text-slate-900">
                            {item.student_name}
                            {item.class && (
                              <span className="font-normal text-slate-500"> · {item.class}</span>
                            )}
                          </p>
                          <p className="text-xs text-slate-500">
                            {item.subject} · {item.term}
                            {item.grade && ` · ${item.grade}`}
                          </p>
                        </div>
                        <Badge tone="amber">Pending</Badge>
                      </div>

                      {editing === item.score_entry_id ? (
                        <div className="mt-3 space-y-2">
                          <textarea
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            rows={3}
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900"
                            aria-label={`Edit the comment for ${item.student_name}`}
                          />
                          <div className="flex justify-end gap-2">
                            <Button
                              variant="secondary"
                              onClick={() => {
                                setEditing(null);
                                setDraft('');
                              }}
                            >
                              Cancel
                            </Button>
                            <Button
                              disabled={busyId === item.score_entry_id || draft.trim().length === 0}
                              onClick={() => review(item.score_entry_id, 'edit', draft.trim())}
                            >
                              Save &amp; approve
                            </Button>
                          </div>
                        </div>
                      ) : (
                        <>
                          <p className="mt-2 text-sm italic text-slate-700">
                            {item.comment ?? 'No comment text was generated.'}
                          </p>
                          <div className="mt-3 flex flex-wrap justify-end gap-2">
                            <Button
                              variant="secondary"
                              disabled={busyId === item.score_entry_id}
                              onClick={() => {
                                setEditing(item.score_entry_id);
                                setDraft(item.comment ?? '');
                              }}
                            >
                              Edit
                            </Button>
                            <Button
                              variant="danger"
                              disabled={busyId === item.score_entry_id}
                              onClick={() => review(item.score_entry_id, 'reject')}
                            >
                              Reject
                            </Button>
                            <Button
                              disabled={busyId === item.score_entry_id}
                              onClick={() => review(item.score_entry_id, 'approve')}
                            >
                              Approve
                            </Button>
                          </div>
                        </>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              {pendingTotal > pending.length && (
                <p className="mt-3 text-xs text-slate-500">
                  Showing {pending.length} of {pendingTotal}.
                </p>
              )}
            </Card>

            <Card title="CBT this term">
              <dl className="space-y-3 text-sm">
                <Row label="Exams published" value={data.cbt.exams_published} />
                <Row label="Scripts sat" value={data.cbt.attempts_sat} />
                <Row
                  label="Awaiting marking"
                  value={data.cbt.awaiting_marking}
                  tone={data.cbt.awaiting_marking > 0 ? 'amber' : 'slate'}
                />
                <Row
                  label="Average score"
                  value={data.cbt.attempts_sat > 0 ? `${data.cbt.average_percentage}%` : '—'}
                />
              </dl>
              <Link
                href="/cbt-setup"
                className="mt-4 inline-block text-sm font-medium text-blue-700 hover:underline"
              >
                Manage CBT exams →
              </Link>
            </Card>
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card
              title="Class averages"
              description="Highest mean score first, across every score entered."
            >
              {data.best_performing_classes.length === 0 ? (
                <Empty>No scores have been entered yet.</Empty>
              ) : (
                <ul className="space-y-3">
                  {data.best_performing_classes.map((row) => {
                    const average = Number(row.average_score) || 0;

                    return (
                      <li key={row.class_name} className="space-y-1">
                        <div className="flex justify-between text-sm">
                          <span className="font-medium text-slate-800">{row.class_name}</span>
                          <span className="text-slate-600">{average.toFixed(1)}%</span>
                        </div>
                        <Meter
                          value={average}
                          tone={average >= 70 ? 'green' : average >= 50 ? 'amber' : 'red'}
                        />
                      </li>
                    );
                  })}
                </ul>
              )}
            </Card>

            <Card title="Homework set, by teacher">
              {data.teacher_workload.length === 0 ? (
                <Empty>No homework has been set yet.</Empty>
              ) : (
                <Table columns={['Teacher', 'Assignments']}>
                  {data.teacher_workload.map((row) => (
                    <tr key={row.teacher_name}>
                      <Cell>{row.teacher_name}</Cell>
                      <Cell>{row.homework_assigned}</Cell>
                    </tr>
                  ))}
                </Table>
              )}
            </Card>
          </div>
        </div>
      )}
    </PageShell>
  );
}

function Row({
  label,
  value,
  tone = 'slate',
}: {
  label: string;
  value: React.ReactNode;
  tone?: 'slate' | 'amber';
}) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-slate-600">{label}</dt>
      <dd className={tone === 'amber' ? 'font-semibold text-amber-700' : 'font-semibold text-slate-900'}>
        {value}
      </dd>
    </div>
  );
}
