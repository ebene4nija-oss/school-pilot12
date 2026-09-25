'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * Taking the register.
 *
 * The old page was a demonstration of itself: a "Simulate Camera Scan Event"
 * button that set a string after a one-second timeout, a hardcoded campus GPS
 * position, four invented children whose status toggled in local state, and a
 * clock-in button that called `alert()`. Nothing reached the API.
 *
 * Roll call now reads the real register and saves it through
 * `/attendance/bulk`, which is atomic and idempotent — a teacher on a bad
 * connection can tap save twice without marking the class twice. QR marking
 * issues a real short-lived token.
 */

type Status = 'present' | 'absent' | 'late' | 'excused';

interface RegisterRow {
  student_id: number;
  name: string | null;
  admission_number: string | null;
  status: Status | null;
  already_marked: boolean;
}

interface ClassOption {
  id: number;
  name: string;
}

interface TermOption {
  id: number;
  name: string;
  is_current?: boolean;
}

const NEXT_STATUS: Record<Status, Status> = {
  present: 'absent',
  absent: 'late',
  late: 'excused',
  excused: 'present',
};

const STATUS_TONE: Record<Status, 'green' | 'red' | 'amber' | 'blue'> = {
  present: 'green',
  absent: 'red',
  late: 'amber',
  excused: 'blue',
};

export default function AttendancePage() {
  const [classes, setClasses] = useState<ClassOption[]>([]);
  const [terms, setTerms] = useState<TermOption[]>([]);
  const [classId, setClassId] = useState('');
  const [termId, setTermId] = useState('');
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));

  const [register, setRegister] = useState<RegisterRow[]>([]);
  const [marks, setMarks] = useState<Record<number, Status>>({});
  const [loading, setLoading] = useState(true);
  const [loadingRegister, setLoadingRegister] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [qrToken, setQrToken] = useState<string | null>(null);
  const [qrExpiresIn, setQrExpiresIn] = useState<number | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const [cls, trm] = await Promise.all([
          fetchApi<{ data?: ClassOption[] }>('/classes'),
          fetchApi<{ data?: TermOption[] }>('/terms'),
        ]);

        const classList = cls.data ?? [];
        const termList = trm.data ?? [];

        setClasses(classList);
        setTerms(termList);

        if (classList.length > 0) setClassId(String(classList[0].id));

        // Default to the term the school is actually in, not the first row.
        const current = termList.find((t) => t.is_current) ?? termList[0];
        if (current) setTermId(String(current.id));
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load classes and terms.');
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  /**
   * Fetches the register and returns it, rather than setting state itself.
   *
   * Callers own the state, which keeps this usable both from an effect and from
   * "reload after saving" without either path fighting the other over the
   * loading flag.
   */
  const fetchRegister = useCallback(async () => {
    const res = await fetchApi<{ register: RegisterRow[] }>(
      `/attendance/register?class_id=${classId}&date=${date}`,
    );

    return res.register ?? [];
  }, [classId, date]);

  /** Anyone already marked keeps their mark; the rest default to present. */
  const applyRegister = useCallback((rows: RegisterRow[]) => {
    setRegister(rows);
    setMarks(
      Object.fromEntries(rows.map((row) => [row.student_id, row.status ?? 'present'])) as Record<
        number,
        Status
      >,
    );
  }, []);

  useEffect(() => {
    if (!classId) return;

    let cancelled = false;

    (async () => {
      setLoadingRegister(true);
      setError(null);

      try {
        const rows = await fetchRegister();

        if (!cancelled) applyRegister(rows);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the register.');
          setRegister([]);
        }
      } finally {
        if (!cancelled) setLoadingRegister(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [classId, fetchRegister, applyRegister]);

  async function save() {
    if (!termId) {
      setError('Select the term this register belongs to.');
      return;
    }

    setSaving(true);
    setError(null);
    setNotice(null);

    try {
      const result = await fetchApi<{
        marked?: number;
        skipped?: unknown[];
        idempotent_replay?: boolean;
      }>('/attendance/bulk', {
        method: 'POST',
        body: JSON.stringify({
          term_id: Number(termId),
          date,
          records: register.map((row) => ({
            student_id: row.student_id,
            status: marks[row.student_id] ?? 'present',
          })),
          /*
           * The key identifies this register — this class, this date, this set
           * of marks — so a double tap is recognised as the same submission
           * rather than marking the class twice.
           */
          idempotency_key: `register-${classId}-${date}-${hash(JSON.stringify(marks))}`,
        }),
      });

      setNotice(
        result.idempotent_replay
          ? 'This register was already saved — nothing was recorded twice.'
          : `Register saved for ${register.length} student(s).`,
      );

      applyRegister(await fetchRegister());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The register could not be saved.');
    } finally {
      setSaving(false);
    }
  }

  async function issueQrToken() {
    if (!classId || !termId) {
      setError('Select a class and term first.');
      return;
    }

    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ qr_token: string; expires_in_seconds: number }>(
        '/attendance/qr-token',
        {
          method: 'POST',
          body: JSON.stringify({ class_id: Number(classId), term_id: Number(termId) }),
        },
      );

      setQrToken(res.qr_token);
      setQrExpiresIn(res.expires_in_seconds);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not issue a scanning token.');
    }
  }

  function setAll(status: Status) {
    setMarks(Object.fromEntries(register.map((r) => [r.student_id, status])) as Record<number, Status>);
  }

  const counts = register.reduce<Record<Status, number>>(
    (acc, row) => {
      const status = marks[row.student_id] ?? 'present';
      acc[status] += 1;
      return acc;
    },
    { present: 0, absent: 0, late: 0, excused: 0 },
  );

  return (
    <PageShell
      title="Attendance"
      subtitle="Take the register by roll call, or issue a token for QR marking from a phone."
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : classes.length === 0 ? (
        <Empty>No classes set up yet. Create a class before taking a register.</Empty>
      ) : (
        <div className="space-y-6">
          <Card title="Which register">
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="Class">
                <select value={classId} onChange={(e) => setClassId(e.target.value)} className={inputClass}>
                  {classes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Term">
                <select value={termId} onChange={(e) => setTermId(e.target.value)} className={inputClass}>
                  <option value="">Select a term…</option>
                  {terms.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                      {t.is_current ? ' (current)' : ''}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Date">
                <input
                  type="date"
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                  className={inputClass}
                />
              </Field>
            </div>
          </Card>

          <div className="grid gap-4 sm:grid-cols-4">
            <StatTile label="Present" value={counts.present} tone="green" />
            <StatTile label="Absent" value={counts.absent} tone="red" />
            <StatTile label="Late" value={counts.late} tone="amber" />
            <StatTile label="Excused" value={counts.excused} tone="blue" />
          </div>

          <Card
            title="Roll call"
            description="Tap a status to cycle it: present → absent → late → excused."
          >
            {loadingRegister ? (
              <Loading />
            ) : register.length === 0 ? (
              <Empty>No active students in this class.</Empty>
            ) : (
              <>
                <div className="mb-4 flex flex-wrap gap-2">
                  <Button variant="secondary" onClick={() => setAll('present')}>
                    Mark all present
                  </Button>
                  <Button variant="secondary" onClick={() => setAll('absent')}>
                    Mark all absent
                  </Button>
                </div>

                <Table columns={['Admission no.', 'Student', 'Status', 'Already marked']}>
                  {register.map((row) => {
                    const status = marks[row.student_id] ?? 'present';

                    return (
                      <tr key={row.student_id}>
                        <Cell className="font-mono text-xs">{row.admission_number ?? '—'}</Cell>
                        <Cell className="font-medium text-slate-900">{row.name ?? 'Unnamed'}</Cell>
                        <Cell>
                          <button
                            onClick={() =>
                              setMarks((prev) => ({
                                ...prev,
                                [row.student_id]: NEXT_STATUS[status],
                              }))
                            }
                            aria-label={`${row.name ?? 'Student'} is ${status}. Tap to change.`}
                            className="rounded-md"
                          >
                            <Badge tone={STATUS_TONE[status]}>{status}</Badge>
                          </button>
                        </Cell>
                        <Cell className="text-xs text-slate-500">
                          {row.already_marked ? `Saved as ${row.status}` : 'Not yet saved'}
                        </Cell>
                      </tr>
                    );
                  })}
                </Table>

                <div className="mt-4 flex items-center gap-3">
                  <Button onClick={save} disabled={saving || !termId}>
                    {saving ? 'Saving…' : 'Save register'}
                  </Button>
                  <span className="text-xs text-slate-500">
                    Saving twice is safe — the same register is never recorded twice.
                  </span>
                </div>
              </>
            )}
          </Card>

          <Card
            title="QR marking"
            description="Issue a short-lived token, then scan student ID badges with a phone camera from the mobile app. No scanner hardware (doc §3)."
          >
            <Button variant="secondary" onClick={issueQrToken} disabled={!classId || !termId}>
              Issue a scanning token
            </Button>

            {qrToken && (
              <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p className="text-xs uppercase tracking-wide text-slate-500">Token</p>
                <code className="mt-1 block break-all text-xs text-slate-800">{qrToken}</code>
                {qrExpiresIn !== null && (
                  <p className="mt-2 text-xs text-slate-500">
                    Valid for {Math.round(qrExpiresIn / 60)} minute(s), then issue another. Each scan
                    spends the token once.
                  </p>
                )}
              </div>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}

/**
 * A short stable digest of the marks.
 *
 * Only needs to differ when the register differs, so that correcting one child's
 * status is treated as a new submission rather than a replay of the last one.
 */
function hash(input: string): string {
  let h = 0;

  for (let i = 0; i < input.length; i++) {
    h = (h << 5) - h + input.charCodeAt(i);
    h |= 0;
  }

  return Math.abs(h).toString(36).padStart(8, '0');
}
