'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
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
        }),
      });

      setNotice('Virtual class scheduled.');
      setTitle('');
      setMeetingUrl('');
      setMeetingId('');
      setPasscode('');
      setScheduledFor('');
      setShowForm(false);
      await load(onlyUpcoming);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That class could not be scheduled.');
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
          <Button onClick={() => setShowForm((v) => !v)} variant={showForm ? 'secondary' : 'primary'}>
            {showForm ? 'Cancel' : 'Schedule a class'}
          </Button>
        </div>
      }
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
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

                    {session.meeting_id && (
                      <p className="mt-2 text-xs text-ink-muted">
                        Meeting ID {session.meeting_id}
                        {session.passcode && ` · passcode ${session.passcode}`}
                      </p>
                    )}
                  </div>

                  <div className="flex shrink-0 flex-col items-end gap-2">
                    {session.meeting_url ? (
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

                    {session.status === 'scheduled' && (
                      <div className="flex gap-2">
                        <Button variant="secondary" disabled={busy} onClick={() => setStatus(session, 'live')}>
                          Mark live
                        </Button>
                        <Button variant="secondary" disabled={busy} onClick={() => setStatus(session, 'cancelled')}>
                          Cancel
                        </Button>
                      </div>
                    )}

                    {session.status === 'live' && (
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
