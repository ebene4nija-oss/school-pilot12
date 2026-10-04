'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { roleFromCookie } from '@/components/Sidebar';
import ProviderConnections from './ProviderConnections';
import {
  Badge,
  Button,
  Card,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  Table,
  Cell,
  inputClass,
} from '@/components/Page';

/**
 * Virtual classrooms.
 *
 * Was a single invented Zoom session — "JSS 1 Mathematics, Mr Chidi, today at
 * 02:00 PM" — behind a button that launched nothing. There was no table, no
 * model and no endpoint; the feature existed only as this page.
 *
 * SchoolPilot does not host video. The teacher creates the room in whatever the
 * school already uses and the link is stored against a class and subject, so the
 * right students see it and nobody else does. Students are given the joining
 * link only once the session opens — a link readable a week early is a link that
 * leaves the school.
 */

interface Session {
  id: number;
  title: string;
  description: string | null;
  provider: 'zoom' | 'google_meet' | 'other';
  class: string | null;
  arm: string | null;
  subject: string | null;
  teacher: string | null;
  scheduled_for: string | null;
  ends_at: string | null;
  duration_minutes: number;
  status: 'scheduled' | 'live' | 'ended' | 'cancelled';
  is_joinable: boolean;
  meeting_url: string | null;
  meeting_id: string | null;
  passcode: string | null;
  objectives: string | null;
  series_id: string | null;
  recording_enabled: boolean;
  recording_notice: string | null;
  cancel_reason: string | null;
}

interface AttendanceRow {
  student_id: number;
  name: string | null;
  minutes_connected: number;
  minutes_in_call?: number | null;
  joins: number;
  rejoined: boolean;
  status: 'present' | 'late' | 'partial' | 'absent';
  corrected: boolean;
  correction_reason: string | null;
}

const ATTENDANCE_TONE = { present: 'green', late: 'amber', partial: 'amber', absent: 'red' } as const;

interface ProviderInfo {
  source: 'provider' | 'page';
  provider_sync: { status: string | null; synced_at: string | null; error: string | null; can_sync: boolean };
  unmatched: { participant_key: string; name: string | null; joined_at: string; left_at: string }[];
}

interface ClassOption {
  id: number;
  name: string;
  arms?: { id: number; name: string }[];
}

interface SubjectOption {
  id: number;
  name: string;
}

const PROVIDER_LABEL = {
  zoom: 'Zoom',
  google_meet: 'Google Meet',
  other: 'Other',
} as const;

const STATUS_TONE = {
  scheduled: 'blue',
  live: 'green',
  ended: 'slate',
  cancelled: 'red',
} as const;

export default function LiveClassPage() {
  const [sessions, setSessions] = useState<Session[]>([]);
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [subjects, setSubjects] = useState<SubjectOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [onlyUpcoming, setOnlyUpcoming] = useState(true);

  const [title, setTitle] = useState('');
  const [classId, setClassId] = useState('');
  const [armId, setArmId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [provider, setProvider] = useState<Session['provider']>('zoom');
  const [meetingUrl, setMeetingUrl] = useState('');
  const [meetingId, setMeetingId] = useState('');
  const [passcode, setPasscode] = useState('');
  const [scheduledFor, setScheduledFor] = useState('');
  const [duration, setDuration] = useState('45');
  const [objectives, setObjectives] = useState('');
  const [repeatUntil, setRepeatUntil] = useState('');
  const [role] = useState(roleFromCookie);
  const staff = role === 'school_admin' || role === 'teacher' || role === 'super_admin';
  const [attendance, setAttendance] = useState<({ id: number; title: string; rows: AttendanceRow[]; rules: { present_percent: number; late_grace_minutes: number } } & ProviderInfo) | null>(null);
  const [matchTo, setMatchTo] = useState<Record<string, string>>({});
  const [inClass, setInClass] = useState<{ id: number; notice: string | null } | null>(null);

  /*
   * Attendance is time in the lesson (TASK 14 §3.3): while the lesson page is
   * open the browser pings once a minute, and closing it says so. Joining
   * alone is not attendance.
   */
  useEffect(() => {
    if (!inClass) return;
    const ping = (leaving = false) =>
      fetchApi(`/live-classes/${inClass.id}/heartbeat`, { method: 'POST', body: JSON.stringify({ leaving }) }).catch(() => undefined);
    const timer = window.setInterval(() => ping(), 60_000);
    const leave = () => {
      navigator.sendBeacon?.(`/api/backend/live-classes/${inClass.id}/heartbeat`, new Blob([JSON.stringify({ leaving: true })], { type: 'application/json' }));
    };
    window.addEventListener('pagehide', leave);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('pagehide', leave);
      ping(true);
    };
  }, [inClass]);

  async function join(session: Session) {
    setError(null);
    // Open the tab inside the click, so pop-up blockers allow it.
    const tab = window.open('', '_blank');
    try {
      const res = await fetchApi<{ meeting_url: string; recording_notice: string | null }>(`/live-classes/${session.id}/join`, { method: 'POST' });
      if (tab) tab.location.href = res.meeting_url;
      else window.location.assign(res.meeting_url);
      setInClass({ id: session.id, notice: res.recording_notice });
    } catch (err) {
      tab?.close();
      setError(errorText(err, 'Could not join the class.'));
    }
  }

  async function openAttendance(id: number, title: string) {
    try {
      const res = await fetchApi<{ students: AttendanceRow[]; rules: { present_percent: number; late_grace_minutes: number } } & ProviderInfo>(`/live-classes/${id}/attendance`);
      setAttendance({ id, title, rows: res.students, rules: res.rules, source: res.source, provider_sync: res.provider_sync, unmatched: res.unmatched ?? [] });
    } catch (err) {
      setError(errorText(err, 'Could not load attendance.'));
    }
  }

  /** Read who was in the Zoom / Meet call, from the provider's report. */
  async function readFromProvider() {
    if (!attendance) return;
    try {
      const res = await fetchApi<{ message: string }>(`/live-classes/${attendance.id}/provider-attendance`, { method: 'POST' });
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'Could not read attendance from the provider.'));
    }
    await openAttendance(attendance.id, attendance.title);
  }

  async function matchParticipant(key: string) {
    if (!attendance || !matchTo[key]) return;
    try {
      await fetchApi(`/live-classes/${attendance.id}/provider-attendance/match`, { method: 'POST', body: JSON.stringify({ participant_key: key, student_id: Number(matchTo[key]), remember: true }) });
      await openAttendance(attendance.id, attendance.title);
    } catch (err) {
      setError(errorText(err, 'Could not match that participant.'));
    }
  }

  async function correct(row: AttendanceRow, status: AttendanceRow['status']) {
    if (!attendance) return;
    const reason = window.prompt(`Why change ${row.name ?? 'this student'} to ${status}? (kept on the record)`);
    if (!reason) return;
    try {
      await fetchApi(`/live-classes/${attendance.id}/attendance/${row.student_id}`, { method: 'PUT', body: JSON.stringify({ status, reason }) });
      await openAttendance(attendance.id, attendance.title);
    } catch (err) {
      setError(errorText(err, 'Could not correct attendance.'));
    }
  }

  async function cancel(session: Session) {
    const reason = window.prompt('Reason for cancelling (students and parents will see it)?');
    if (!reason) return;
    const wholeSeries = session.series_id ? window.confirm('Cancel the rest of this weekly series too?') : false;
    setBusy(true);
    try {
      const res = await fetchApi<{ message: string }>(`/live-classes/${session.id}/cancel`, { method: 'POST', body: JSON.stringify({ reason, whole_series: wholeSeries }) });
      setNotice(res.message);
      await load(onlyUpcoming);
    } catch (err) {
      setError(errorText(err, 'Could not cancel.'));
    } finally {
      setBusy(false);
    }
  }

  const load = useCallback(async (upcoming: boolean) => {
    const res = await fetchApi<{ data: Session[] }>(
      `/live-classes${upcoming ? '?upcoming=1' : ''}`,
    );
    setSessions(res.data ?? []);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        await load(onlyUpcoming);

        // Pickers are optional: a student cannot create a session and does not
        // need them, so a 403 here is not an error worth showing.
        const [cls, subj] = await Promise.all([
          fetchApi<{ data?: ClassOption[] }>('/classes').catch(() => null),
          fetchApi<{ data?: SubjectOption[] }>('/subjects').catch(() => null),
        ]);

        setClasses(cls?.data ?? []);
        setSubjects(subj?.data ?? []);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load virtual classes.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load, onlyUpcoming]);

  async function schedule(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/live-classes', {
        method: 'POST',
        body: JSON.stringify({
          title: title.trim(),
          class_id: classId ? Number(classId) : null,
          arm_id: armId ? Number(armId) : null,
          subject_id: subjectId ? Number(subjectId) : null,
          provider,
          meeting_url: meetingUrl.trim(),
          meeting_id: meetingId.trim() || null,
          passcode: passcode.trim() || null,
          // A datetime-local value has no zone; the browser's own offset is the
          // right one, since the teacher typed the time they mean locally.
          scheduled_for: new Date(scheduledFor).toISOString(),
          duration_minutes: Number(duration),
          objectives: objectives.trim() || null,
          repeat_weekly_until: repeatUntil || null,
        }),
      });

      setNotice(repeatUntil ? 'Weekly classes scheduled. Students and parents have been told.' : 'Virtual class scheduled. Students and parents have been told.');
      setObjectives('');
      setRepeatUntil('');
      setTitle('');
      setMeetingUrl('');
      setMeetingId('');
      setPasscode('');
      setScheduledFor('');
      setShowForm(false);
      await load(onlyUpcoming);
    } catch (err) {
      setError(errorText(err, 'That class could not be scheduled.'));
    } finally {
      setBusy(false);
    }
  }

  async function setStatus(session: Session, status: Session['status']) {
    setBusy(true);
    setError(null);

    try {
      await fetchApi(`/live-classes/${session.id}`, {
        method: 'PUT',
        body: JSON.stringify({ status }),
      });

      await load(onlyUpcoming);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That change could not be saved.');
    } finally {
      setBusy(false);
    }
  }

  const arms = classes.find((c) => String(c.id) === classId)?.arms ?? [];

  return (
    <PageShell
      title="Virtual classes"
      subtitle="Scheduled online lessons and their joining details."
      actions={
        <div className="flex gap-2">
          <Button
            variant="secondary"
            onClick={() => {
              const next = !onlyUpcoming;
              setOnlyUpcoming(next);
              load(next).catch(() => undefined);
            }}
          >
            {onlyUpcoming ? 'Show all' : 'Upcoming only'}
          </Button>
          {staff && (
            <Button onClick={() => setShowForm((v) => !v)} variant={showForm ? 'secondary' : 'primary'}>
              {showForm ? 'Cancel' : 'Schedule a class'}
            </Button>
          )}
        </div>
      }
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {inClass && (
        <p role="status" className="mb-6 rounded-lg bg-info-soft px-4 py-3 text-sm text-info">
          You are in class. Keep this page open while the lesson runs, and stay in the call under your own name: your attendance is the time you are there.
          {inClass.notice && <span className="mt-1 block font-medium">Recording: {inClass.notice}</span>}
          <button type="button" className="ml-2 underline" onClick={() => setInClass(null)}>
            I have left the class
          </button>
        </p>
      )}

      {attendance && (
        <Card
          title={`Attendance · ${attendance.title}`}
          description={`Present: at least ${attendance.rules.present_percent}% of the lesson. Late: joined more than ${attendance.rules.late_grace_minutes} minutes after the start. ${attendance.source === 'provider' ? 'Time is from the video provider’s own report of who was in the call.' : 'Time is counted from SchoolPilot’s lesson page until the provider’s report is read.'} Correct anyone whose connection failed.`}
          className="mb-6"
        >
          {attendance.provider_sync.can_sync && (
            <div className="mb-4 flex flex-wrap items-center gap-3 text-sm">
              <Button variant="secondary" onClick={readFromProvider}>{attendance.source === 'provider' ? 'Read the report again' : 'Read from Zoom / Meet'}</Button>
              {attendance.provider_sync.synced_at && <span className="text-ink-muted">Read {formatWhen(attendance.provider_sync.synced_at)}</span>}
              {attendance.provider_sync.status === 'waiting' && <Badge tone="amber">report not ready yet, will retry</Badge>}
              {attendance.provider_sync.error && attendance.provider_sync.status !== 'synced' && <span className="text-xs text-negative">{attendance.provider_sync.error}</span>}
            </div>
          )}
          {attendance.unmatched.length > 0 && (
            <div className="mb-4 rounded-lg border border-line-soft p-3">
              <p className="mb-2 text-sm font-semibold">In the call, not matched to a student</p>
              {attendance.unmatched.map((u) => (
                <div key={u.participant_key} className="mb-2 flex flex-wrap items-center gap-2 text-sm">
                  <span className="min-w-[10rem]">{u.name ?? 'Unknown'}</span>
                  <select className={`${inputClass} max-w-xs`} value={matchTo[u.participant_key] ?? ''} onChange={(e) => setMatchTo({ ...matchTo, [u.participant_key]: e.target.value })} aria-label={`Who is ${u.name ?? 'this'}`}>
                    <option value="">Who is this?</option>
                    {attendance.rows.map((r) => <option key={r.student_id} value={r.student_id}>{r.name}</option>)}
                  </select>
                  <Button variant="secondary" disabled={!matchTo[u.participant_key]} onClick={() => matchParticipant(u.participant_key)}>Match</Button>
                </div>
              ))}
              <p className="text-xs text-ink-muted">The match is remembered for this class, so the same name is recognised next time.</p>
            </div>
          )}
          <Table columns={['Student', 'Time', 'Status', 'Correct']}>
            {attendance.rows.map((row) => (
              <tr key={row.student_id}>
                <Cell>{row.name}</Cell>
                <Cell>
                  {attendance.source === 'provider' ? `${row.minutes_in_call ?? 0} min in call` : `${row.minutes_connected} min${row.rejoined ? ` · rejoined ${row.joins - 1}×` : ''}`}
                </Cell>
                <Cell>
                  <Badge tone={ATTENDANCE_TONE[row.status]}>{row.status}</Badge>
                  {row.corrected && (
                    <span className="ml-1 text-xs text-ink-muted" title={row.correction_reason ?? ''}>
                      corrected
                    </span>
                  )}
                </Cell>
                <Cell>
                  <select
                    className={inputClass}
                    value=""
                    onChange={(e) => e.target.value && correct(row, e.target.value as AttendanceRow['status'])}
                    aria-label={`Correct ${row.name ?? 'student'}`}
                  >
                    <option value="">Change…</option>
                    {(['present', 'late', 'partial', 'absent'] as const)
                      .filter((st) => st !== row.status)
                      .map((st) => (
                        <option key={st} value={st}>
                          {st}
                        </option>
                      ))}
                  </select>
                </Cell>
              </tr>
            ))}
          </Table>
          <Button className="mt-4" variant="secondary" onClick={() => setAttendance(null)}>
            Close
          </Button>
        </Card>
      )}

      {showForm && (
        <Card
          title="Schedule a virtual class"
          description="Create the room in Zoom or Google Meet first, then paste its link here."
          className="mb-6"
        >
          <form onSubmit={schedule} className="grid gap-4 sm:grid-cols-2">
            <Field label="Lesson title">
              <input
                required
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                className={inputClass}
                placeholder="e.g. Algebra & linear equations"
              />
            </Field>

            <Field label="Platform">
              <select
                value={provider}
                onChange={(e) => setProvider(e.target.value as Session['provider'])}
                className={inputClass}
              >
                <option value="zoom">Zoom</option>
                <option value="google_meet">Google Meet</option>
                <option value="other">Other</option>
              </select>
            </Field>

            <Field label="Class">
              <select
                value={classId}
                onChange={(e) => {
                  setClassId(e.target.value);
                  setArmId('');
                }}
                className={inputClass}
              >
                <option value="">Any class</option>
                {classes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Arm (optional)">
              <select
                value={armId}
                onChange={(e) => setArmId(e.target.value)}
                className={inputClass}
                disabled={arms.length === 0}
              >
                <option value="">Whole class</option>
                {arms.map((arm) => (
                  <option key={arm.id} value={arm.id}>
                    {arm.name}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Subject (optional)">
              <select
                value={subjectId}
                onChange={(e) => setSubjectId(e.target.value)}
                className={inputClass}
              >
                <option value="">No subject</option>
                {subjects.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Joining link">
              <input
                required
                type="url"
                value={meetingUrl}
                onChange={(e) => setMeetingUrl(e.target.value)}
                className={inputClass}
                placeholder="https://zoom.us/j/…"
              />
            </Field>

            <Field label="Meeting ID (optional)">
              <input value={meetingId} onChange={(e) => setMeetingId(e.target.value)} className={inputClass} />
            </Field>

            <Field label="Passcode (optional)">
              <input value={passcode} onChange={(e) => setPasscode(e.target.value)} className={inputClass} />
            </Field>

            <Field label="Starts">
              <input
                required
                type="datetime-local"
                value={scheduledFor}
                onChange={(e) => setScheduledFor(e.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Length (minutes)">
              <input
                required
                type="number"
                min={5}
                max={480}
                value={duration}
                onChange={(e) => setDuration(e.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Repeat weekly until (optional)">
              <input type="date" value={repeatUntil} onChange={(e) => setRepeatUntil(e.target.value)} className={inputClass} />
            </Field>

            <div className="sm:col-span-2">
              <Field label="Learning objectives (optional)">
                <textarea rows={2} value={objectives} onChange={(e) => setObjectives(e.target.value)} className={inputClass} />
              </Field>
            </div>

            <div className="sm:col-span-2">
              <Button type="submit" disabled={busy || !scheduledFor}>
                {busy ? 'Saving…' : 'Schedule class'}
              </Button>
            </div>
          </form>
        </Card>
      )}

      {loading ? (
        <Loading />
      ) : sessions.length === 0 ? (
        <Empty>
          {onlyUpcoming
            ? 'No upcoming virtual classes. Schedule one, or show all to see past sessions.'
            : 'No virtual classes yet.'}
        </Empty>
      ) : (
        <ul className="space-y-4">
          {sessions.map((session) => (
            <li key={session.id}>
              <Card>
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone={STATUS_TONE[session.status]}>{session.status}</Badge>
                      <span className="text-xs text-ink-muted">
                        {PROVIDER_LABEL[session.provider]}
                      </span>
                      {[session.class, session.arm, session.subject].filter(Boolean).length > 0 && (
                        <span className="text-xs text-ink-muted">
                          · {[session.class, session.arm, session.subject].filter(Boolean).join(' · ')}
                        </span>
                      )}
                    </div>

                    <h3 className="mt-1 text-base font-semibold text-brand">{session.title}</h3>

                    <p className="text-sm text-ink-muted">
                      {session.teacher ? `${session.teacher} · ` : ''}
                      {formatWhen(session.scheduled_for)} · {session.duration_minutes} min
                    </p>

                    {session.description && (
                      <p className="mt-2 text-sm text-ink-muted">{session.description}</p>
                    )}

                    {session.objectives && (
                      <p className="mt-2 text-sm text-ink">
                        <span className="font-medium">Objectives:</span> {session.objectives}
                      </p>
                    )}

                    {session.cancel_reason && <p className="mt-2 text-sm text-negative">Cancelled: {session.cancel_reason}</p>}

                    {session.recording_enabled && (
                      <p className="mt-2 text-xs text-caution">This lesson is recorded. {session.recording_notice}</p>
                    )}

                    {session.meeting_id && (
                      <p className="mt-2 text-xs text-ink-muted">
                        Meeting ID {session.meeting_id}
                        {session.passcode && ` · passcode ${session.passcode}`}
                      </p>
                    )}
                  </div>

                  <div className="flex shrink-0 flex-col items-end gap-2">
                    {!staff && session.is_joinable ? (
                      <Button onClick={() => join(session)}>Join</Button>
                    ) : session.meeting_url ? (
                      <a
                        href={session.meeting_url}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-lg bg-brand px-3.5 py-2 text-sm font-medium text-white transition hover:bg-brand-soft"
                      >
                        Join
                      </a>
                    ) : (
                      <span className="text-xs text-ink-muted">
                        The link appears shortly before the lesson starts.
                      </span>
                    )}

                    {staff && session.status === 'scheduled' && (
                      <div className="flex gap-2">
                        <Button variant="secondary" disabled={busy} onClick={() => setStatus(session, 'live')}>
                          Mark live
                        </Button>
                        <Button variant="secondary" disabled={busy} onClick={() => cancel(session)}>
                          Cancel
                        </Button>
                      </div>
                    )}

                    {staff && session.class && session.status !== 'cancelled' && (
                      <Button variant="secondary" onClick={() => openAttendance(session.id, session.title)}>
                        Attendance
                      </Button>
                    )}

                    {staff && session.status === 'live' && (
                      <Button variant="secondary" disabled={busy} onClick={() => setStatus(session, 'ended')}>
                        End session
                      </Button>
                    )}
                  </div>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {role === 'school_admin' && <ProviderConnections />}
    </PageShell>
  );
}

function formatWhen(iso: string | null): string {
  if (!iso) return 'Time not set';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return 'Time not set';

  return date.toLocaleString('en-NG', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}
