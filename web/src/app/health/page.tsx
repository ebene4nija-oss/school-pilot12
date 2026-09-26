'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import StudentPicker, { type PickedStudent } from '@/components/StudentPicker';
import {
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * The school clinic log.
 *
 * Was a static list of two invented children and their invented ailments. The
 * write endpoint existed; nothing could read the log back, so whatever a nurse
 * recorded was never visible again.
 *
 * Deliberately narrow (doc §12): who was seen, what was done, by whom, when. It
 * does not show — and the endpoint does not return — the stored medical record,
 * blood group or allergy list. Those are held for an emergency, not for a
 * browsable list.
 */

interface Visit {
  id: number;
  student_id: number;
  student_name: string;
  admission_number: string | null;
  symptoms: string;
  treatment: string;
  attending_nurse: string;
  visited_at: string | null;
}

export default function ClinicLogPage() {
  const [visits, setVisits] = useState<Visit[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [showForm, setShowForm] = useState(false);

  const [student, setStudent] = useState<PickedStudent | null>(null);
  // A nurse is the attending nurse on her own visits; the API fills her name in.
  // The role cookie only decides what renders — the API checks the rest.
  const [isNurse, setIsNurse] = useState(false);
  const [symptoms, setSymptoms] = useState('');
  const [treatment, setTreatment] = useState('');
  const [nurse, setNurse] = useState('');

  const load = useCallback(async () => {
    const res = await fetchApi<{ data: Visit[] }>('/health/clinic-visits');
    setVisits(res.data ?? []);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        setIsNurse(/(?:^|;\s*)sp_role=nurse(?:;|$)/.test(document.cookie));
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load the clinic log.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/health/clinic-visit', {
        method: 'POST',
        body: JSON.stringify({
          student_id: student?.id,
          symptoms: symptoms.trim(),
          treatment: treatment.trim(),
          attending_nurse: isNurse ? undefined : nurse.trim(),
        }),
      });

      setNotice('Visit recorded.');
      setSymptoms('');
      setTreatment('');
      setStudent(null);
      setShowForm(false);
      await load();
    } catch (err) {
      setError(errorText(err, 'That visit could not be recorded.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <PageShell
      title="Clinic log"
      subtitle="Visits to the school clinic: who was seen, and what was done."
      actions={
        <Button onClick={() => setShowForm((v) => !v)} variant={showForm ? 'secondary' : 'primary'}>
          {showForm ? 'Cancel' : 'Log a visit'}
        </Button>
      }
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {showForm && (
        <Card title="Log a clinic visit" className="mb-6">
          <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <Field label="Student">
              <StudentPicker value={student} onChange={setStudent} />
            </Field>

            {!isNurse && (
              <Field label="Attending nurse">
                <input
                  required
                  value={nurse}
                  onChange={(e) => setNurse(e.target.value)}
                  className={inputClass}
                  placeholder="Name of the nurse on duty"
                />
              </Field>
            )}

            <Field label="Reported symptoms">
              <textarea
                required
                rows={3}
                value={symptoms}
                onChange={(e) => setSymptoms(e.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Treatment given">
              <textarea
                required
                rows={3}
                value={treatment}
                onChange={(e) => setTreatment(e.target.value)}
                className={inputClass}
              />
            </Field>

            <div className="sm:col-span-2">
              <Button type="submit" disabled={saving || !student}>
                {saving ? 'Saving…' : 'Record visit'}
              </Button>
            </div>
          </form>
        </Card>
      )}

      <Card title="Recent visits">
        {loading ? (
          <Loading />
        ) : visits.length === 0 ? (
          <Empty>No clinic visits recorded yet.</Empty>
        ) : (
          <Table columns={['Student', 'Symptoms', 'Treatment', 'Nurse', 'When']}>
            {visits.map((visit) => (
              <tr key={visit.id}>
                <Cell className="font-medium text-brand">
                  {visit.student_name}
                  {visit.admission_number && (
                    <span className="block text-xs text-ink-muted">{visit.admission_number}</span>
                  )}
                </Cell>
                <Cell>{visit.symptoms}</Cell>
                <Cell>{visit.treatment}</Cell>
                <Cell>{visit.attending_nurse}</Cell>
                <Cell className="whitespace-nowrap text-xs">{when(visit.visited_at)}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <p className="mt-4 text-xs text-ink-muted">
        This log holds only what happened at the visit. A student&apos;s stored medical details are
        on their own record and are not listed here.
      </p>
    </PageShell>
  );
}

function when(iso: string | null): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return date.toLocaleString('en-NG', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}
