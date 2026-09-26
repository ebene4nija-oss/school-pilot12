'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote } from '@/components/Page';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';

/**
 * Absence alerts, for the school admin.
 *
 * Off until the school switches them on, and then sent only to parents who opt
 * in, per child. Parents opt in from the app; a parent who cannot use the app
 * asks the school office, and the office records it here.
 *
 * Hidden for anyone who is not a school admin: the settings request refuses
 * them, and a teacher taking the register has no use for this card.
 */

interface Settings {
  enabled: boolean;
  sms: boolean;
  sms_configured: boolean;
  parents_opted_in: number;
  sent_today: number;
}

interface GuardianRow {
  guardian_id: number;
  name: string | null;
  relationship: string | null;
  has_phone: boolean;
  enabled: boolean;
}

export default function AbsenceAlertsCard() {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [student, setStudent] = useState<PickedStudent | null>(null);
  const [guardians, setGuardians] = useState<GuardianRow[]>([]);

  useEffect(() => {
    void (async () => {
      try {
        setSettings(await fetchApi<Settings>('/school/absence-alerts'));
      } catch {
        // Not a school admin: leave the card out.
      }
    })();
  }, []);

  const loadGuardians = useCallback(async (picked: PickedStudent | null) => {
    setStudent(picked);
    setGuardians([]);
    if (!picked) return;

    try {
      const res = await fetchApi<{ guardians: GuardianRow[] }>(`/students/${picked.id}/absence-alerts`);
      setGuardians(res.guardians);
    } catch (err) {
      setError(errorText(err, "Could not load that child's parents."));
    }
  }, []);

  async function save(patch: Partial<Pick<Settings, 'enabled' | 'sms'>>) {
    if (!settings) return;

    if (patch.sms && !window.confirm('Also send absence alerts by SMS? Your school pays for each message.')) return;

    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<Settings & { message: string }>('/school/absence-alerts', {
        method: 'PUT',
        body: JSON.stringify({ enabled: patch.enabled ?? settings.enabled, sms: patch.sms ?? settings.sms }),
      });
      setSettings(res);
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'That change could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  async function setParent(row: GuardianRow, enabled: boolean) {
    if (!student) return;

    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<{ message: string }>(
        `/students/${student.id}/guardians/${row.guardian_id}/absence-alerts`,
        { method: 'PUT', body: JSON.stringify({ enabled }) },
      );
      setGuardians((prev) => prev.map((g) => (g.guardian_id === row.guardian_id ? { ...g, enabled } : g)));
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'That change could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  if (!settings) return null;

  return (
    <Card
      title="Absence alerts to parents"
      description="Tell a parent when their child is marked absent today. Off until you switch it on, and then sent only to parents who ask for it."
      className="mt-6"
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <Badge tone={settings.enabled ? 'green' : 'slate'}>{settings.enabled ? 'On' : 'Off'}</Badge>
        <Button variant={settings.enabled ? 'secondary' : 'primary'} disabled={busy} onClick={() => save({ enabled: !settings.enabled })}>
          {settings.enabled ? 'Switch off' : 'Switch on'}
        </Button>
        {settings.enabled && (
          <span className="text-sm text-ink-muted">
            {settings.parents_opted_in} parent(s) signed up · {settings.sent_today} sent today
          </span>
        )}
      </div>

      {settings.enabled && (
        <div className="mt-5 space-y-5 border-t border-line-soft pt-5">
          <label className="flex items-start gap-3 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={settings.sms}
              disabled={busy}
              onChange={(e) => save({ sms: e.target.checked })}
            />
            <span>
              <span className="font-medium text-ink">Also send by SMS</span>
              <span className="block text-xs text-ink-muted">
                The app notification is free. SMS reaches parents without the app, and your school pays for each message.
                {settings.sms && !settings.sms_configured && (
                  <span className="block font-medium text-caution">
                    Your SMS account is not set up yet, so SMS alerts will not go out. Set it up under Notifications.
                  </span>
                )}
              </span>
            </span>
          </label>

          <div>
            <p className="text-sm font-medium text-ink">Sign up a parent who asked</p>
            <p className="mb-2 text-xs text-ink-muted">
              Parents can sign up in the app. For a parent who cannot, find their child and switch it on for them.
            </p>
            <div className="max-w-md">
              <StudentPicker value={student} onChange={(picked) => void loadGuardians(picked)} />
            </div>

            {student && (
              <ul className="mt-3 divide-y divide-line-soft rounded-lg border border-line-soft">
                {guardians.length === 0 ? (
                  <li className="px-3 py-2 text-sm text-ink-muted">No parent is linked to this child.</li>
                ) : (
                  guardians.map((g) => (
                    <li key={g.guardian_id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                      <span>
                        {g.name ?? 'Parent'}
                        {g.relationship && <span className="text-ink-muted"> · {g.relationship}</span>}
                        {!g.has_phone && <span className="block text-xs text-caution">No phone number on file</span>}
                      </span>
                      <Button variant="secondary" disabled={busy} onClick={() => setParent(g, !g.enabled)}>
                        {g.enabled ? 'Stop alerts' : 'Send alerts'}
                      </Button>
                    </li>
                  ))
                )}
              </ul>
            )}
          </div>
        </div>
      )}
    </Card>
  );
}
